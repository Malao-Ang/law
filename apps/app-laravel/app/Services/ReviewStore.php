<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ReviewStore
{
    private string $basePath;

    public function __construct(private readonly DocumentHtmlService $documentHtmlService)
    {
        $this->basePath = storage_path('app/poc');
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

    /**
     * @param array<string, mixed> $status
     */
    public function setStatus(string $documentId, array $status): void
    {
        $path = $this->statusPath($documentId);
        File::ensureDirectoryExists(dirname($path));

        $this->withLockedFile($path, function (array &$current) use ($documentId, $status): void {
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
        $path = $this->statusPath($documentId);

        if (! File::exists($path)) {
            return null;
        }

        return $this->readJson($path);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function writeReviewDocument(string $documentId, array $document): void
    {
        $this->syncDocumentReview($document);

        $path = $this->intermediatePath($documentId);
        File::ensureDirectoryExists(dirname($path));
        $this->atomicWrite($path, $document);
    }

    /**
     * @return array<string, mixed>
     */
    public function getReviewDocument(string $documentId): array
    {
        $path = $this->intermediatePath($documentId);

        if (! File::exists($path)) {
            throw new RuntimeException('Review document not found.');
        }

        $document = $this->readJson($path);

        $review = is_array($document['document_review'] ?? null) ? $document['document_review'] : [];
        $needsSync = $review === [] || (bool) ($review['out_of_sync'] ?? false);

        if ($needsSync) {
            $this->syncDocumentReview($document);
            $this->atomicWrite($path, $document);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function patchApprovedBlock(string $documentId, int $pageNo, string $blockId, array $patch): array
    {
        $returnBlock = null;

        $this->withLockedFile($this->intermediatePath($documentId), function (array &$document) use ($pageNo, $blockId, $patch, &$returnBlock): void {
            $block = &$this->findBlockReference($document, $pageNo, $blockId);

            $block['approved_text'] = (string) $patch['approved_text'];
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
            if ($reviewedHtml === '') {
                $reviewedHtml = $this->rebuildBlockHtml($block, $table, $layout, $existingMeta);
            }

            $block['meta'] = array_merge($existingMeta, [
                'reviewed_html' => $reviewedHtml,
                'layout' => $layout,
                'table' => $table,
                'table_html' => $table['html'] ?? null,
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
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function applyReprocessResult(string $documentId, array $result): array
    {
        $returnBlock = null;

        $this->withLockedFile($this->intermediatePath($documentId), function (array &$document) use ($result, &$returnBlock): void {
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateDocumentReview(string $documentId, array $payload): array
    {
        $returnReview = null;

        $this->withLockedFile($this->intermediatePath($documentId), function (array &$document) use ($payload, &$returnReview): void {
            $this->syncDocumentReview($document);

            $generatedHtml = (string) ($document['document_review']['generated_html'] ?? '');
            $resetToGenerated = (bool) ($payload['reset_to_generated'] ?? false);
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
                    'approved_by' => $payload['approved_by'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                ],
            );

            $returnReview = $document['document_review'];
        });

        return $returnReview;
    }

    /**
     * @param array<string, mixed> $exportData
     */
    public function writeExport(string $documentId, array $exportData): string
    {
        $relativePath = sprintf('exports/%s.rag.json', $documentId);
        $absolutePath = $this->absolutePath($relativePath);
        $this->writeJson($absolutePath, $exportData);

        return $relativePath;
    }

    /**
     * @param array<string, mixed> $ingestData
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
        foreach (['uploads', 'pages', 'intermediate', 'exports', 'ingested', 'status'] as $dir) {
            File::ensureDirectoryExists($this->basePath.'/'.$dir);
        }
    }

    private function statusPath(string $documentId): string
    {
        return $this->basePath.'/status/'.$documentId.'.json';
    }

    private function intermediatePath(string $documentId): string
    {
        return $this->basePath.'/intermediate/'.$documentId.'.review.json';
    }

    /**
     * Exclusive-lock the file at $path, read its JSON content (or [] if empty),
     * pass the data by reference to $callback for in-place modification, then
     * write it back atomically before releasing the lock.
     *
     * All read-modify-write operations on state files must go through this method
     * to prevent concurrent queue workers from corrupting the same document.
     *
     * @param callable(array<string,mixed> &): void $callback
     */
    private function withLockedFile(string $path, callable $callback): void
    {
        File::ensureDirectoryExists(dirname($path));

        $fp = fopen($path, 'c+');
        if ($fp === false) {
            throw new RuntimeException("Cannot open file for locked write: {$path}");
        }

        try {
            flock($fp, LOCK_EX);

            $size = fstat($fp)['size'] ?? 0;
            $contents = $size > 0 ? stream_get_contents($fp) : '';
            /** @var array<string, mixed> $data */
            $data = ($contents !== false && $contents !== '')
                ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR)
                : [];

            $callback($data);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Write a complete document to disk in one atomic operation.
     * Used when we already have the full document in memory and only need to persist it.
     *
     * @param array<string, mixed> $data
     */
    private function atomicWrite(string $path, array $data): void
    {
        $tmp = $path.'.tmp.'.getmypid();
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($tmp, $path);
    }

    /**
     * @param array<string, mixed> $document
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
     * @param array<string, mixed> $document
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
     * Mark the document's generated HTML as out of sync with the current block states.
     * The HTML will be lazily rebuilt the next time getReviewDocument is called.
     *
     * @param array<string, mixed> $document
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
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $document
     */
    private function syncDocumentReview(array &$document): void
    {
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

    private function normalizeHtmlForCompare(string $html): string
    {
        return preg_replace('/\s+/u', ' ', trim($html)) ?? trim($html);
    }

    /**
     * @param mixed $bbox
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
     * @param array<string, mixed> $block
     * @param array<string, mixed>|null $table
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $existingMeta
     */
    private function rebuildBlockHtml(array $block, ?array $table, array $layout, array $existingMeta): string
    {
        $type = (string) ($block['type'] ?? 'paragraph');
        $text = (string) ($block['approved_text'] ?? $block['ai_suggested_text'] ?? $block['normalized_text'] ?? '');
        $layoutCss = trim((string) ($existingMeta['layout_css'] ?? ''));

        if ($type !== 'table' && $type !== 'image' && $layoutCss !== '') {
            $classMap = [
                'list_item'      => 'doc-paragraph doc-list-item',
                'title'          => 'doc-paragraph doc-title',
                'section_header' => 'doc-paragraph doc-section-header',
                'figure_caption' => 'doc-paragraph doc-figure-caption',
                'footnote'       => 'doc-paragraph doc-footnote',
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
