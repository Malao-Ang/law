<?php

namespace App\Services;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ReviewStore
{
    private string $basePath;

    public function __construct(
        private readonly DocumentHtmlService $documentHtmlService,
        private readonly MongoBlobStore $blob,
        ?string $basePath = null,
    ) {
        $this->basePath = $basePath ?? storage_path('app/poc');
        $this->ensureDirectories();
    }

    public function generateDocumentId(): string
    {
        return sprintf('doc_%s_%s', now()->format('Ymd_His'), substr(bin2hex(random_bytes(3)), 0, 6));
    }

    /**
     * @return array{relative_path: string, absolute_path: string, source_file: string}
     */
    public function storeUpload(UploadedFile $file, string $documentId): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $filename = sprintf('%s.%s', $documentId, $ext);
        $relativePath = 'uploads/'.$filename;
        $absolutePath = $this->absolutePath($relativePath);

        $file->move(dirname($absolutePath), basename($absolutePath));

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'source_file' => $file->getClientOriginalName(),
        ];
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->basePath.'/'.str_replace('\\', '/', ltrim($relativePath, '/'));
    }

    public function absoluteUploadPath(string $relativePath): string
    {
        return $this->absolutePath($relativePath);
    }

    public function absoluteImagesDir(string $documentId): string
    {
        return $this->basePath.'/images/'.basename($documentId);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public function setStatus(string $documentId, array $status): void
    {
        $this->blob->withLock('status', $documentId, function (array &$current) use ($documentId, $status): void {
            if ($current === []) {
                $current = ['document_id' => $documentId];
            }
            foreach ($status as $k => $v) {
                $current[$k] = $v;
            }
            $current['updated_at'] = now()->toIso8601String();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStatus(string $documentId): ?array
    {
        return $this->blob->read('status', $documentId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDocuments(): array
    {
        $documents = [];
        foreach ($this->blob->allStatuses() as $status) {
            $documentId = (string) ($status['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }
            $title = (string) ($status['source_file'] ?? $documentId);
            $parentDocumentId = null;
            $accessScope = 'public';

            $review = $this->blob->read('review', $documentId);
            if ($review !== null) {
                $meta = is_array($review['law_meta'] ?? null) ? $review['law_meta'] : [];
                $metaTitle = trim((string) ($meta['title'] ?? ''));
                if ($metaTitle !== '') {
                    $title = $metaTitle;
                }
                $parentRaw = trim((string) ($meta['parent_document_id'] ?? ''));
                if ($parentRaw !== '') {
                    $parentDocumentId = $parentRaw;
                }
                $accessScope = ($meta['access_scope'] ?? 'public') === 'private' ? 'private' : 'public';
            }

            $documents[] = [
                'document_id' => $documentId,
                'title' => $title,
                'status' => (string) ($status['status'] ?? 'unknown'),
                'updated_at' => $status['updated_at'] ?? null,
                'parent_document_id' => $parentDocumentId,
                'access_scope' => $accessScope,
                'workflow_completed_step' => isset($status['workflow_completed_step']) ? (int) $status['workflow_completed_step'] : null,
                'workflow_current_step' => isset($status['workflow_current_step']) ? (int) $status['workflow_current_step'] : null,
                'workflow_updated_at' => $status['workflow_updated_at'] ?? null,
            ];
        }

        usort($documents, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

        return $documents;
    }

    /**
     * Raw per-document law_meta rows for reporting. Reads every status file
     * and merges the intermediate review's law_meta. Missing meta → empty defaults.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLawMeta(): array
    {
        return Cache::remember('law-meta-list', 180, function (): array {
            $rows = [];
            foreach ($this->blob->allStatuses() as $status) {
                $documentId = (string) ($status['document_id'] ?? '');
                if ($documentId === '') {
                    continue;
                }

                $meta = [];
                $review = $this->blob->read('review', $documentId);
                if ($review !== null) {
                    $meta = is_array($review['law_meta'] ?? null) ? $review['law_meta'] : [];
                }

                $groups = is_array($meta['law_groups'] ?? null) ? array_values(array_filter($meta['law_groups'], 'is_string')) : [];
                if ($groups === [] && trim((string) ($meta['law_group'] ?? '')) !== '') {
                    $groups = [(string) $meta['law_group']];
                }

                $agencies = is_array($meta['agencies'] ?? null) ? array_values(array_filter($meta['agencies'], 'is_string')) : [];
                if ($agencies === [] && trim((string) ($meta['agency'] ?? '')) !== '') {
                    $agencies = [(string) $meta['agency']];
                }

                $title = trim((string) ($meta['title'] ?? '')) ?: (string) ($status['source_file'] ?? $documentId);
                $parentDocumentId = trim((string) ($meta['parent_document_id'] ?? ''));

                $rows[] = [
                    'document_id' => $documentId,
                    'title' => $title,
                    'status' => (string) ($status['status'] ?? 'unknown'),
                    'updated_at' => $status['updated_at'] ?? null,
                    'access_scope' => ($meta['access_scope'] ?? 'public') === 'private' ? 'private' : 'public',
                    'law_type' => trim((string) ($meta['law_type'] ?? '')),
                    'meta_status' => trim((string) ($meta['status'] ?? '')),
                    'change_status' => trim((string) ($meta['change_status'] ?? '')),
                    'signer_group' => trim((string) ($meta['signer_group'] ?? '')),
                    'law_groups' => $groups,
                    'agencies' => $agencies,
                    'promulgation_date' => trim((string) ($meta['promulgation_date'] ?? '')),
                    'section_count' => isset($meta['section_count']) ? (int) $meta['section_count'] : null,
                    'page_count' => is_array($review['summary'] ?? null) ? (int) ($review['summary']['page_count'] ?? 0) : 0,
                    'parent_document_id' => $parentDocumentId !== '' ? $parentDocumentId : null,
                    'workflow_completed_step' => isset($status['workflow_completed_step']) ? (int) $status['workflow_completed_step'] : null,
                ];
            }

            return $rows;
        });
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function writeReviewDocument(string $documentId, array $document): void
    {
        $this->syncDocumentReview($document);
        $this->blob->write('review', $documentId, $document);
    }

    /**
     * @return array<string, mixed>
     */
    public function getReviewDocument(string $documentId): array
    {
        $document = $this->blob->read('review', $documentId);

        if ($document === null) {
            throw new RuntimeException('Review document not found.');
        }

        $review = is_array($document['document_review'] ?? null) ? $document['document_review'] : [];
        $needsSync = $review === [] || (bool) ($review['out_of_sync'] ?? false);

        if ($needsSync) {
            $this->blob->withLock('review', $documentId, function (array &$doc): void {
                $this->syncDocumentReview($doc);
            });
            $document = $this->blob->read('review', $documentId) ?? $document;
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function patchApprovedBlock(string $documentId, int $pageNo, string $blockId, array $patch): array
    {
        $returnBlock = null;

        $this->blob->withLock('review', $documentId, function (array &$document) use ($pageNo, $blockId, $patch, &$returnBlock): void {
            $block = &$this->findBlockReference($document, $pageNo, $blockId);

            $block['approved_text'] = (string) ($patch['approved_text'] ?? $block['approved_text'] ?? '');
            $block['needs_review'] = (bool) ($patch['mark_uncertain'] ?? false);
            $block['type'] = (string) ($patch['type'] ?? $block['type'] ?? 'paragraph');
            $block['reading_order'] = (int) ($patch['reading_order'] ?? $block['reading_order'] ?? 0);
            $block['bbox'] = $this->normalizeBbox($patch['bbox'] ?? $block['bbox'] ?? null);

            $flags = collect($block['flags'] ?? [])->filter()->values();
            if ($block['needs_review']) {
                $flags = $flags->push('mark_uncertain')->unique()->values();
            } else {
                $flags = $flags->reject(fn (string $flag): bool => $flag === 'mark_uncertain')->values();
            }
            $block['flags'] = $flags->all();

            $existingMeta = is_array($block['meta'] ?? null) ? $block['meta'] : [];
            $existingLayout = is_array($existingMeta['layout'] ?? null) ? $existingMeta['layout'] : [];
            $table = $this->normalizeTable($patch['table'] ?? $existingMeta['table'] ?? null);
            $layout = array_merge($existingLayout, [
                'bbox' => $block['bbox'],
                'reading_order' => $block['reading_order'],
            ]);

            $reviewedHtml = trim((string) ($patch['reviewed_html'] ?? ''));
            if ($reviewedHtml !== '') {
                $reviewedHtml = $this->sanitizeHtml($reviewedHtml);
            }
            $derivedFormatting = $reviewedHtml !== '' ? $this->extractFormattingHints($reviewedHtml) : [];
            $derivedAlignment = $reviewedHtml !== '' ? $this->extractAlignmentHint($reviewedHtml) : null;
            if ($reviewedHtml === '') {
                $reviewedHtml = $this->rebuildBlockHtml($block, $table, $layout, $existingMeta);
            }

            $mergedFormatting = is_array($existingMeta['formatting'] ?? null) ? $existingMeta['formatting'] : [];
            foreach ($derivedFormatting as $key => $value) {
                $mergedFormatting[$key] = $value;
            }

            if ($derivedAlignment !== null) {
                $layout['alignment'] = $derivedAlignment;
            }

            $block['meta'] = array_merge($existingMeta, [
                'reviewed_html' => $reviewedHtml,
                'layout' => $layout,
                'table' => $table,
                'table_html' => $table['html'] ?? null,
                'formatting' => $mergedFormatting,
                'chunk_type' => $patch['chunk_type'] ?? $existingMeta['chunk_type'] ?? null,
                'review' => [
                    'approved_by' => $patch['approved_by'] ?? null,
                    'notes' => $patch['notes'] ?? null,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]);

            $this->recalculateSummary($document);
            $this->markOutOfSync($document);

            $returnBlock = $block;
        });

        return $returnBlock;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function applyReprocessResult(string $documentId, array $result): array
    {
        $returnBlock = null;

        $this->blob->withLock('review', $documentId, function (array &$document) use ($result, &$returnBlock): void {
            $pageNo = (int) ($result['page_no'] ?? 0);
            $blockId = (string) ($result['block_id'] ?? '');
            $block = &$this->findBlockReference($document, $pageNo, $blockId);

            $block['ai_suggested_text'] = (string) ($result['ai_suggested_text'] ?? $block['ai_suggested_text'] ?? '');
            $block['confidence'] = max(0.0, min(1.0, (float) ($result['confidence'] ?? $block['confidence'] ?? 0.0)));
            $block['flags'] = array_values(array_unique(array_map('strval', $result['flags'] ?? $block['flags'] ?? [])));
            $block['needs_review'] = true;

            $existingMeta = is_array($block['meta'] ?? null) ? $block['meta'] : [];
            $layout = is_array($existingMeta['layout'] ?? null) ? $existingMeta['layout'] : [];
            $table = $this->normalizeTable($existingMeta['table'] ?? null);
            $block['meta'] = array_merge($existingMeta, [
                'reviewed_html' => $this->rebuildBlockHtml($block, $table, $layout, $existingMeta),
                'table' => $table,
                'table_html' => $table['html'] ?? null,
            ]);

            $this->recalculateSummary($document);
            $this->markOutOfSync($document);

            $returnBlock = $block;
        });

        return $returnBlock;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateDocumentReview(string $documentId, array $payload): array
    {
        $returnPayload = null;

        $this->blob->withLock('review', $documentId, function (array &$document) use ($documentId, $payload, &$returnPayload): void {
            if (array_key_exists('draft_html', $payload) && is_string($payload['draft_html'])) {
                $this->applyDraftHtmlToBlocksInPlace($document, $documentId, $payload['draft_html']);
            }
            $this->syncDocumentReview($document);
            $this->ensureComposeStateDefaults($document);

            $generatedHtml = (string) ($document['document_review']['generated_html'] ?? '');
            $resetToGenerated = (bool) ($payload['reset_to_generated'] ?? false);
            $hasDraftHtml = array_key_exists('draft_html', $payload);
            $shouldUpdateDraft = $resetToGenerated || $hasDraftHtml;

            if ($shouldUpdateDraft) {
                $draftHtml = $resetToGenerated
                    ? $generatedHtml
                    : trim((string) ($payload['draft_html'] ?? ''));

                if ($draftHtml === '') {
                    throw new RuntimeException('Document HTML draft cannot be empty.');
                }

                $document['document_review'] = array_merge(
                    is_array($document['document_review'] ?? null) ? $document['document_review'] : [],
                    [
                        'generated_html' => $generatedHtml,
                        'draft_html' => $draftHtml,
                        'html_mode' => $resetToGenerated ? 'generated' : 'manual',
                        'out_of_sync' => $resetToGenerated
                            ? false
                            : $this->normalizeHtmlForCompare($draftHtml) !== $this->normalizeHtmlForCompare($generatedHtml),
                        'updated_at' => now()->toIso8601String(),
                    ],
                );
            }

            if (array_key_exists('approved_by', $payload)) {
                $document['document_review']['approved_by'] = $payload['approved_by'];
                $document['document_review']['updated_at'] = now()->toIso8601String();
            }

            if (array_key_exists('notes', $payload)) {
                $document['document_review']['notes'] = $payload['notes'];
                $document['document_review']['updated_at'] = now()->toIso8601String();
            }

            if (array_key_exists('font_family', $payload)) {
                $document['compose_state']['font_family'] = (string) $payload['font_family'];
            }

            if (array_key_exists('font_size_pt', $payload) && $payload['font_size_pt'] !== null) {
                $document['compose_state']['font_size_pt'] = (int) $payload['font_size_pt'];
            }

            if (array_key_exists('metadata', $payload) && is_array($payload['metadata'])) {
                $document['compose_state']['metadata'] = array_merge(
                    is_array($document['compose_state']['metadata'] ?? null) ? $document['compose_state']['metadata'] : [],
                    $payload['metadata'],
                );
            }

            if (array_key_exists('law_meta', $payload) && is_array($payload['law_meta'])) {
                $this->ensureLawMetaDefaults($document);
                $lawMetaPayload = $payload['law_meta'];
                if (($lawMetaPayload['access_scope'] ?? null) === 'public') {
                    $lawMetaPayload['permission_group_ids'] = [];
                }
                $document['law_meta'] = array_merge($document['law_meta'], $lawMetaPayload);
                $this->ensureLawMetaDefaults($document);
            }

            if (array_key_exists('relations', $payload) && is_array($payload['relations'])) {
                $document['relations'] = $payload['relations'];
                $this->ensureRelationsDefaults($document);
            }

            $returnPayload = [
                'document_review' => $document['document_review'],
                'compose_state' => $document['compose_state'],
                'law_meta' => $document['law_meta'] ?? [],
                'relations' => $document['relations'] ?? [],
            ];
        });

        return $returnPayload;
    }

    /**
     * Patch layout metadata on a block without touching approved_text.
     *
     * Supported keys include indent level, marker level, alignment,
     * explicit DOCX-style indent fields, and tab stops.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function patchBlockLayout(string $documentId, int $pageNo, string $blockId, array $patch): array
    {
        $returnBlock = null;

        $this->blob->withLock('review', $documentId, function (array &$document) use ($pageNo, $blockId, $patch, &$returnBlock): void {
            $block = &$this->findBlockReference($document, $pageNo, $blockId);

            $existingMeta = is_array($block['meta'] ?? null) ? $block['meta'] : [];
            $existingLayout = is_array($existingMeta['layout'] ?? null) ? $existingMeta['layout'] : [];

            if (array_key_exists('indent_level', $patch)) {
                $level = $patch['indent_level'];
                $existingLayout['indent_level'] = $level === null ? null : max(0, min(10, (int) $level));
            }

            if (array_key_exists('alignment', $patch)) {
                $existingLayout['alignment'] = $patch['alignment'];  // already validated against allowed set
            }

            foreach (['indent_left', 'indent_first_line', 'indent_hanging'] as $twipsKey) {
                if (array_key_exists($twipsKey, $patch)) {
                    $value = $patch[$twipsKey];
                    $existingLayout[$twipsKey] = $value === null ? null : max(0, min(14400, (int) $value));
                }
            }

            if (array_key_exists('tabs', $patch)) {
                $tabs = [];
                foreach ((array) $patch['tabs'] as $tab) {
                    if (! is_array($tab)) {
                        continue;
                    }
                    $tabs[] = [
                        'position' => max(0, min(14400, (int) ($tab['position'] ?? 0))),
                        'type' => (string) ($tab['type'] ?? 'left'),
                    ];
                }
                $existingLayout['tabs'] = $tabs;
            }

            $block['meta'] = array_merge($existingMeta, ['layout' => $existingLayout]);

            if (array_key_exists('list_marker_level', $patch)) {
                $existingMarker = is_array($existingMeta['list_marker'] ?? null) ? $existingMeta['list_marker'] : null;
                if ($existingMarker !== null) {
                    $markerLevel = $patch['list_marker_level'];
                    $existingMarker['level'] = max(1, min(6, (int) $markerLevel));
                    $block['meta']['list_marker'] = $existingMarker;
                }
            }

            $this->markOutOfSync($document);
            $returnBlock = $block;
        });

        return $returnBlock;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function patchBlockSize(string $documentId, int $pageNo, string $blockId, array $patch): array
    {
        $returnBlock = null;

        $this->blob->withLock('review', $documentId, function (array &$document) use ($pageNo, $blockId, $patch, &$returnBlock): void {
            $block = &$this->findBlockReference($document, $pageNo, $blockId);

            $existingMeta = is_array($block['meta'] ?? null) ? $block['meta'] : [];
            $widthPx = isset($patch['display_width_px']) ? (int) $patch['display_width_px'] : null;
            $heightPx = isset($patch['display_height_px']) ? (int) $patch['display_height_px'] : null;
            $blockType = (string) ($block['type'] ?? '');

            if ($blockType === 'image') {
                $image = is_array($existingMeta['image'] ?? null) ? $existingMeta['image'] : [];
                $image['display_width_px'] = $widthPx;
                $image['display_height_px'] = $heightPx;
                $existingMeta['image'] = $image;
            } else {
                $existingMeta['table_display_width_px'] = $widthPx;
            }

            $block['meta'] = $existingMeta;
            $returnBlock = $block;
        });

        return $returnBlock;
    }

    /**
     * @param  array<string, mixed>  $exportData
     */
    public function writeExport(string $documentId, array $exportData): string
    {
        $relativePath = sprintf('exports/%s.rag.json', $documentId);
        $absolutePath = $this->absolutePath($relativePath);
        $this->writeJson($absolutePath, $exportData);

        return $relativePath;
    }

    /**
     * @param  array<string, mixed>  $ingestData
     */
    public function writeIngest(string $documentId, array $ingestData): string
    {
        $relativePath = sprintf('ingested/%s.ingested.json', $documentId);
        $absolutePath = $this->absolutePath($relativePath);
        $this->writeJson($absolutePath, $ingestData);

        return $relativePath;
    }

    public function reviewRelativePath(string $documentId): string
    {
        return sprintf('intermediate/%s.review.json', $documentId);
    }

    public function exportRelativePath(string $documentId): string
    {
        return sprintf('exports/%s.rag.json', $documentId);
    }

    public function ingestRelativePath(string $documentId): string
    {
        return sprintf('ingested/%s.ingested.json', $documentId);
    }

    private function ensureDirectories(): void
    {
        foreach (['uploads', 'pages', 'exports', 'ingested'] as $dir) {
            $this->ensureDirectoryExists($this->basePath.'/'.$dir);
        }
    }

    /**
     * Reorder blocks across all pages and renumber their reading_order.
     *
     * @param  array<string>  $blockIds
     */
    public function reorderBlocks(string $documentId, array $blockIds): void
    {
        $this->blob->withLock('review', $documentId, function (array &$document) use ($blockIds): void {
            // Collect all blocks by ID for fast lookup
            $blockMap = [];
            foreach (($document['pages'] ?? []) as &$page) {
                foreach (($page['blocks'] ?? []) as &$block) {
                    $blockId = (string) ($block['block_id'] ?? '');
                    if ($blockId !== '') {
                        $blockMap[$blockId] = &$block;
                    }
                }
            }

            // Validate all requested block IDs exist
            foreach ($blockIds as $id) {
                if (! isset($blockMap[$id])) {
                    throw new RuntimeException("Block not found: {$id}");
                }
            }

            // Assign reading_order according to the new sequence
            // Note: direct index mutation because reference chain from &$page/&$block may not propagate through json_decode arrays
            $blockIdToOrder = [];
            foreach ($blockIds as $idx => $blockId) {
                $blockIdToOrder[$blockId] = $idx + 1;
            }
            foreach ($document['pages'] as &$pageForOrder) {
                foreach ($pageForOrder['blocks'] as &$blockForOrder) {
                    $bid = (string) ($blockForOrder['block_id'] ?? '');
                    if (isset($blockIdToOrder[$bid])) {
                        $blockForOrder['reading_order'] = $blockIdToOrder[$bid];
                    }
                }
                unset($blockForOrder);
            }
            unset($pageForOrder);

            // Physically sort blocks within each page by reading_order so array iteration matches display order
            foreach (array_keys($document['pages']) as $pi) {
                usort($document['pages'][$pi]['blocks'], fn ($a, $b) => ($a['reading_order'] ?? 0) <=> ($b['reading_order'] ?? 0));
            }

            $this->markOutOfSync($document);
            $this->recalculateSummary($document);
        });
    }

    public function deleteBlock(string $documentId, int $pageNo, string $blockId): void
    {
        $this->blob->withLock('review', $documentId, function (array &$document) use ($pageNo, $blockId): void {
            $deleted = false;
            foreach ($document['pages'] as &$page) {
                if ((int) ($page['page_no'] ?? 0) !== $pageNo) {
                    continue;
                }
                $before = count($page['blocks'] ?? []);
                $page['blocks'] = array_values(
                    array_filter(
                        $page['blocks'] ?? [],
                        static fn (array $b): bool => (string) ($b['block_id'] ?? '') !== $blockId,
                    ),
                );
                if (count($page['blocks']) < $before) {
                    $deleted = true;
                }
            }
            if (! $deleted) {
                throw new RuntimeException("Block not found: {$blockId}");
            }
            $this->recalculateSummary($document);
            $this->markOutOfSync($document);
        });
    }

    /**
     * @param  array<string>  $blockIds
     * @return array<string, mixed>
     */
    public function mergeBlocks(string $documentId, array $blockIds): array
    {
        if (count($blockIds) < 2) {
            throw new \InvalidArgumentException('mergeBlocks requires at least 2 block IDs.');
        }

        $idSet = array_flip(array_map('strval', $blockIds));
        $returnBlock = null;

        $this->blob->withLock('review', $documentId, function (array &$document) use ($idSet, &$returnBlock): void {
            // Collect selected blocks in DOCUMENT order (not the caller's click/selection order).
            $ordered = [];
            foreach (($document['pages'] ?? []) as $page) {
                foreach (($page['blocks'] ?? []) as $b) {
                    $id = (string) ($b['block_id'] ?? '');
                    if (isset($idSet[$id])) {
                        $ordered[] = $b;
                    }
                }
            }

            if (count($ordered) !== count($idSet)) {
                throw new RuntimeException('One or more blocks not found for merge.');
            }

            // Anchor is the topmost selected block in document order.
            $anchorId = (string) ($ordered[0]['block_id'] ?? '');

            $mergedText = implode("\n", array_map(
                static fn (array $b): string => (string) ($b['approved_text'] ?? $b['normalized_text'] ?? ''),
                $ordered,
            ));

            // Every block must contribute visible HTML: use its reviewed_html when present,
            // otherwise fall back to an escaped paragraph built from its text — so no content
            // silently disappears when a merged block has no reviewed_html.
            $mergedHtml = implode('', array_map(
                static function (array $b): string {
                    $html = (string) ($b['meta']['reviewed_html'] ?? '');
                    if (trim($html) !== '') {
                        return $html;
                    }
                    $text = (string) ($b['approved_text'] ?? $b['normalized_text'] ?? $b['raw_text'] ?? '');

                    return '<p>'.nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')).'</p>';
                },
                $ordered,
            ));

            foreach ($document['pages'] as &$page) {
                foreach ($page['blocks'] as &$block) {
                    if ((string) ($block['block_id'] ?? '') === $anchorId) {
                        $block['approved_text'] = $mergedText;
                        $block['meta']['reviewed_html'] = $this->sanitizeHtml($mergedHtml);
                        $block['needs_review'] = false;
                        $returnBlock = $block;
                    }
                }
                unset($block);

                // Keep the anchor; drop every other selected block.
                $page['blocks'] = array_values(array_filter(
                    $page['blocks'],
                    static function (array $b) use ($idSet, $anchorId): bool {
                        $id = (string) ($b['block_id'] ?? '');

                        return $id === $anchorId || ! isset($idSet[$id]);
                    },
                ));
            }
            unset($page);

            $this->recalculateSummary($document);
            $this->markOutOfSync($document);
        });

        if ($returnBlock === null) {
            throw new RuntimeException('Merge target block could not be updated.');
        }

        /** @var array<string, mixed> $returnBlock */
        return $returnBlock;
    }

    /**
     * @param  array<int, array{page_no: int, blocks: array<int, array<string, mixed>>}>  $pages
     */
    public function restoreBlocks(string $documentId, array $pages): void
    {
        $pagesByNo = [];

        foreach ($pages as $page) {
            $pageNo = (int) ($page['page_no'] ?? 0);
            if ($pageNo < 1 || ! is_array($page['blocks'] ?? null)) {
                throw new RuntimeException('Invalid restore payload.');
            }

            $pagesByNo[$pageNo] = array_values($page['blocks']);
        }

        $this->blob->withLock('review', $documentId, function (array &$document) use ($pagesByNo): void {
            $restoredPages = [];

            foreach (array_keys($document['pages'] ?? []) as $pageIndex) {
                $pageNo = (int) ($document['pages'][$pageIndex]['page_no'] ?? 0);
                if (! array_key_exists($pageNo, $pagesByNo)) {
                    continue;
                }

                $document['pages'][$pageIndex]['blocks'] = $pagesByNo[$pageNo];
                $restoredPages[$pageNo] = true;
            }

            if (count($restoredPages) !== count($pagesByNo)) {
                throw new RuntimeException('One or more pages not found for restore.');
            }

            $this->recalculateSummary($document);
            $this->markOutOfSync($document);
        });
    }

    /**
     * @return array{first: array<string, mixed>, second: array<string, mixed>}
     */
    public function splitBlock(
        string $documentId,
        int $pageNo,
        string $blockId,
        string $beforeText,
        string $beforeHtml,
        string $afterText,
        string $afterHtml,
    ): array {
        $result = null;

        $this->blob->withLock('review', $documentId, function (array &$document) use ($documentId, $pageNo, $blockId, $beforeText, $beforeHtml, $afterText, $afterHtml, &$result): void {
            foreach ($document['pages'] as &$page) {
                if ((int) ($page['page_no'] ?? 0) !== $pageNo) {
                    continue;
                }
                $newBlocks = [];
                foreach ($page['blocks'] as $block) {
                    if ((string) ($block['block_id'] ?? '') === $blockId) {
                        $block['approved_text'] = $beforeText;
                        $block['meta']['reviewed_html'] = $this->sanitizeHtml($beforeHtml);
                        $block['needs_review'] = false;
                        $newBlocks[] = $block;

                        $newId = $documentId.'_split_'.substr(bin2hex(random_bytes(3)), 0, 6);
                        $second = $block;
                        $second['block_id'] = $newId;
                        $second['approved_text'] = $afterText;
                        $second['meta'] = is_array($block['meta'] ?? null) ? $block['meta'] : [];
                        $second['meta']['reviewed_html'] = $this->sanitizeHtml($afterHtml);
                        // The tail of a split is body text, never a heading.
                        unset($second['meta']['chunk_type']);
                        $newBlocks[] = $second;

                        $result = ['first' => $block, 'second' => $second];
                    } else {
                        $newBlocks[] = $block;
                    }
                }
                foreach ($newBlocks as $i => &$b) {
                    $b['reading_order'] = $i + 1;
                }
                unset($b);
                $page['blocks'] = $newBlocks;
            }

            $this->recalculateSummary($document);
            $this->markOutOfSync($document);
        });

        if ($result === null) {
            throw new RuntimeException("Block not found: {$blockId}");
        }

        /** @var array{first: array<string, mixed>, second: array<string, mixed>} $result */
        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createBlock(string $documentId, int $pageNo, ?string $afterBlockId, array $data): array
    {
        $newBlockId = $documentId.'_new_'.substr(bin2hex(random_bytes(3)), 0, 6);
        $newBlock = [
            'block_id' => $newBlockId,
            'type' => (string) ($data['type'] ?? 'paragraph'),
            'bbox' => null,
            'reading_order' => 0,
            'raw_text' => (string) ($data['approved_text'] ?? ''),
            'normalized_text' => (string) ($data['approved_text'] ?? ''),
            'ai_suggested_text' => '',
            'approved_text' => (string) ($data['approved_text'] ?? ''),
            'confidence' => 1.0,
            'needs_review' => false,
            'flags' => [],
            'meta' => [
                'reviewed_html' => $this->sanitizeHtml((string) ($data['reviewed_html'] ?? '<p></p>')),
                'layout' => is_array($data['layout'] ?? null) ? $data['layout'] : [],
            ],
        ];

        $this->blob->withLock('review', $documentId, function (array &$document) use ($pageNo, $afterBlockId, $newBlock): void {
            foreach ($document['pages'] as &$page) {
                if ((int) ($page['page_no'] ?? 0) !== $pageNo) {
                    continue;
                }
                if ($afterBlockId === null) {
                    $page['blocks'][] = $newBlock;
                } else {
                    $rebuilt = [];
                    foreach ($page['blocks'] as $b) {
                        $rebuilt[] = $b;
                        if ((string) ($b['block_id'] ?? '') === $afterBlockId) {
                            $rebuilt[] = $newBlock;
                        }
                    }
                    $page['blocks'] = $rebuilt;
                }
                foreach ($page['blocks'] as $i => &$b) {
                    $b['reading_order'] = $i + 1;
                }
                unset($b);
                break;
            }
            $this->recalculateSummary($document);
            $this->markOutOfSync($document);
        });

        return $newBlock;
    }

    /**
     * Apply Python normalize results onto the stored review document.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public function applyNormalizationResults(string $documentId, array $results): array
    {
        $returnDoc = [];

        $this->blob->withLock('review', $documentId, function (array &$document) use ($results, &$returnDoc): void {
            if ($document === []) {
                throw new RuntimeException('Review document not found.');
            }

            $byId = [];
            foreach ($results as $result) {
                $blockId = $result['block_id'] ?? null;
                if (is_string($blockId) && $blockId !== '') {
                    $byId[$blockId] = $result;
                }
            }

            $reviewCount = 0;
            foreach ($document['pages'] ?? [] as $pageIndex => $page) {
                foreach ($page['blocks'] ?? [] as $blockIndex => $block) {
                    $blockId = $block['block_id'] ?? null;

                    if (is_string($blockId) && isset($byId[$blockId])) {
                        $result = $byId[$blockId];
                        $approved = (string) ($result['approved_text'] ?? ($block['approved_text'] ?? ''));

                        $block['normalized_text'] = (string) ($result['normalized_text'] ?? ($block['normalized_text'] ?? ''));
                        $block['approved_text'] = $approved;
                        $block['ai_suggested_text'] = $approved;
                        $block['flags'] = $result['flags'] ?? ($block['flags'] ?? []);
                        $block['needs_review'] = false;
                        $existingMeta = is_array($block['meta'] ?? null) ? $block['meta'] : [];
                        $existingMeta['spell_suggestions'] = $result['spell_suggestions'] ?? [];
                        $block['meta'] = $existingMeta;

                        $document['pages'][$pageIndex]['blocks'][$blockIndex] = $block;
                    }

                    if (($document['pages'][$pageIndex]['blocks'][$blockIndex]['needs_review'] ?? false) === true) {
                        $reviewCount++;
                    }
                }
            }

            $document['summary']['review_required_count'] = $reviewCount;

            $this->syncDocumentReview($document);
            $returnDoc = $document;
        });

        return $returnDoc;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function &findBlockReference(array &$document, int $pageNo, string $blockId): array
    {
        if (! isset($document['pages']) || ! is_array($document['pages'])) {
            throw new RuntimeException('Invalid review document format.');
        }

        foreach ($document['pages'] as &$page) {
            if ((int) ($page['page_no'] ?? 0) !== $pageNo) {
                continue;
            }

            if (! isset($page['blocks']) || ! is_array($page['blocks'])) {
                continue;
            }

            foreach ($page['blocks'] as &$block) {
                if ((string) ($block['block_id'] ?? '') === $blockId) {
                    return $block;
                }
            }
        }

        throw new RuntimeException('Block not found.');
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function recalculateSummary(array &$document): void
    {
        $pages = is_array($document['pages'] ?? null) ? $document['pages'] : [];
        $blockCount = 0;
        $reviewCount = 0;

        foreach ($pages as $page) {
            foreach (($page['blocks'] ?? []) as $block) {
                $blockCount++;
                if ((bool) ($block['needs_review'] ?? false)) {
                    $reviewCount++;
                }
            }
        }

        $document['summary'] = [
            'page_count' => count($pages),
            'block_count' => $blockCount,
            'review_required_count' => $reviewCount,
        ];
    }

    /**
     * Map edited draft_html back onto document blocks by data-block-id, so the
     * RAG and /law screens (which render from blocks) reflect review edits.
     * v1: 1:1 block_id match only — structural edits (split/merge/new/delete)
     * are not reconciled, matching the buildChunksFromHtml/export limitation.
     *
     * @param  array<string, mixed>  $document
     */
    private function applyDraftHtmlToBlocksInPlace(array &$document, string $documentId, string $html): void
    {
        if (trim($html) === '') {
            return;
        }

        $parsed = $this->documentHtmlService->buildChunksFromHtml($documentId, $html);
        $byId = [];
        foreach (($parsed['chunks'] ?? []) as $chunk) {
            foreach (($chunk['block_ids'] ?? []) as $bid) {
                if (is_string($bid) && $bid !== '') {
                    $byId[$bid] = $chunk;
                }
            }
        }
        $imgAlignments = $this->extractImageAlignmentsFromHtml($html);

        // ponytail: no ?? [] on foreach with references — PHP copies the rvalue, breaking mutation
        foreach (array_keys($document['pages'] ?? []) as $pi) {
            foreach (array_keys($document['pages'][$pi]['blocks'] ?? []) as $bi) {
                $block = &$document['pages'][$pi]['blocks'][$bi];
                $bid = (string) ($block['block_id'] ?? '');
                $type = (string) ($block['type'] ?? '');
                if ($type === 'image') {
                    if ($bid !== '' && array_key_exists($bid, $imgAlignments)) {
                        $meta = is_array($block['meta'] ?? null) ? $block['meta'] : [];
                        $layout = is_array($meta['layout'] ?? null) ? $meta['layout'] : [];
                        $al = $imgAlignments[$bid];
                        if ($al !== null) {
                            $layout['alignment'] = $al;
                        } else {
                            unset($layout['alignment']);
                        }
                        $meta['layout'] = $layout;
                        $block['meta'] = $meta;
                    }
                    unset($block);

                    continue;
                }
                if ($type === 'table' || $bid === '' || ! isset($byId[$bid])) {
                    unset($block);

                    continue;
                }
                $text = trim((string) ($byId[$bid]['text'] ?? ''));
                if ($text === '') {
                    unset($block);

                    continue;
                }
                $block['approved_text'] = $text;
                $block['normalized_text'] = $text;
                $meta = is_array($block['meta'] ?? null) ? $block['meta'] : [];
                $meta['reviewed_html'] = (string) ($byId[$bid]['meta']['html'] ?? '');
                $meta['formatting'] = array_merge(
                    is_array($meta['formatting'] ?? null) ? $meta['formatting'] : [],
                    $this->extractFormattingHints((string) $meta['reviewed_html']),
                );
                // Prefer the alignment captured from the wrapper element (TextAlign puts
                // text-align on the block's own <p>, which innerHtml drops from reviewed_html).
                $derivedAlignment = $byId[$bid]['meta']['layout']['alignment']
                    ?? $this->extractAlignmentHint((string) $meta['reviewed_html']);
                $derivedIndentLeft = $byId[$bid]['meta']['layout']['indent_left'] ?? null;
                $layout = is_array($meta['layout'] ?? null) ? $meta['layout'] : [];
                if ($derivedAlignment !== null) {
                    $layout['alignment'] = $derivedAlignment;
                } else {
                    // Reverting to the default (left) drops the style entirely — clear it.
                    unset($layout['alignment']);
                }
                if (is_numeric($derivedIndentLeft) && (float) $derivedIndentLeft > 0) {
                    $layout['indent_left'] = (int) round((float) $derivedIndentLeft);
                } else {
                    unset($layout['indent_left']);
                }
                // First-line indent (text-indent): positive → first-line, negative → hanging.
                // FirstLineIndentExtension round-trips text-indent, so absence means removed.
                $derivedFirstLine = $byId[$bid]['meta']['layout']['indent_first_line'] ?? null;
                if (is_numeric($derivedFirstLine) && (float) $derivedFirstLine > 0) {
                    $layout['indent_first_line'] = (int) round((float) $derivedFirstLine);
                    unset($layout['indent_hanging']);
                } elseif (is_numeric($derivedFirstLine) && (float) $derivedFirstLine < 0) {
                    $layout['indent_hanging'] = (int) round(abs((float) $derivedFirstLine));
                    unset($layout['indent_first_line']);
                } else {
                    unset($layout['indent_first_line'], $layout['indent_hanging']);
                }
                $meta['layout'] = $layout;
                $block['meta'] = $meta;
                unset($block);
            }
        }
    }

    /**
     * Mark the document's generated HTML as out of sync with the current block states.
     * The HTML will be lazily rebuilt the next time getReviewDocument is called.
     *
     * @param  array<string, mixed>  $document
     */
    private function markOutOfSync(array &$document): void
    {
        if (! is_array($document['document_review'] ?? null)) {
            return;
        }
        $document['document_review']['out_of_sync'] = true;
        $document['document_review']['updated_at'] = now()->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create directory: {$path}");
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function syncDocumentReview(array &$document): void
    {
        $this->ensureComposeStateDefaults($document);
        $this->ensureLawMetaDefaults($document);
        $this->ensureRelationsDefaults($document);

        $generatedHtml = $this->documentHtmlService->buildGeneratedHtml($document);
        $existing = is_array($document['document_review'] ?? null) ? $document['document_review'] : [];
        $mode = ($existing['html_mode'] ?? 'generated') === 'manual' ? 'manual' : 'generated';
        $draftHtml = trim((string) ($existing['draft_html'] ?? ''));

        if ($draftHtml === '' || $mode !== 'manual') {
            $draftHtml = $generatedHtml;
            $mode = 'generated';
        }

        $document['document_review'] = array_merge($existing, [
            'generated_html' => $generatedHtml,
            'draft_html' => $draftHtml,
            'html_mode' => $mode,
            'out_of_sync' => $mode === 'manual'
                && $this->normalizeHtmlForCompare($draftHtml) !== $this->normalizeHtmlForCompare($generatedHtml),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function ensureComposeStateDefaults(array &$document): void
    {
        $compose = is_array($document['compose_state'] ?? null) ? $document['compose_state'] : [];
        $metadata = is_array($compose['metadata'] ?? null) ? $compose['metadata'] : [];

        $document['compose_state'] = array_merge([
            'font_family' => 'sarabun',
            'font_size_pt' => 16,
            'metadata' => [],
        ], $compose, [
            'metadata' => array_merge([
                'department' => '',
                'doc_number' => '',
                'date' => '',
                'subject' => '',
                'recipient' => '',
                'reference' => '',
                'attachments' => '',
                'urgency' => '',
                'confidentiality' => '',
                'signatory_name' => '',
                'signatory_position' => '',
            ], $metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function ensureRelationsDefaults(array &$document): void
    {
        $raw = is_array($document['relations'] ?? null) ? $document['relations'] : [];
        $clean = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $title = trim((string) ($entry['target_title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $type = ($entry['type'] ?? 'related') === 'repeals' ? 'repeals' : 'related';
            $scope = ($entry['scope'] ?? 'document') === 'section' ? 'section' : 'document';

            $clean[] = [
                'id' => (string) ($entry['id'] ?? bin2hex(random_bytes(6))),
                'scope' => $scope,
                'block_id' => $scope === 'section' ? (((string) ($entry['block_id'] ?? '')) ?: null) : null,
                'type' => $type,
                'target_document_id' => isset($entry['target_document_id']) && $entry['target_document_id'] !== ''
                    ? (string) $entry['target_document_id'] : null,
                'target_title' => $title,
                'target_section' => trim((string) ($entry['target_section'] ?? '')) ?: null,
                'note' => trim((string) ($entry['note'] ?? '')) ?: null,
                'url' => trim((string) ($entry['url'] ?? '')) ?: null,
            ];
        }

        $document['relations'] = $clean;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function ensureLawMetaDefaults(array &$document): void
    {
        $meta = is_array($document['law_meta'] ?? null) ? $document['law_meta'] : [];

        $repealed = [];
        foreach (($meta['repealed_laws'] ?? []) as $entry) {
            $text = trim((string) $entry);
            if ($text !== '') {
                $repealed[] = $text;
            }
        }

        $keywords = [];
        foreach (($meta['keywords'] ?? []) as $entry) {
            $text = trim((string) $entry);
            if ($text !== '' && ! in_array($text, $keywords, true)) {
                $keywords[] = $text;
            }
        }

        $parentDocumentId = trim((string) ($meta['parent_document_id'] ?? ''));
        $lawGroups = is_array($meta['law_groups'] ?? null) ? array_values(array_filter(array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            $meta['law_groups'],
        ))) : [];
        $agencies = is_array($meta['agencies'] ?? null) ? array_values(array_filter(array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            $meta['agencies'],
        ))) : [];
        $legacyLawGroup = trim((string) ($meta['law_group'] ?? ''));
        $legacyAgency = trim((string) ($meta['agency'] ?? ''));
        $accessScope = ($meta['access_scope'] ?? 'public') === 'private' ? 'private' : 'public';
        $permissionGroupIds = is_array($meta['permission_group_ids'] ?? null) ? array_values(array_unique(array_filter(array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            $meta['permission_group_ids'],
        )))) : [];

        if ($lawGroups === [] && $legacyLawGroup !== '') {
            $lawGroups = [$legacyLawGroup];
        }

        if ($agencies === [] && $legacyAgency !== '') {
            $agencies = [$legacyAgency];
        }

        $sectionCount = $this->countLawSections($document);

        $document['law_meta'] = array_merge([
            'status' => '',
            'law_type' => '',
            'law_group' => '',
            'change_status' => null,
            'law_groups' => [],
            'agency' => '',
            'signer_group' => null,
            'agencies' => [],
            'keywords' => [],
            'promulgation_date' => '',
            'effective_date' => '',
            'gazette_reference' => '',
            'royal_command' => '',
            'parent_document_id' => null,
            'access_scope' => 'public',
            'permission_group_ids' => [],
        ], $meta, [
            'law_group' => $lawGroups[0] ?? '',
            'law_groups' => $lawGroups,
            'agency' => $agencies[0] ?? '',
            'agencies' => $agencies,
            'keywords' => $keywords,
            'repealed_laws' => $repealed,
            'section_count' => $sectionCount,
            'parent_document_id' => $parentDocumentId !== '' ? $parentDocumentId : null,
            'access_scope' => $accessScope,
            'permission_group_ids' => $accessScope === 'public' ? [] : $permissionGroupIds,
        ]);
    }

    private function countLawSections(array $document): int
    {
        $count = 0;
        foreach (($document['pages'] ?? []) as $page) {
            if (! is_array($page) || ! is_array($page['blocks'] ?? null)) {
                continue;
            }

            foreach ($page['blocks'] as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $chunkType = strtoupper(trim((string) ($block['meta']['chunk_type'] ?? '')));
                if ($chunkType === 'ARTICLE' || $chunkType === 'CLAUSE') {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument;
        $prevLibxmlErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($prevLibxmlErrors);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body) {
            return '';
        }

        static::cleanNode($body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * @return array{bold?: bool, italic?: bool, underline?: bool}
     */
    private function extractFormattingHints(string $html): array
    {
        return [
            'bold' => preg_match('/<(strong|b)\b/i', $html) === 1,
            'italic' => preg_match('/<(em|i)\b/i', $html) === 1,
            'underline' => preg_match('/<u\b/i', $html) === 1,
        ];
    }

    private function extractAlignmentHint(string $html): ?string
    {
        if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $html, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Scan draft HTML for <img data-block-id="..."> elements and return each block ID's
     * alignment from the nearest ancestor with a text-align style (TipTap wraps the image
     * node in a <p style="text-align:..."> when the user changes alignment).
     *
     * @return array<string, string|null> blockId => alignment (null = revert to default)
     */
    private function extractImageAlignmentsFromHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $internalErrors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $xpath = new \DOMXPath($dom);
        $result = [];
        foreach ($xpath->query('//img[@data-block-id]') as $img) {
            /** @var \DOMElement $img */
            $bid = $img->getAttribute('data-block-id');
            if ($bid === '') {
                continue;
            }
            $alignment = null;
            $node = $img->parentNode;
            while ($node instanceof \DOMElement) {
                $style = $node->getAttribute('style');
                if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $style, $m) === 1) {
                    $alignment = strtolower($m[1]);
                    break;
                }
                $node = $node->parentNode;
            }
            $result[$bid] = $alignment;
        }

        return $result;
    }

    /**
     * Recursively clean nodes: remove disallowed tags (hoisting their content),
     * filter attributes, and sanitize inline styles.
     */
    private static function cleanNode(\DOMNode $node): void
    {
        /** @var string[] $allowed */
        static $allowed = ['p', 'br', 'strong', 'em', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'span', 'div', 'sub', 'sup'];
        /** @var string[] $allowedStyleProps */
        static $allowedStyleProps = ['margin-left', 'text-indent', 'text-align'];

        $toReplace = [];
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child->nodeType !== \XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (! in_array($tag, $allowed, true)) {
                $toReplace[] = $child;

                continue;
            }
            if ($child instanceof \DOMElement) {
                $attrsToRemove = [];
                foreach ($child->attributes as $attr) {
                    $name = strtolower($attr->name);
                    if ($name === 'style') {
                        $safe = static::filterStyle($attr->value, $allowedStyleProps);
                        if ($safe === '') {
                            $attrsToRemove[] = 'style';
                        } else {
                            $attr->value = $safe;
                        }
                    } elseif (! in_array($name, ['class', 'colspan', 'rowspan'], true)) {
                        $attrsToRemove[] = $attr->name;
                    }
                }
                foreach ($attrsToRemove as $a) {
                    $child->removeAttribute($a);
                }
            }
            static::cleanNode($child);
        }

        foreach ($toReplace as $child) {
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
        }
        // Re-clean hoisted children (they were not previously processed)
        if ($toReplace !== []) {
            static::cleanNode($node);
        }
    }

    /**
     * Filter CSS declaration string to only include allowed properties.
     *
     * @param  string[]  $allowedProps
     */
    private static function filterStyle(string $style, array $allowedProps): string
    {
        $safe = [];
        foreach (array_filter(array_map('trim', explode(';', $style))) as $decl) {
            if (($colon = strpos($decl, ':')) === false) {
                continue;
            }
            $prop = strtolower(trim(substr($decl, 0, $colon)));
            if (! in_array($prop, $allowedProps, true)) {
                continue;
            }
            $value = trim(substr($decl, $colon + 1));
            if ($value === '') {
                continue;
            }
            // Only allow safe CSS values: numbers with units, or alignment keywords
            if (! preg_match('/^-?[\d.]+(px|em|rem|%|pt|cm|mm|vw|vh)?$|^(left|center|right|justify|auto|inherit|initial|0)$/i', $value)) {
                continue;
            }
            $safe[] = $prop.': '.$value;
        }

        return implode('; ', $safe);
    }

    private function normalizeHtmlForCompare(string $html): string
    {
        return preg_replace('/\s+/u', ' ', trim($html)) ?? trim($html);
    }

    /**
     * @return array<int, float>|null
     */
    private function normalizeBbox(mixed $bbox): ?array
    {
        if (! is_array($bbox) || count($bbox) !== 4) {
            return null;
        }

        return array_map(static fn ($value): float => (float) $value, array_values($bbox));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeTable(mixed $table): ?array
    {
        return $this->documentHtmlService->normalizeTablePayload($table);
    }

    /**
     * Rebuild a single block's reviewed_html after a text edit.
     *
     * Uses the precomputed layout_css from meta when available (Option B) to avoid
     * an HTTP round-trip back to the Python service. Falls back to buildBlockHtml
     * for tables and blocks without layout_css.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>|null  $table
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $existingMeta
     */
    private function rebuildBlockHtml(array $block, ?array $table, array $layout, array $existingMeta): string
    {
        $type = (string) ($block['type'] ?? 'paragraph');
        $text = (string) ($block['approved_text'] ?? $block['ai_suggested_text'] ?? $block['normalized_text'] ?? '');

        // layout_css is standardized under meta.layout.layout_css (set by Python block_builder)
        $existingLayout = is_array($existingMeta['layout'] ?? null) ? $existingMeta['layout'] : [];
        $layoutCss = trim((string) ($existingLayout['layout_css'] ?? ''));

        if ($type !== 'table' && $type !== 'image' && $layoutCss !== '') {
            $classMap = [
                'list_item' => 'doc-paragraph doc-list-item',
                'title' => 'doc-paragraph doc-title',
                'section_header' => 'doc-paragraph doc-section-header',
                'figure_caption' => 'doc-paragraph doc-figure-caption',
                'footnote' => 'doc-paragraph doc-footnote',
            ];
            $classes = $classMap[$type] ?? 'doc-paragraph';
            $escaped = e(str_replace(["\r\n", "\r", "\n"], '<br>', $text));

            return sprintf('<p class="%s" style="%s">%s</p>', e($classes), e($layoutCss), $escaped);
        }

        $tempBlock = $block;
        $tempBlock['meta'] = array_merge($existingMeta, [
            'reviewed_html' => '',
            'layout' => $layout,
            'table' => $table,
        ]);

        return $this->documentHtmlService->buildBlockHtml($tempBlock);
    }
}
