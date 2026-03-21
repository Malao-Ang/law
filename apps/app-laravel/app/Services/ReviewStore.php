<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ReviewStore
{
    private string $basePath;

    public function __construct()
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
        $current = $this->getStatus($documentId) ?? ['document_id' => $documentId];
        $payload = array_merge($current, $status, ['updated_at' => now()->toIso8601String()]);
        $this->writeJson($this->statusPath($documentId), $payload);
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
        $this->writeJson($this->intermediatePath($documentId), $document);
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

        return $this->readJson($path);
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function patchApprovedBlock(string $documentId, int $pageNo, string $blockId, array $patch): array
    {
        $document = $this->getReviewDocument($documentId);
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

        $table = $this->normalizeTable($patch['table'] ?? $block['meta']['table'] ?? null);
        $reviewedHtml = trim((string) ($patch['reviewed_html'] ?? ''));
        if ($reviewedHtml === '') {
            $reviewedHtml = $this->buildReviewedHtml($block, $table);
        }

        $block['meta'] = array_merge($block['meta'] ?? [], [
            'reviewed_html' => $reviewedHtml,
            'layout' => [
                'bbox' => $block['bbox'],
                'reading_order' => $block['reading_order'],
            ],
            'table' => $table,
            'review' => [
                'approved_by' => $patch['approved_by'] ?? null,
                'notes' => $patch['notes'] ?? null,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);

        $this->recalculateSummary($document);
        $this->writeReviewDocument($documentId, $document);

        return $block;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function applyReprocessResult(string $documentId, array $result): array
    {
        $document = $this->getReviewDocument($documentId);
        $pageNo = (int) ($result['page_no'] ?? 0);
        $blockId = (string) ($result['block_id'] ?? '');
        $block = &$this->findBlockReference($document, $pageNo, $blockId);

        $block['ai_suggested_text'] = (string) ($result['ai_suggested_text'] ?? $block['ai_suggested_text'] ?? '');
        $block['confidence'] = max(0.0, min(1.0, (float) ($result['confidence'] ?? $block['confidence'] ?? 0.0)));
        $block['flags'] = array_values(array_unique(array_map('strval', $result['flags'] ?? $block['flags'] ?? [])));
        $block['needs_review'] = true;
        $block['meta'] = array_merge($block['meta'] ?? [], [
            'reviewed_html' => $this->buildReviewedHtml($block, $this->normalizeTable($block['meta']['table'] ?? null)),
        ]);

        $this->recalculateSummary($document);
        $this->writeReviewDocument($documentId, $document);

        return $block;
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

    public function reviewRelativePath(string $documentId): string
    {
        return sprintf('intermediate/%s.review.json', $documentId);
    }

    public function exportRelativePath(string $documentId): string
    {
        return sprintf('exports/%s.rag.json', $documentId);
    }

    private function ensureDirectories(): void
    {
        foreach (['uploads', 'pages', 'intermediate', 'exports', 'status'] as $dir) {
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
     * @param mixed $table
     * @return array<string, mixed>|null
     */
    private function normalizeTable(mixed $table): ?array
    {
        if (! is_array($table)) {
            return null;
        }

        $headers = array_values(array_map('strval', is_array($table['headers'] ?? null) ? $table['headers'] : []));
        $rows = [];
        foreach ((is_array($table['rows'] ?? null) ? $table['rows'] : []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = array_values(array_map('strval', $row));
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'html' => $this->buildTableHtml($headers, $rows),
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed>|null $table
     */
    private function buildReviewedHtml(array $block, ?array $table): string
    {
        if (($block['type'] ?? 'paragraph') === 'table' && $table !== null) {
            return (string) ($table['html'] ?? '');
        }

        $text = trim((string) ($block['approved_text'] ?? ''));
        $safeText = e($text === '' ? (string) ($block['ai_suggested_text'] ?? '') : $text);
        $tag = match ((string) ($block['type'] ?? 'paragraph')) {
            'title' => 'h1',
            'section_header' => 'h2',
            'list_item' => 'li',
            'figure_caption' => 'figcaption',
            'footnote' => 'aside',
            default => 'p',
        };

        return sprintf('<%1$s>%2$s</%1$s>', $tag, nl2br($safeText, false));
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string>> $rows
     */
    private function buildTableHtml(array $headers, array $rows): string
    {
        $headerCells = '';
        foreach ($headers as $header) {
            $headerCells .= '<th>'.e($header).'</th>';
        }

        $bodyRows = '';
        foreach ($rows as $row) {
            $bodyRows .= '<tr>';
            foreach ($row as $cell) {
                $bodyRows .= '<td>'.e($cell).'</td>';
            }
            $bodyRows .= '</tr>';
        }

        $thead = $headerCells !== '' ? '<thead><tr>'.$headerCells.'</tr></thead>' : '';
        $tbody = '<tbody>'.$bodyRows.'</tbody>';

        return '<table>'.$thead.$tbody.'</table>';
    }
}
