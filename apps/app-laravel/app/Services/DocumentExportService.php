<?php

namespace App\Services;

use App\Services\Fast\LibreOfficeConverter;
use DOMDocument;
use DOMElement;
use DOMNode;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Tab;
use RuntimeException;

class DocumentExportService
{
    // Must match the editor's primary face (DocumentEditorShell .ProseMirror).
    // Only "TH Sarabun New" is actually installed in the LibreOffice container;
    // "TH Sarabun PSK" is not, so declaring it first makes fontconfig substitute
    // Google "Sarabun" — a different font with different metrics than the review.
    private const EXPORT_FONT = 'TH Sarabun New';

    // Matches the review editor's on-screen line-height (DocumentEditorShell 1.85).
    private const DEFAULT_LINE_HEIGHT = 1.85;

    private const EXPORT_FONT_STACK = "'TH Sarabun New', 'TH Sarabun PSK', 'Sarabun', 'Noto Sans Thai', sans-serif";

    private const DEFAULT_PAGE_MARGINS = [
        'top' => 1440,
        'bottom' => 1440,
        'left' => 1800,
        'right' => 1800,
    ];

    public function __construct(
        private readonly DocumentHtmlService $documentHtmlService,
        private readonly LibreOfficeConverter $libreOffice,
        private readonly ReviewStore $reviewStore,
    ) {}

    public function buildHtml(array $document): string
    {
        $blocks = [];
        $pageMargins = $this->pageMarginsFromDocument($document);
        $pageMarginCss = sprintf(
            '%smm %smm %smm %smm',
            $this->formatMillimeters($this->twipsToMillimeters($pageMargins['top'])),
            $this->formatMillimeters($this->twipsToMillimeters($pageMargins['right'])),
            $this->formatMillimeters($this->twipsToMillimeters($pageMargins['bottom'])),
            $this->formatMillimeters($this->twipsToMillimeters($pageMargins['left'])),
        );

        foreach ($this->orderedBlocks($document) as $block) {
            $blockId = $this->escapeHtml((string) ($block['block_id'] ?? ''));
            $type = (string) ($block['type'] ?? '');
            $layout = is_array($block['meta']['layout'] ?? null) ? $block['meta']['layout'] : [];
            $blockClass = $type === 'table' ? 'block block--table' : 'block';
            $blocks[] = sprintf(
                '<div class="%s" data-block-id="%s"%s>%s</div>',
                $blockClass,
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
  @page { size: A4; margin: '.$pageMarginCss.'; }
  @font-face {
    font-family: "TH Sarabun PSK";
    src: local("TH Sarabun PSK"), local("THSarabunPSK"), local("TH Sarabun New"), local("THSarabunNew"), local("Sarabun");
  }
  body { margin: 0; padding: 0; font-family: '.self::EXPORT_FONT_STACK.'; font-size: 16pt; line-height: 1.85; }
  * { box-sizing: border-box; }
  h1 { font-size: 22pt; font-weight: 700; margin: 16pt 0 8pt; page-break-after: avoid; break-after: avoid; }
  h2 { font-size: 18pt; font-weight: 700; margin: 14pt 0 7pt; page-break-after: avoid; break-after: avoid; }
  h3 { font-size: 16pt; font-weight: 700; margin: 12pt 0 6pt; page-break-after: avoid; break-after: avoid; }
  p { margin: 0 0 8px; }
  .block { font-family: '.self::EXPORT_FONT_STACK.'; page-break-inside: avoid; break-inside: avoid; orphans: 3; widows: 3; }
  .block--table { page-break-inside: auto; break-inside: auto; }
  table { width: 100%; border-collapse: collapse; page-break-inside: auto; break-inside: auto; }
  tr { page-break-inside: avoid; break-inside: avoid; }
  th, td { vertical-align: top; border: 1pt solid #000; padding: 4pt 6pt; }
</style>
</head>
<body>'.implode('', $blocks).'</body>
</html>';
    }

    public function toPdf(array $document): string
    {
        $docxPath = $this->buildDocxFile($document);
        $pdfPath = null;
        try {
            $pdfPath = $this->libreOffice->convertToPdf($docxPath);
            $bytes = file_get_contents($pdfPath);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('PDF service unavailable');
            }

            return $bytes;
        } catch (\Throwable $exception) {
            throw new RuntimeException('PDF service unavailable', 0, $exception);
        } finally {
            @unlink($docxPath);
            if ($pdfPath !== null) {
                @unlink($pdfPath);
                @rmdir(dirname($pdfPath));
            }
        }
    }

    public function toDocx(array $document): string
    {
        $docxPath = $this->buildDocxFile($document);
        $content = (string) file_get_contents($docxPath);
        @unlink($docxPath);

        return $content;
    }

    private function buildDocxFile(array $document): string
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName(self::EXPORT_FONT);
        $phpWord->setDefaultFontSize(16);
        $pageMargins = $this->pageMarginsFromDocument($document);
        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'marginTop' => $pageMargins['top'],
            'marginBottom' => $pageMargins['bottom'],
            'marginLeft' => $pageMargins['left'],
            'marginRight' => $pageMargins['right'],
        ]);

        $tempImages = [];

        foreach ($this->orderedExportNodes($document) as $node) {
            if (($node['type'] ?? null) === 'page_break') {
                $section->addPageBreak();

                continue;
            }

            if (($node['type'] ?? null) === 'blank') {
                // A regular space with xml:space="preserve" guarantees the paragraph
                // keeps a full line box in LibreOffice. U+200B (zero-width space) has
                // zero advance width so LibreOffice collapses the line height to zero.
                $blankRun = $section->addTextRun($this->blankParagraphStyle());
                $blankRun->addText(' ', ['name' => self::EXPORT_FONT, 'size' => 16]);

                continue;
            }

            $block = is_array($node['block'] ?? null) ? $node['block'] : [];
            $type = (string) ($block['type'] ?? '');
            $table = $this->normalizeTable($block);
            $isTable = $type === 'table' && $table !== null && ($table['cells'] ?? []) !== [];

            if ($isTable) {
                $this->appendTable($section, $table);

                continue;
            }

            if ($type === 'image' && $this->appendImage($section, $block, $tempImages)) {
                continue;
            }

            // Prefer the element HTML captured from draft_html (reflects live editor edits
            // like font changes) over the stale per-block reviewed_html.
            $draftElementHtml = (string) ($node['draft_html'] ?? '');
            $lineHeight = $this->lineHeightFromHtml($draftElementHtml);
            $html = $draftElementHtml !== '' ? $draftElementHtml : $this->blockHtmlOrFallback($block);
            $runs = $this->parseHtmlRuns($html);
            if ($runs === []) {
                // An emptied block is still a visible blank line in the editor —
                // emit an empty paragraph so the PDF keeps the same vertical rhythm.
                $emptyRun = $section->addTextRun($this->paragraphStyleForBlock($block, $lineHeight));
                $emptyRun->addText(' ', ['name' => self::EXPORT_FONT, 'size' => 16]);

                continue;
            }

            $textRun = $section->addTextRun($this->paragraphStyleForBlock($block, $lineHeight));

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
        @unlink($tempPath);
        IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);

        // Image bytes are embedded during save(); only now is it safe to remove temp files.
        foreach ($tempImages as $tempImage) {
            @unlink($tempImage);
        }

        return $docxPath;
    }

    /**
     * Append an image block to the section. Returns false when the image cannot be
     * resolved so the caller can fall back to text rendering.
     *
     * @param  array<string, mixed>  $block
     * @param  list<string>  $tempImages  collects temp files to unlink after save()
     */
    private function appendImage(object $section, array $block, array &$tempImages): bool
    {
        $imgMeta = is_array($block['meta']['image'] ?? null) ? $block['meta']['image'] : [];
        [$path, $isTemp] = $this->resolveImagePath($imgMeta, is_array($block['meta'] ?? null) ? $block['meta'] : []);
        if ($path === null) {
            return false;
        }

        try {
            $style = [
                'alignment' => $this->mapAlignment((string) ($block['meta']['layout']['alignment'] ?? 'center')) ?? Jc::CENTER,
            ];
            $widthPt = $this->imageWidthPt($imgMeta, $path);
            if ($widthPt !== null) {
                $style['width'] = $widthPt;
            }

            $section->addImage($path, $style);
        } catch (\Throwable) {
            if ($isTemp) {
                @unlink($path);
            }

            return false;
        }

        if ($isTemp) {
            $tempImages[] = $path;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $imgMeta
     * @param  array<string, mixed>  $meta
     * @return array{0: string|null, 1: bool} [absolute path | null, isTempFile]
     */
    private function resolveImagePath(array $imgMeta, array $meta): array
    {
        $dataUri = (string) ($imgMeta['data_uri'] ?? '');
        if (str_starts_with($dataUri, 'data:image/')) {
            $comma = strpos($dataUri, ',');
            if ($comma !== false) {
                $bytes = base64_decode(substr($dataUri, $comma + 1), true);
                if ($bytes !== false && $bytes !== '') {
                    $tmp = tempnam(sys_get_temp_dir(), 'esign_img_');
                    if ($tmp !== false && file_put_contents($tmp, $bytes) !== false) {
                        return [$tmp, true];
                    }
                }
            }
        }

        $srcPath = (string) ($imgMeta['src_path'] ?? $meta['image_path'] ?? '');
        if ($srcPath !== '') {
            $absolute = $this->reviewStore->absolutePath($srcPath);
            if (is_file($absolute)) {
                return [$absolute, false];
            }
        }

        return [null, false];
    }

    /**
     * @param  array<string, mixed>  $imgMeta
     */
    private function imageWidthPt(array $imgMeta, string $path): ?float
    {
        // Clamp to the A4 text column (~6.25in) so wide images do not overflow the page.
        $maxWidthPt = 6.25 * 72;

        $displayPx = (int) ($imgMeta['display_width_px'] ?? 0);
        if ($displayPx > 0) {
            return min($displayPx * 0.75, $maxWidthPt);
        }

        $size = @getimagesize($path);
        if ($size !== false && isset($size[0]) && (int) $size[0] > 0) {
            return min(((int) $size[0]) * 0.75, $maxWidthPt);
        }

        return null;
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
        $baseName = pathinfo($rawTitle, PATHINFO_FILENAME) ?: 'document';

        // Keep Thai and all printable Unicode; strip only filesystem-illegal chars and control chars
        $safeName = (string) preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/u', '', $baseName);
        // Collapse runs of whitespace to one space
        $safeName = (string) preg_replace('/\s+/u', ' ', $safeName);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderedExportNodes(array $document): array
    {
        $orderedBlocks = $this->orderedBlocks($document);
        $blocksById = [];
        foreach ($orderedBlocks as $block) {
            $blockId = (string) ($block['block_id'] ?? '');
            if ($blockId !== '') {
                $blocksById[$blockId] = $block;
            }
        }

        $draftHtml = trim((string) ($document['document_review']['draft_html'] ?? ''));
        if ($draftHtml === '') {
            return array_map(static fn (array $block): array => ['type' => 'block', 'block' => $block], $orderedBlocks);
        }

        $dom = $this->loadHtmlFragment($draftHtml);
        $root = $dom->getElementsByTagName('div')->item(0);
        if (! $root instanceof DOMElement) {
            return array_map(static fn (array $block): array => ['type' => 'block', 'block' => $block], $orderedBlocks);
        }

        $nodes = [];
        $seenBlockIds = [];

        // Walk top-level nodes in document order so page breaks AND user-inserted
        // blank paragraphs (empty <p> with no data-block-id) are preserved in the
        // PDF exactly where the editor shows them.
        foreach ($root->childNodes as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            if ($element->hasAttribute('data-page-break')) {
                $nodes[] = ['type' => 'page_break'];

                continue;
            }

            $selfId = trim((string) $element->getAttribute('data-block-id'));
            if ($selfId !== '') {
                if (isset($seenBlockIds[$selfId]) || ! isset($blocksById[$selfId])) {
                    continue;
                }
                $seenBlockIds[$selfId] = true;
                // Capture the element HTML from draft_html so font/formatting edits
                // made in the whole-doc editor (not yet flushed to reviewed_html) appear in the PDF.
                $nodes[] = [
                    'type' => 'block',
                    'block' => $blocksById[$selfId],
                    'draft_html' => (string) $dom->saveHTML($element),
                ];

                continue;
            }

            $matched = [];
            foreach ($element->getElementsByTagName('*') as $descendant) {
                if (! $descendant instanceof DOMElement) {
                    continue;
                }

                $descendantId = trim((string) $descendant->getAttribute('data-block-id'));
                if (
                    $descendantId !== ''
                    && isset($blocksById[$descendantId])
                    && ! isset($seenBlockIds[$descendantId])
                    && ! in_array($descendantId, $matched, true)
                ) {
                    $matched[] = $descendantId;
                }
            }

            if ($matched !== []) {
                foreach ($matched as $blockId) {
                    $seenBlockIds[$blockId] = true;
                    $nodes[] = ['type' => 'block', 'block' => $blocksById[$blockId]];
                }

                continue;
            }

            // No block id and no visible text/media → a blank line the reviewer added.
            // TipTap serializes empty paragraphs as <p><br></p>; textContent is "\n" not "".
            $childNodes = iterator_to_array($element->childNodes);
            $onlyBr = count($childNodes) === 1 && strtolower($childNodes[0]->nodeName) === 'br';
            if ((trim($element->textContent) === '' || $onlyBr) && $element->getElementsByTagName('img')->length === 0) {
                $nodes[] = ['type' => 'blank'];
            }
        }

        foreach ($orderedBlocks as $block) {
            $blockId = (string) ($block['block_id'] ?? '');
            if ($blockId !== '' && ! isset($seenBlockIds[$blockId])) {
                $nodes[] = ['type' => 'block', 'block' => $block];
            }
        }

        return $nodes;
    }

    private function renderBlockHtml(array $block): string
    {
        $table = $this->normalizeTable($block);
        if ((string) ($block['type'] ?? '') === 'table' && $table !== null && ($table['cells'] ?? []) !== []) {
            return $this->buildTableHtml($this->pageSafeTableRows((array) $table['cells']));
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
        $fontSize = isset($block['meta']['formatting']['font_size_pt'])
            ? (float) $block['meta']['formatting']['font_size_pt']
            : null;
        $inner = $this->escapeHtmlWithBreaks($text);
        if ($fontSize !== null && $fontSize > 0) {
            $inner = '<span style="font-size: '.$fontSize.'pt">'.$inner.'</span>';
        }

        return '<p>'.$inner.'</p>';
    }

    private function normalizeTable(array $block): ?array
    {
        return $this->documentHtmlService->normalizeTablePayload($block['meta']['table'] ?? null);
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $rows
     */
    private function buildTableHtml(array $rows): string
    {
        $htmlRows = [];

        foreach ($rows as $row) {
            $cells = [];
            foreach ((array) $row as $cell) {
                $text = $this->escapeHtml((string) ($cell['text'] ?? ''));
                $colspan = max(1, (int) ($cell['colspan'] ?? 1));
                $alignment = (string) ($cell['alignment'] ?? '');
                $style = $alignment !== '' ? ' style="text-align:'.$this->escapeHtml($alignment).';"' : '';
                $attrs = '';
                if ($colspan > 1) {
                    $attrs .= ' colspan="'.$colspan.'"';
                }

                $cells[] = sprintf('<td%s%s>%s</td>', $attrs, $style, nl2br($text));
            }

            $htmlRows[] = '<tr>'.implode('', $cells).'</tr>';
        }

        return '<table><tbody>'.implode('', $htmlRows).'</tbody></table>';
    }

    private function appendTable(object $section, array $tableData): void
    {
        $table = $section->addTable(['width' => 5000, 'unit' => 'pct']);
        $rows = $this->pageSafeTableRows((array) ($tableData['cells'] ?? []));
        $totalColumns = 0;
        $borderStyle = [
            'borderTopSize' => 8,    'borderTopColor' => '000000',
            'borderBottomSize' => 8, 'borderBottomColor' => '000000',
            'borderLeftSize' => 8,   'borderLeftColor' => '000000',
            'borderRightSize' => 8,  'borderRightColor' => '000000',
            'paddingTop' => 60,
            'paddingBottom' => 60,
            'paddingLeft' => 108,
            'paddingRight' => 108,
            'valign' => 'top',
        ];

        foreach ($rows as $row) {
            $columnCount = 0;
            foreach ((array) $row as $cell) {
                $columnCount += max(1, (int) ($cell['colspan'] ?? 1));
            }
            $totalColumns = max($totalColumns, $columnCount);
        }

        foreach ($rows as $rowCells) {
            $row = $table->addRow(null, ['cantSplit' => true]);
            $columnIndex = 0;

            foreach ((array) $rowCells as $cellData) {
                $colspan = max(1, (int) ($cellData['colspan'] ?? 1));
                $style = $borderStyle;

                if ($colspan > 1) {
                    $style['gridSpan'] = $colspan;
                }

                $cell = $row->addCell(null, $style);
                $cell->addText(
                    (string) ($cellData['text'] ?? ''),
                    ['name' => self::EXPORT_FONT, 'size' => 16],
                    [
                        'alignment' => $this->mapAlignment((string) ($cellData['alignment'] ?? '')) ?? Jc::LEFT,
                        'spaceAfter' => 0,
                    ],
                );

                $columnIndex += $colspan;
            }

            while ($columnIndex < $totalColumns) {
                $row->addCell(null, $borderStyle);
                $columnIndex++;
            }
        }
    }

    /**
     * LibreOffice can drop or overlap vertically merged table cells when a table
     * crosses a PDF page boundary. For export, expand rowspans into normal cells
     * so pagination happens between complete physical rows with stable borders.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $rows
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function pageSafeTableRows(array $rows): array
    {
        $expandedRows = [];
        $activeMerges = [];

        foreach ($rows as $sourceRow) {
            $sourceCells = array_values(array_filter((array) $sourceRow, 'is_array'));
            $sourceIndex = 0;
            $columnIndex = 0;
            $nextMerges = [];
            $expandedRow = [];

            while ($sourceIndex < count($sourceCells) || $this->hasActiveMergeAtOrAfter($activeMerges, $columnIndex)) {
                if (isset($activeMerges[$columnIndex])) {
                    $merge = $activeMerges[$columnIndex];
                    $cell = $this->pageSafeCell((array) ($merge['cell'] ?? []));
                    $expandedRow[] = $cell;

                    $colspan = max(1, (int) ($cell['colspan'] ?? 1));
                    $remaining = max(0, (int) ($merge['remaining'] ?? 0) - 1);
                    if ($remaining > 0) {
                        for ($offset = 0; $offset < $colspan; $offset++) {
                            $nextMerges[$columnIndex + $offset] = [
                                'remaining' => $remaining,
                                'cell' => $cell,
                            ];
                        }
                    }

                    $columnIndex += $colspan;

                    continue;
                }

                if ($sourceIndex >= count($sourceCells)) {
                    break;
                }

                $rawCell = (array) $sourceCells[$sourceIndex++];
                $cell = $this->pageSafeCell($rawCell);
                $expandedRow[] = $cell;

                $colspan = max(1, (int) ($cell['colspan'] ?? 1));
                $rowspan = max(1, (int) ($rawCell['rowspan'] ?? 1));
                if ($rowspan > 1) {
                    for ($offset = 0; $offset < $colspan; $offset++) {
                        $nextMerges[$columnIndex + $offset] = [
                            'remaining' => $rowspan - 1,
                            'cell' => $cell,
                        ];
                    }
                }

                $columnIndex += $colspan;
            }

            if ($expandedRow !== []) {
                $expandedRows[] = $expandedRow;
            }

            $activeMerges = $nextMerges;
        }

        return $expandedRows;
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return array<string, mixed>
     */
    private function pageSafeCell(array $cell): array
    {
        $cell['rowspan'] = 1;

        return $cell;
    }

    /**
     * @param  array<int, array<string, mixed>>  $activeMerges
     */
    private function hasActiveMergeAtOrAfter(array $activeMerges, int $columnIndex): bool
    {
        foreach (array_keys($activeMerges) as $mergeColumnIndex) {
            if ((int) $mergeColumnIndex >= $columnIndex) {
                return true;
            }
        }

        return false;
    }

    /**
     * A blank reviewer-inserted line: same 1.85 line spacing as real paragraphs
     * so its height matches the editor.
     *
     * @return array<string, mixed>
     */
    private function blankParagraphStyle(): array
    {
        // PhpWord's Paragraph style key for line spacing is `lineHeight` (a
        // multiplier), NOT `line`/`lineRule` — those are silently ignored, which
        // left blank paragraphs with no line box so LibreOffice collapsed them.
        return [
            'spaceAfter' => 0,
            'lineHeight' => self::DEFAULT_LINE_HEIGHT,
        ];
    }

    /**
     * Parse a unitless CSS line-height (the editor's LineHeightExtension sets
     * `line-height: 1.5` etc. on the paragraph) from a draft element's HTML.
     */
    private function lineHeightFromHtml(string $html): ?float
    {
        if ($html === '' || preg_match('/line-height\s*:\s*([0-9]*\.?[0-9]+)/i', $html, $m) !== 1) {
            return null;
        }

        $value = (float) $m[1];

        return $value > 0 ? $value : null;
    }

    /**
     * Stored line spacing lives in Word units (240 = single). Convert to the
     * PhpWord `lineHeight` multiplier, or null when absent.
     *
     * @param  array<string, mixed>  $layout
     */
    private function layoutLineHeight(array $layout): ?float
    {
        $spacing = $layout['line_spacing'] ?? null;
        if (! is_numeric($spacing) || (float) $spacing <= 0) {
            return null;
        }

        return (float) $spacing / 240.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraphStyleForBlock(array $block, ?float $lineHeight = null): array
    {
        $layout = is_array($block['meta']['layout'] ?? null) ? $block['meta']['layout'] : [];
        $isHeading = ($block['type'] ?? '') === 'section_header';
        // `lineHeight` is the correct PhpWord key (multiplier → w:line auto).
        // Prefer the value parsed from the live draft <p> (user's editor choice),
        // then the stored layout.line_spacing (Word 240 units = 1×), else 1.85.
        $resolvedLineHeight = $lineHeight
            ?? $this->layoutLineHeight($layout)
            ?? self::DEFAULT_LINE_HEIGHT;
        $style = [
            'spaceAfter' => 0,
            'widowControl' => true,
            'lineHeight' => $resolvedLineHeight,
        ];

        if ($isHeading) {
            $style['keepWithNext'] = true;
        }

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
        $style['name'] = $fontFamily !== '' ? $fontFamily : self::EXPORT_FONT;

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
                'h1' => '22pt',
                'h2' => '18pt',
                default => '16pt',
            };
        }

        foreach ($this->parseInlineStyle((string) $node->getAttribute('style')) as $property => $value) {
            if ($property === 'font-family' && $value !== '') {
                $first = explode(',', $value)[0];
                $next['fontFamily'] = trim($first, '\'" ');
            }
            if ($property === 'font-size' && $value !== '') {
                $next['fontSize'] = $value;
            }
            $valueLower = strtolower($value);
            if ($property === 'font-weight' && ($valueLower === 'bold' || (is_numeric($valueLower) && (int) $valueLower >= 600))) {
                $next['bold'] = true;
            }
            if ($property === 'font-style' && $valueLower === 'italic') {
                $next['italic'] = true;
            }
            if ($property === 'text-decoration' && str_contains($valueLower, 'underline')) {
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
            $value = trim($value);

            if ($property !== '' && $value !== '') {
                $parsed[$property] = $value;
            }
        }

        return $parsed;
    }

    /**
     * @return array{top: int, bottom: int, left: int, right: int}
     */
    private function pageMarginsFromDocument(array $document): array
    {
        $composeState = is_array($document['compose_state'] ?? null) ? $document['compose_state'] : [];
        $pageMargins = is_array($composeState['page_margins'] ?? null) ? $composeState['page_margins'] : [];

        return [
            'top' => max(0, (int) ($pageMargins['top'] ?? self::DEFAULT_PAGE_MARGINS['top'])),
            'bottom' => max(0, (int) ($pageMargins['bottom'] ?? self::DEFAULT_PAGE_MARGINS['bottom'])),
            'left' => max(0, (int) ($pageMargins['left'] ?? self::DEFAULT_PAGE_MARGINS['left'])),
            'right' => max(0, (int) ($pageMargins['right'] ?? self::DEFAULT_PAGE_MARGINS['right'])),
        ];
    }

    private function twipsToMillimeters(int $twips): float
    {
        return ($twips / 1440) * 25.4;
    }

    private function formatMillimeters(float $millimeters): string
    {
        $formatted = number_format($millimeters, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
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
