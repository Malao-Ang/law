<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Cell as CellStyle;
use PhpOffice\PhpWord\Style\Tab;
use RuntimeException;

class DocumentExportService
{
    private const EXPORT_FONT_STACK = "'TH Sarabun PSK', 'TH Sarabun New', 'Sarabun', 'Noto Sans Thai', sans-serif";

    public function __construct(
        private readonly DocumentHtmlService $documentHtmlService,
    ) {}

    public function buildHtml(array $document): string
    {
        $blocks = [];

        foreach ($this->orderedBlocks($document) as $block) {
            $blockId = $this->escapeHtml((string) ($block['block_id'] ?? ''));
            $layout = is_array($block['meta']['layout'] ?? null) ? $block['meta']['layout'] : [];
            $blocks[] = sprintf(
                '<div class="block" data-block-id="%s"%s>%s</div>',
                $blockId,
                $this->documentHtmlService->buildLayoutStyleAttribute($layout),
                $this->renderBlockHtml($block),
            );
        }

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { size: A4; margin: 2.54cm 3.17cm; }
  @font-face {
    font-family: "TH Sarabun PSK";
    src: local("TH Sarabun PSK"), local("THSarabunPSK"), local("TH Sarabun New"), local("THSarabunNew"), local("Sarabun");
  }
  body { margin: 0; padding: 0; font-family: '.self::EXPORT_FONT_STACK.'; }
  * { box-sizing: border-box; }
  h1 { font-size: 20px; font-weight: 700; margin: 16px 0 8px; }
  h2 { font-size: 17px; font-weight: 700; margin: 14px 0 7px; }
  h3 { font-size: 15px; font-weight: 700; margin: 12px 0 6px; }
  p { margin: 0 0 8px; }
  .block { font-family: '.self::EXPORT_FONT_STACK.'; }
  table { width: 100%; border-collapse: collapse; }
  th, td { vertical-align: top; }
</style>
</head>
<body>'.implode('', $blocks).'</body>
</html>';
    }

    public function toPdf(array $document): string
    {
        $endpoint = rtrim((string) config('services.pdf.base_url', 'http://pdf-service:3001'), '/').'/render';

        try {
            $response = Http::timeout(120)
                ->accept('application/pdf')
                ->post($endpoint, ['html' => $this->buildHtml($document)]);
        } catch (ConnectionException) {
            throw new RuntimeException('PDF service unavailable');
        }

        if (! $response->successful()) {
            throw new RuntimeException('PDF rendering failed');
        }

        return $response->body();
    }

    public function toDocx(array $document): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'marginTop' => 1440,
            'marginBottom' => 1440,
            'marginLeft' => 1800,
            'marginRight' => 1800,
        ]);

        foreach ($this->orderedBlocks($document) as $block) {
            $table = $this->normalizeTable($block);
            $isTable = (string) ($block['type'] ?? '') === 'table' && $table !== null && ($table['cells'] ?? []) !== [];

            if ($isTable) {
                $this->appendTable($section, $table);
                continue;
            }

            $html = $this->blockHtmlOrFallback($block);
            $runs = $this->parseHtmlRuns($html);
            if ($runs === []) {
                continue;
            }

            $textRun = $section->addTextRun($this->paragraphStyleForBlock($block));

            foreach ($runs as $run) {
                $parts = explode("\n", (string) ($run['text'] ?? ''));
                foreach ($parts as $index => $part) {
                    if ($part !== '') {
                        $textRun->addText($part, $this->fontStyleForRun($run));
                    }
                    if ($index < count($parts) - 1) {
                        $textRun->addTextBreak();
                    }
                }
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'esign_docx_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to create temporary DOCX file.');
        }

        $docxPath = $tempPath.'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);
        $content = (string) file_get_contents($docxPath);

        @unlink($tempPath);
        @unlink($docxPath);

        return $content;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseHtmlRuns(string $html): array
    {
        $dom = $this->loadHtmlFragment($html);
        $root = $dom->getElementsByTagName('div')->item(0);
        if (! $root instanceof DOMElement) {
            return [];
        }

        $runs = [];
        foreach ($root->childNodes as $child) {
            $this->collectRuns($child, [
                'bold' => false,
                'italic' => false,
                'underline' => false,
                'fontFamily' => null,
                'fontSize' => null,
            ], $runs);
        }

        return $this->mergeRuns($runs);
    }

    public function safeFilenameBase(array $document): string
    {
        $sourceFile = trim((string) ($document['source_file'] ?? ''));
        $lawMeta = is_array($document['law_meta'] ?? null) ? $document['law_meta'] : [];
        $lawTitle = trim((string) ($lawMeta['title'] ?? ''));

        $rawTitle = $sourceFile !== '' ? $sourceFile : ($lawTitle !== '' ? $lawTitle : 'document');

        // Strip filesystem-illegal chars (including path separators) before extension removal
        $cleaned = (string) preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/u', '', $rawTitle);
        $baseName = pathinfo($cleaned, PATHINFO_FILENAME) ?: 'document';

        // Collapse runs of whitespace to one space
        $safeName = (string) preg_replace('/\s+/u', ' ', $baseName);
        $safeName = trim($safeName);

        return $safeName !== '' ? $safeName : 'document';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderedBlocks(array $document): array
    {
        $ordered = [];

        foreach ((array) ($document['pages'] ?? []) as $page) {
            $blocks = is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            usort($blocks, fn (array $left, array $right): int => ((int) ($left['reading_order'] ?? 0) <=> (int) ($right['reading_order'] ?? 0))
                ?: strcmp((string) ($left['block_id'] ?? ''), (string) ($right['block_id'] ?? '')));
            array_push($ordered, ...$blocks);
        }

        return $ordered;
    }

    private function renderBlockHtml(array $block): string
    {
        $table = $this->normalizeTable($block);
        if ((string) ($block['type'] ?? '') === 'table' && $table !== null && ($table['cells'] ?? []) !== []) {
            $html = trim((string) ($table['html'] ?? ''));
            if ($html !== '') {
                return $html;
            }

            return $this->buildTableHtml($table);
        }

        return $this->blockHtmlOrFallback($block);
    }

    private function blockHtmlOrFallback(array $block): string
    {
        $reviewedHtml = trim((string) ($block['meta']['reviewed_html'] ?? ''));
        if ($reviewedHtml !== '') {
            return $reviewedHtml;
        }

        $text = (string) ($block['approved_text'] ?? $block['normalized_text'] ?? $block['raw_text'] ?? '');

        return '<p>'.$this->escapeHtmlWithBreaks($text).'</p>';
    }

    private function normalizeTable(array $block): ?array
    {
        return $this->documentHtmlService->normalizeTablePayload($block['meta']['table'] ?? null);
    }

    private function buildTableHtml(array $table): string
    {
        $rows = [];

        foreach ((array) ($table['cells'] ?? []) as $row) {
            $cells = [];
            foreach ((array) $row as $cell) {
                $text = $this->escapeHtml((string) ($cell['text'] ?? ''));
                $colspan = max(1, (int) ($cell['colspan'] ?? 1));
                $rowspan = max(1, (int) ($cell['rowspan'] ?? 1));
                $alignment = (string) ($cell['alignment'] ?? '');
                $style = $alignment !== '' ? ' style="text-align:'.$this->escapeHtml($alignment).';"' : '';
                $attrs = '';
                if ($colspan > 1) {
                    $attrs .= ' colspan="'.$colspan.'"';
                }
                if ($rowspan > 1) {
                    $attrs .= ' rowspan="'.$rowspan.'"';
                }

                $cells[] = sprintf('<td%s%s>%s</td>', $attrs, $style, nl2br($text));
            }

            $rows[] = '<tr>'.implode('', $cells).'</tr>';
        }

        return '<table><tbody>'.implode('', $rows).'</tbody></table>';
    }

    private function appendTable(object $section, array $tableData): void
    {
        $table = $section->addTable();
        $rows = (array) ($tableData['cells'] ?? []);
        $totalColumns = 0;

        foreach ($rows as $row) {
            $columnCount = 0;
            foreach ((array) $row as $cell) {
                $columnCount += max(1, (int) ($cell['colspan'] ?? 1));
            }
            $totalColumns = max($totalColumns, $columnCount);
        }

        $pendingMerges = [];

        foreach ($rows as $rowCells) {
            $row = $table->addRow();
            $columnIndex = 0;

            foreach ((array) $rowCells as $cellData) {
                while (($pendingMerges[$columnIndex] ?? 0) > 0) {
                    $row->addCell(null, ['vMerge' => CellStyle::VMERGE_CONTINUE]);
                    $pendingMerges[$columnIndex]--;
                    $columnIndex++;
                }

                $colspan = max(1, (int) ($cellData['colspan'] ?? 1));
                $rowspan = max(1, (int) ($cellData['rowspan'] ?? 1));
                $style = [];

                if ($colspan > 1) {
                    $style['gridSpan'] = $colspan;
                }
                if ($rowspan > 1) {
                    $style['vMerge'] = CellStyle::VMERGE_RESTART;
                }

                $cell = $row->addCell(null, $style);
                $cell->addText(
                    (string) ($cellData['text'] ?? ''),
                    [],
                    [
                        'alignment' => $this->mapAlignment((string) ($cellData['alignment'] ?? '')) ?? Jc::LEFT,
                        'spaceAfter' => 0,
                    ],
                );

                if ($rowspan > 1) {
                    for ($offset = 0; $offset < $colspan; $offset++) {
                        $pendingMerges[$columnIndex + $offset] = $rowspan - 1;
                    }
                }

                $columnIndex += $colspan;
            }

            while ($columnIndex < $totalColumns) {
                if (($pendingMerges[$columnIndex] ?? 0) > 0) {
                    $row->addCell(null, ['vMerge' => CellStyle::VMERGE_CONTINUE]);
                    $pendingMerges[$columnIndex]--;
                } else {
                    $row->addCell();
                }
                $columnIndex++;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraphStyleForBlock(array $block): array
    {
        $layout = is_array($block['meta']['layout'] ?? null) ? $block['meta']['layout'] : [];
        $style = [
            'spaceAfter' => 0,
        ];

        $alignment = $this->mapAlignment((string) ($layout['alignment'] ?? ''));
        if ($alignment !== null) {
            $style['alignment'] = $alignment;
        }

        $indentation = [];
        foreach ([
            'left' => 'indent_left',
            'firstLine' => 'indent_first_line',
            'hanging' => 'indent_hanging',
        ] as $target => $source) {
            if (isset($layout[$source]) && is_numeric($layout[$source])) {
                $indentation[$target] = (int) $layout[$source];
            }
        }
        if ($indentation !== []) {
            $style['indentation'] = $indentation;
        }

        $tabs = [];
        foreach ((array) ($layout['tabs'] ?? []) as $tab) {
            if (! is_array($tab) || ! is_numeric($tab['position'] ?? null)) {
                continue;
            }

            $tabs[] = new Tab(
                $this->mapTabType((string) ($tab['type'] ?? 'left')),
                (int) $tab['position'],
            );
        }
        if ($tabs !== []) {
            $style['tabs'] = $tabs;
        }

        return $style;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function fontStyleForRun(array $run): array
    {
        $style = [
            'bold' => (bool) ($run['bold'] ?? false),
            'italic' => (bool) ($run['italic'] ?? false),
            'underline' => (bool) ($run['underline'] ?? false) ? 'single' : null,
        ];

        $fontFamily = trim((string) ($run['fontFamily'] ?? ''));
        if ($fontFamily !== '') {
            $style['name'] = $fontFamily;
        }

        $fontSize = $this->toPointSize($run['fontSize'] ?? null);
        if ($fontSize !== null) {
            $style['size'] = $fontSize;
        }

        return array_filter($style, static fn (mixed $value): bool => $value !== null && $value !== false);
    }

    private function collectRuns(DOMNode $node, array $context, array &$runs): void
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->nodeValue ?? '';
            if ($text !== '') {
                $runs[] = [
                    'text' => $text,
                    ...$context,
                ];
            }

            return;
        }

        if (! $node instanceof DOMElement) {
            return;
        }

        if (strtolower($node->tagName) === 'br') {
            $runs[] = [
                'text' => "\n",
                ...$context,
            ];

            return;
        }

        $next = $context;
        $tag = strtolower($node->tagName);

        if (in_array($tag, ['strong', 'b', 'h1', 'h2', 'h3'], true)) {
            $next['bold'] = true;
        }
        if (in_array($tag, ['em', 'i'], true)) {
            $next['italic'] = true;
        }
        if ($tag === 'u') {
            $next['underline'] = true;
        }
        if (in_array($tag, ['h1', 'h2', 'h3'], true) && $next['fontSize'] === null) {
            $next['fontSize'] = match ($tag) {
                'h1' => '20px',
                'h2' => '17px',
                default => '15px',
            };
        }

        foreach ($this->parseInlineStyle((string) $node->getAttribute('style')) as $property => $value) {
            if ($property === 'font-family' && $value !== '') {
                $next['fontFamily'] = trim($value, '\'"');
            }
            if ($property === 'font-size' && $value !== '') {
                $next['fontSize'] = $value;
            }
            if ($property === 'font-weight' && ($value === 'bold' || (is_numeric($value) && (int) $value >= 600))) {
                $next['bold'] = true;
            }
            if ($property === 'font-style' && $value === 'italic') {
                $next['italic'] = true;
            }
            if ($property === 'text-decoration' && str_contains($value, 'underline')) {
                $next['underline'] = true;
            }
        }

        foreach ($node->childNodes as $child) {
            $this->collectRuns($child, $next, $runs);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $runs
     * @return array<int, array<string, mixed>>
     */
    private function mergeRuns(array $runs): array
    {
        $merged = [];

        foreach ($runs as $run) {
            if ($run['text'] === '') {
                continue;
            }

            $last = $merged[count($merged) - 1] ?? null;
            if ($last !== null
                && ($last['bold'] ?? false) === ($run['bold'] ?? false)
                && ($last['italic'] ?? false) === ($run['italic'] ?? false)
                && ($last['underline'] ?? false) === ($run['underline'] ?? false)
                && ($last['fontFamily'] ?? null) === ($run['fontFamily'] ?? null)
                && ($last['fontSize'] ?? null) === ($run['fontSize'] ?? null)) {
                $merged[count($merged) - 1]['text'] .= $run['text'];
                continue;
            }

            $merged[] = $run;
        }

        return $merged;
    }

    /**
     * @return array<string, string>
     */
    private function parseInlineStyle(string $style): array
    {
        $parsed = [];

        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);
            if ($property === null || $value === null) {
                continue;
            }

            $property = strtolower(trim($property));
            $value = strtolower(trim($value));

            if ($property !== '' && $value !== '') {
                $parsed[$property] = $value;
            }
        }

        return $parsed;
    }

    private function toPointSize(mixed $size): ?float
    {
        if (! is_string($size) && ! is_numeric($size)) {
            return null;
        }

        $raw = strtolower(trim((string) $size));
        if ($raw === '') {
            return null;
        }

        if (str_ends_with($raw, 'pt')) {
            return (float) substr($raw, 0, -2);
        }

        if (str_ends_with($raw, 'px')) {
            return ((float) substr($raw, 0, -2)) * 0.75;
        }

        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return null;
    }

    private function mapAlignment(string $alignment): ?string
    {
        return match ($alignment) {
            'left' => Jc::LEFT,
            'center' => Jc::CENTER,
            'right' => Jc::RIGHT,
            'justify' => Jc::BOTH,
            default => null,
        };
    }

    private function mapTabType(string $type): string
    {
        return match ($type) {
            'center' => Tab::TAB_STOP_CENTER,
            'right' => Tab::TAB_STOP_RIGHT,
            'decimal' => Tab::TAB_STOP_DECIMAL,
            default => Tab::TAB_STOP_LEFT,
        };
    }

    private function loadHtmlFragment(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML(
            '<?xml encoding="utf-8" ?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        return $dom;
    }

    private function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeHtmlWithBreaks(string $text): string
    {
        return nl2br($this->escapeHtml($text));
    }
}
