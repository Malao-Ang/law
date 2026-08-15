<?php

namespace App\Services;

class ExportService
{
    // Target token budget per chunk.  Thai chars ≈ 1.5 tokens, ASCII ≈ 0.75.
    private const CHUNK_TOKEN_LIMIT = 512;

    // Block types that are never merged with adjacent blocks.
    private const SOLO_TYPES = ['table', 'image'];

    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly DocumentHtmlService $documentHtmlService,
    ) {}

    /**
     * @return array{document_id: string, status: string, export_path: string}
     */
    public function export(string $documentId): array
    {
        $document = $this->reviewStore->getReviewDocument($documentId);
        $documentReview = is_array($document['document_review'] ?? null) ? $document['document_review'] : [];
        $draftHtml = trim((string) ($documentReview['draft_html'] ?? $documentReview['generated_html'] ?? ''));

        $documentTitle = null;
        $chunks = [];

        if ($draftHtml !== '') {
            $parsed = $this->documentHtmlService->buildChunksFromHtml($documentId, $draftHtml);
            $documentTitle = $parsed['document_title'];
            $chunks = $parsed['chunks'];
        }

        if ($chunks === []) {
            $fallback = $this->buildChunksFromBlocks($documentId, $document);
            $documentTitle = $fallback['document_title'];
            $chunks = $fallback['chunks'];
        }

        $exportData = [
            'document_id' => $documentId,
            'document_title' => $documentTitle,
            'document_html' => $draftHtml !== '' ? $draftHtml : null,
            'document_html_mode' => $documentReview['html_mode'] ?? 'generated',
            'chunks' => $chunks,
        ];

        $relativePath = $this->reviewStore->writeExport($documentId, $exportData);

        return [
            'document_id' => $documentId,
            'status' => 'exported',
            'export_path' => $this->reviewStore->displayPath($relativePath),
        ];
    }

    /**
     * Build RAG chunks from block data using a sliding-window grouper.
     *
     * Rules:
     * - Group consecutive paragraph/list/header blocks until the combined token
     *   estimate reaches CHUNK_TOKEN_LIMIT.
     * - table and image blocks are always emitted as their own solo chunk.
     * - A section_header block flushes the current window and starts a new one.
     * - Each chunk carries a chunk_context field with the nearest preceding
     *   section_header text so retrieval has structural context.
     *
     * @param  array<string, mixed>  $document
     * @return array{document_title: string|null, chunks: array<int, array<string, mixed>>}
     */
    private function buildChunksFromBlocks(string $documentId, array $document): array
    {
        $chunks = [];
        $counter = 1;
        $title = null;
        $sectionContext = null;

        // Accumulator for the current sliding window
        $windowBlocks = [];
        $windowTokens = 0;

        $flush = function () use (&$chunks, &$counter, &$windowBlocks, &$windowTokens, $documentId, &$sectionContext): void {
            if ($windowBlocks === []) {
                return;
            }

            $chunks[] = $this->buildWindowChunk($documentId, $counter++, $windowBlocks, $sectionContext);
            $windowBlocks = [];
            $windowTokens = 0;
        };

        foreach (($document['pages'] ?? []) as $page) {
            $pageNo = (int) ($page['page_no'] ?? 1);

            foreach (($page['blocks'] ?? []) as $block) {
                $type = (string) ($block['type'] ?? 'unknown');
                $blockId = (string) ($block['block_id'] ?? '');
                $text = trim((string) ($block['approved_text'] ?? $block['ai_suggested_text'] ?? $block['normalized_text'] ?? $block['raw_text'] ?? ''));

                if ($blockId === '' || ($text === '' && $type !== 'image')) {
                    continue;
                }

                if ($title === null && $type === 'title') {
                    $title = $text;
                }

                // Solo types are never merged — flush current window first, emit solo, continue.
                if (in_array($type, self::SOLO_TYPES, true)) {
                    ($flush)();
                    $chunks[] = $this->buildSoloChunk($documentId, $counter++, $block, $pageNo, $sectionContext);

                    continue;
                }

                // A section_header flushes the current window, updates context, then starts fresh.
                if ($type === 'section_header') {
                    ($flush)();
                    $sectionContext = $text;
                }

                $tokens = $this->estimateTokens($text);

                // If adding this block would overflow the window, flush first.
                if ($windowBlocks !== [] && $windowTokens + $tokens > self::CHUNK_TOKEN_LIMIT) {
                    ($flush)();
                }

                $windowBlocks[] = ['block' => $block, 'page_no' => $pageNo];
                $windowTokens += $tokens;
            }
        }

        ($flush)();

        return [
            'document_title' => $title,
            'chunks' => $chunks,
        ];
    }

    /**
     * Build a chunk from a window of one or more consecutive blocks.
     *
     * @param  array<int, array{block: array<string, mixed>, page_no: int}>  $windowBlocks
     * @return array<string, mixed>
     */
    private function buildWindowChunk(string $documentId, int $counter, array $windowBlocks, ?string $sectionContext): array
    {
        $texts = [];
        $blockIds = [];
        $pageNo = null;
        $htmlParts = [];
        $firstBlock = $windowBlocks[0]['block'];

        foreach ($windowBlocks as $entry) {
            $block = $entry['block'];
            $pageNo ??= $entry['page_no'];
            $blockIds[] = (string) ($block['block_id'] ?? '');
            $text = trim((string) ($block['approved_text'] ?? $block['ai_suggested_text'] ?? $block['normalized_text'] ?? $block['raw_text'] ?? ''));
            if ($text !== '') {
                $texts[] = $text;
            }
            $html = $block['meta']['reviewed_html'] ?? null;
            if ($html !== null) {
                $htmlParts[] = (string) $html;
            }
        }

        return [
            'chunk_id' => sprintf('%s_chunk_%04d', $documentId, $counter),
            'page_no' => $pageNo,
            'block_ids' => array_filter($blockIds),
            'section_path' => $firstBlock['meta']['section_path'] ?? null,
            'chunk_context' => $sectionContext,
            'text' => implode("\n", $texts),
            'meta' => [
                'type' => (string) ($firstBlock['type'] ?? 'paragraph'),
                'html' => $htmlParts !== [] ? implode('', $htmlParts) : null,
                'layout' => $firstBlock['meta']['layout'] ?? ['bbox' => $firstBlock['bbox'] ?? null, 'reading_order' => $firstBlock['reading_order'] ?? null],
                'table' => null,
            ],
        ];
    }

    /**
     * Build a single-block chunk for table and image blocks.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function buildSoloChunk(string $documentId, int $counter, array $block, int $pageNo, ?string $sectionContext): array
    {
        $type = (string) ($block['type'] ?? 'unknown');
        $text = trim((string) ($block['approved_text'] ?? $block['ai_suggested_text'] ?? $block['normalized_text'] ?? $block['raw_text'] ?? ''));

        return [
            'chunk_id' => sprintf('%s_chunk_%04d', $documentId, $counter),
            'page_no' => $pageNo,
            'block_ids' => [(string) ($block['block_id'] ?? '')],
            'section_path' => $block['meta']['section_path'] ?? null,
            'chunk_context' => $sectionContext,
            'text' => $text,
            'meta' => [
                'type' => $type,
                'html' => $block['meta']['reviewed_html'] ?? null,
                'layout' => $block['meta']['layout'] ?? ['bbox' => $block['bbox'] ?? null, 'reading_order' => $block['reading_order'] ?? null],
                'table' => $block['meta']['table'] ?? null,
            ],
        ];
    }

    /**
     * Estimate token count for a string.
     * Thai characters ≈ 1.5 tokens; ASCII alphabetic ≈ 0.75 tokens per character.
     * Spaces are ignored.
     */
    private function estimateTokens(string $text): int
    {
        $tokens = 0.0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chars as $char) {
            $codepoint = mb_ord($char, 'UTF-8');
            if ($codepoint >= 0x0E00 && $codepoint <= 0x0E7F) {
                $tokens += 1.5;
            } elseif (ctype_alpha($char)) {
                $tokens += 0.75;
            } elseif (! ctype_space($char)) {
                $tokens += 0.5;
            }
        }

        return (int) ceil($tokens);
    }
}
