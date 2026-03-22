<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

class DocumentHtmlService
{
    public function buildGeneratedHtml(array $document): string
    {
        $pages = is_array($document['pages'] ?? null) ? $document['pages'] : [];
        $sections = [];

        foreach ($pages as $page) {
            $pageNo = (int) ($page['page_no'] ?? 1);
            $blocks = is_array($page['blocks'] ?? null) ? $page['blocks'] : [];

            usort($blocks, function (array $left, array $right): int {
                $leftOrder = (int) ($left['reading_order'] ?? 0);
                $rightOrder = (int) ($right['reading_order'] ?? 0);

                if ($leftOrder === $rightOrder) {
                    return strcmp((string) ($left['block_id'] ?? ''), (string) ($right['block_id'] ?? ''));
                }

                return $leftOrder <=> $rightOrder;
            });

            $blockHtml = [];
            foreach ($blocks as $block) {
                $blockId = (string) ($block['block_id'] ?? '');
                if ($blockId === '') {
                    continue;
                }

                $type = (string) ($block['type'] ?? 'paragraph');
                $readingOrder = (int) ($block['reading_order'] ?? 0);
                $contentHtml = $this->buildBlockHtml($block);

                $blockHtml[] = sprintf(
                    '<div class="doc-block doc-block-%s" data-block-id="%s" data-block-type="%s" data-page-no="%d" data-reading-order="%d">%s</div>',
                    e($type),
                    e($blockId),
                    e($type),
                    $pageNo,
                    $readingOrder,
                    $contentHtml,
                );
            }

            $sections[] = sprintf(
                '<section class="doc-page" data-page-no="%d"><header class="doc-page-marker">Page %d</header>%s</section>',
                $pageNo,
                $pageNo,
                implode('', $blockHtml),
            );
        }

        return '<article class="doc-review-document">'.implode('', $sections).'</article>';
    }

    public function buildBlockHtml(array $block): string
    {
        $type = (string) ($block['type'] ?? 'paragraph');
        $blockId = (string) ($block['block_id'] ?? '');
        $table = $this->normalizeTablePayload($block['meta']['table'] ?? null);
        $html = trim((string) ($block['meta']['reviewed_html'] ?? ''));

        if ($html !== '') {
            return $html;
        }

        if ($type === 'table' && $table !== null) {
            $tableHtml = (string) ($table['html'] ?? '');
            if ($blockId !== '') {
                return preg_replace('/<table/i', '<table data-block-id="'.e($blockId).'"', $tableHtml, 1) ?? $tableHtml;
            }
            return $tableHtml;
        }

        $text = trim((string) ($block['approved_text'] ?? $block['ai_suggested_text'] ?? $block['normalized_text'] ?? ''));
        $layout = is_array($block['meta']['layout'] ?? null) ? $block['meta']['layout'] : [];
        $tag = 'p';

        $classNames = ['doc-paragraph'];
        if ($type === 'list_item') {
            $classNames[] = 'doc-list-item';
        }
        // Force all types to use doc-paragraph styling, but keep semantic classes for reference
        if ($type === 'title') {
            $classNames[] = 'doc-title';
        }
        if ($type === 'section_header') {
            $classNames[] = 'doc-section-header';
        }
        if ($type === 'figure_caption') {
            $classNames[] = 'doc-figure-caption';
        }
        if ($type === 'footnote') {
            $classNames[] = 'doc-footnote';
        }

        $attributes = '';
        if ($blockId !== '') {
            $attributes .= ' data-block-id="'.e($blockId).'"';
        }

        return sprintf(
            '<%1$s%5$s class="%2$s"%3$s>%4$s</%1$s>',
            $tag,
            e(implode(' ', $classNames)),
            $this->buildLayoutStyleAttribute($layout),
            $this->renderTextWithLayout($text, $layout),
            $attributes,
        );
    }

    /**
     * @return array{document_title: string|null, chunks: array<int, array<string, mixed>>}
     */
    public function buildChunksFromHtml(string $documentId, string $html): array
    {
        $dom = $this->loadHtmlFragment($html);
        $xpath = new DOMXPath($dom);
        $wrappers = $xpath->query('//*[@data-block-id]');

        $chunks = [];
        $counter = 1;
        $title = null;

        if ($wrappers !== false) {
            foreach ($wrappers as $wrapper) {
                if (! $wrapper instanceof DOMElement) {
                    continue;
                }

                $chunk = $this->chunkFromWrapper($documentId, $counter, $wrapper);
                if ($chunk === null) {
                    continue;
                }

                $counter++;
                if ($title === null && in_array($chunk['meta']['type'], ['title', 'section_header'], true)) {
                    $title = $chunk['text'];
                }

                $chunks[] = $chunk;
            }
        }

        return [
            'document_title' => $title,
            'chunks' => $chunks,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function normalizeTablePayload(mixed $table): ?array
    {
        if (! is_array($table)) {
            return null;
        }

        $cells = [];
        foreach (($table['cells'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalizedRow = [];
            foreach ($row as $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                $normalizedRow[] = [
                    'text' => (string) ($cell['text'] ?? ''),
                    'colspan' => max(1, (int) ($cell['colspan'] ?? 1)),
                    'rowspan' => max(1, (int) ($cell['rowspan'] ?? 1)),
                    'alignment' => $cell['alignment'] ?? null,
                ];
            }
            if ($normalizedRow !== []) {
                $cells[] = $normalizedRow;
            }
        }

        $headers = array_values(array_map('strval', is_array($table['headers'] ?? null) ? $table['headers'] : []));
        $rows = [];
        foreach ((is_array($table['rows'] ?? null) ? $table['rows'] : []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = array_values(array_map('strval', $row));
        }

        if ($cells === []) {
            $legacyRows = [];
            if ($headers !== []) {
                $legacyRows[] = array_map(fn (string $text): array => [
                    'text' => $text,
                    'colspan' => 1,
                    'rowspan' => 1,
                    'alignment' => null,
                ], $headers);
            }
            foreach ($rows as $row) {
                $legacyRows[] = array_map(fn (string $text): array => [
                    'text' => $text,
                    'colspan' => 1,
                    'rowspan' => 1,
                    'alignment' => null,
                ], $row);
            }
            $cells = $legacyRows;
        }

        if ($cells === [] && is_string($table['html'] ?? null) && trim((string) $table['html']) !== '') {
            $dom = $this->loadHtmlFragment((string) $table['html']);
            $parsed = $this->extractTableData($dom->documentElement ?? $dom);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        if ($cells === []) {
            return null;
        }

        $html = is_string($table['html'] ?? null) && trim((string) $table['html']) !== ''
            ? trim((string) $table['html'])
            : $this->buildTableHtml($cells);

        return [
            'headers' => $headers !== [] ? $headers : $this->flattenRow($cells[0]),
            'rows' => $rows !== [] ? $rows : array_map(fn (array $row): array => $this->flattenRow($row), array_slice($cells, 1)),
            'cells' => $cells,
            'html' => $html,
        ];
    }

    private function loadHtmlFragment(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $internalErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        return $dom;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chunkFromWrapper(string $documentId, int $counter, DOMElement $wrapper): ?array
    {
        $html = $this->innerHtml($wrapper);
        $text = $this->extractText($html);
        if ($text === '') {
            return null;
        }

        $type = $wrapper->getAttribute('data-block-type') ?: 'paragraph';
        $pageNo = $wrapper->getAttribute('data-page-no');
        $readingOrder = $wrapper->getAttribute('data-reading-order');
        $blockId = $wrapper->getAttribute('data-block-id');

        return [
            'chunk_id' => sprintf('%s_chunk_%04d', $documentId, $counter),
            'page_no' => $pageNo !== '' ? (int) $pageNo : null,
            'block_ids' => $blockId !== '' ? [$blockId] : [],
            'section_path' => null,
            'text' => $text,
            'meta' => [
                'type' => $type,
                'html' => $html,
                'layout' => [
                    'bbox' => null,
                    'reading_order' => $readingOrder !== '' ? (int) $readingOrder : null,
                ],
                'table' => $type === 'table' ? $this->extractTableData($wrapper) : null,
            ],
        ];
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return trim($html);
    }

    private function extractText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractTableData(DOMNode $node): ?array
    {
        $table = $node instanceof DOMElement && strtolower($node->tagName) === 'table'
            ? $node
            : $this->findFirstDescendantTable($node);

        if (! $table instanceof DOMElement) {
            return null;
        }

        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $row) {
            $rows[] = $this->extractHtmlTableRow($row);
        }

        if ($rows === []) {
            return null;
        }

        return [
            'headers' => $this->flattenRow($rows[0]),
            'rows' => array_map(fn (array $row): array => $this->flattenRow($row), array_slice($rows, 1)),
            'cells' => $rows,
            'html' => trim($table->ownerDocument?->saveHTML($table) ?? ''),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractHtmlTableRow(DOMElement $row): array
    {
        $cells = [];
        foreach ($row->childNodes as $child) {
            if (! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), ['th', 'td'], true)) {
                continue;
            }
            $cells[] = [
                'text' => trim($child->textContent),
                'colspan' => max(1, (int) ($child->getAttribute('colspan') ?: 1)),
                'rowspan' => max(1, (int) ($child->getAttribute('rowspan') ?: 1)),
                'alignment' => $this->extractInlineTextAlign($child),
            ];
        }

        return $cells;
    }

    private function extractInlineTextAlign(DOMElement $element): ?string
    {
        $style = $element->getAttribute('style');
        if ($style === '') {
            return null;
        }

        if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $style, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    private function findFirstDescendantTable(DOMNode $node): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'table') {
                return $child;
            }

            $table = $this->findFirstDescendantTable($child);
            if ($table instanceof DOMElement) {
                return $table;
            }
        }

        return null;
    }

    private function buildLayoutStyleAttribute(array $layout): string
    {
        $styles = [];
        $alignment = $layout['alignment'] ?? null;
        $indentLeft = $layout['indent_left'] ?? null;
        $indentFirstLine = $layout['indent_first_line'] ?? null;
        $indentHanging = $layout['indent_hanging'] ?? null;

        if (is_string($alignment) && $alignment !== '') {
            $styles[] = 'text-align:'.$alignment;
        }
        if (is_numeric($indentLeft)) {
            $styles[] = 'margin-left:'.((float) $indentLeft / 20).'pt';
        }
        if (is_numeric($indentFirstLine)) {
            $styles[] = 'text-indent:'.((float) $indentFirstLine / 20).'pt';
        } elseif (is_numeric($indentHanging)) {
            $styles[] = 'text-indent:-'.((float) $indentHanging / 20).'pt';
        }

        return $styles === [] ? '' : ' style="'.e(implode('; ', $styles)).'"';
    }

    private function renderTextWithLayout(string $text, array $layout): string
    {
        if (! str_contains($text, "\t")) {
            return nl2br(e($text), false);
        }

        $tabs = [];
        foreach (($layout['tabs'] ?? []) as $tab) {
            if (! is_array($tab) || ! is_numeric($tab['position'] ?? null)) {
                continue;
            }
            $tabs[] = max(((float) $tab['position']) / 20, 48.0);
        }

        $parts = explode("\t", $text);
        $html = '';
        foreach ($parts as $index => $part) {
            $html .= nl2br(e($part), false);
            if ($index >= count($parts) - 1) {
                continue;
            }
            $width = $tabs[$index] ?? 48.0;
            $html .= '<span class="doc-tab" style="display:inline-block; width:'.$width.'pt;"></span>';
        }

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $row
     * @return array<int, string>
     */
    private function flattenRow(array $row): array
    {
        $flattened = [];
        foreach ($row as $cell) {
            $flattened[] = (string) ($cell['text'] ?? '');
        }

        return $flattened;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rows
     */
    private function buildTableHtml(array $rows): string
    {
        $htmlRows = '';
        foreach ($rows as $rowIndex => $row) {
            $tag = $rowIndex === 0 ? 'th' : 'td';
            $htmlRows .= '<tr>';
            foreach ($row as $cell) {
                $attributes = '';
                $colspan = max(1, (int) ($cell['colspan'] ?? 1));
                $rowspan = max(1, (int) ($cell['rowspan'] ?? 1));
                if ($colspan > 1) {
                    $attributes .= ' colspan="'.$colspan.'"';
                }
                if ($rowspan > 1) {
                    $attributes .= ' rowspan="'.$rowspan.'"';
                }
                $alignment = $cell['alignment'] ?? null;
                if (is_string($alignment) && $alignment !== '') {
                    $attributes .= ' style="text-align:'.e($alignment).';"';
                }
                $htmlRows .= '<'.$tag.$attributes.'>'.nl2br(e((string) ($cell['text'] ?? '')), false).'</'.$tag.'>';
            }
            $htmlRows .= '</tr>';
        }

        return '<table><tbody>'.$htmlRows.'</tbody></table>';
    }
}