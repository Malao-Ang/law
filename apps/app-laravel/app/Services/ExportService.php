<?php

namespace App\Services;

class ExportService
{
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
            'export_path' => 'storage/app/poc/'.$relativePath,
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array{document_title: string|null, chunks: array<int, array<string, mixed>>}
     */
    private function buildChunksFromBlocks(string $documentId, array $document): array
    {
        $chunks = [];
        $counter = 1;
        $title = null;

        foreach (($document['pages'] ?? []) as $page) {
            $pageNo = (int) ($page['page_no'] ?? 1);

            foreach (($page['blocks'] ?? []) as $block) {
                $type = (string) ($block['type'] ?? 'unknown');
                $blockId = (string) ($block['block_id'] ?? '');
                $text = trim((string) ($block['approved_text'] ?? $block['ai_suggested_text'] ?? $block['normalized_text'] ?? $block['raw_text'] ?? ''));

                if ($text === '' || $blockId === '') {
                    continue;
                }

                if ($title === null && $type === 'title') {
                    $title = $text;
                }

                $chunks[] = [
                    'chunk_id' => sprintf('%s_chunk_%04d', $documentId, $counter++),
                    'page_no' => $pageNo,
                    'block_ids' => [$blockId],
                    'section_path' => $block['meta']['section_path'] ?? null,
                    'text' => $text,
                    'meta' => [
                        'type' => $type,
                        'html' => $block['meta']['reviewed_html'] ?? null,
                        'layout' => $block['meta']['layout'] ?? [
                            'bbox' => $block['bbox'] ?? null,
                            'reading_order' => $block['reading_order'] ?? null,
                        ],
                        'table' => $block['meta']['table'] ?? null,
                    ],
                ];
            }
        }

        return [
            'document_title' => $title,
            'chunks' => $chunks,
        ];
    }
}
