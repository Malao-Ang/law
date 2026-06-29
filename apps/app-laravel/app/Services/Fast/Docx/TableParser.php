<?php

namespace App\Services\Fast\Docx;

use DOMElement;

final class TableParser
{
    public function __construct(
        private readonly ParagraphParser $paragraphParser,
        private readonly TableHtmlRenderer $tableHtmlRenderer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function parse(DOMElement $table): ?array
    {
        $xpath = WordXml::createXPath($table->ownerDocument);
        $rows = [];
        $flattenedRows = [];
        $activeVerticalMerges = [];

        foreach ($xpath->query('./w:tr', $table) ?: [] as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }

            $parsedRow = [];
            $flatRow = [];
            $nextMerges = [];
            $columnIndex = 0;

            foreach ($xpath->query('./w:tc', $rowNode) ?: [] as $cellNode) {
                if (! $cellNode instanceof DOMElement) {
                    continue;
                }

                $cell = $this->parseCell($cellNode);
                $colspan = (int) $cell['colspan'];
                $vMergeState = $cell['v_merge_state'] ?? null;
                unset($cell['v_merge_state']);

                if ($vMergeState === 'continue') {
                    $mergedCell = $activeVerticalMerges[$columnIndex] ?? null;
                    if (is_array($mergedCell)) {
                        $mergedCell['rowspan'] = ((int) ($mergedCell['rowspan'] ?? 1)) + 1;
                        $activeVerticalMerges[$columnIndex] = $mergedCell;
                        for ($offset = 0; $offset < $colspan; $offset++) {
                            $nextMerges[$columnIndex + $offset] = &$activeVerticalMerges[$columnIndex];
                        }
                    }
                    $columnIndex += $colspan;

                    continue;
                }

                $parsedRow[] = $cell;
                $flatRow[] = (string) $cell['text'];

                if ($vMergeState === 'restart') {
                    foreach (range(0, $colspan - 1) as $offset) {
                        $nextMerges[$columnIndex + $offset] = &$parsedRow[array_key_last($parsedRow)];
                    }
                }

                $columnIndex += $colspan;
            }

            if ($parsedRow !== []) {
                $rows[] = $parsedRow;
                $flattenedRows[] = $flatRow;
            }

            $activeVerticalMerges = $nextMerges;
        }

        if ($rows === []) {
            return null;
        }

        $rawTextLines = [];
        foreach ($flattenedRows as $row) {
            $rawTextLines[] = implode("\t", array_values(array_filter($row, static fn (string $value): bool => $value !== '')));
        }

        return [
            'headers' => $flattenedRows[0] ?? [],
            'rows' => $flattenedRows,
            'cells' => $rows,
            'html' => $this->tableHtmlRenderer->render($rows),
            'text' => implode("\n", $rawTextLines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parseCell(DOMElement $cell): array
    {
        $xpath = WordXml::createXPath($cell->ownerDocument);
        $tcPr = $xpath->query('./w:tcPr', $cell)?->item(0);

        $colspan = 1;
        $vMergeState = null;
        if ($tcPr instanceof DOMElement) {
            $gridSpan = $xpath->query('./w:gridSpan', $tcPr)?->item(0);
            if ($gridSpan instanceof DOMElement) {
                $colspan = (int) (WordXml::wordAttr($gridSpan, 'val') ?? '1');
            }

            $vMerge = $xpath->query('./w:vMerge', $tcPr)?->item(0);
            if ($vMerge instanceof DOMElement) {
                $vMergeState = WordXml::wordAttr($vMerge, 'val') ?? 'continue';
            }
        }

        $parts = [];
        foreach ($xpath->query('./w:p', $cell) ?: [] as $paragraph) {
            if (! $paragraph instanceof DOMElement) {
                continue;
            }
            $text = trim($this->paragraphParser->extractText($paragraph));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return [
            'text' => implode("\n", $parts),
            'colspan' => $colspan,
            'rowspan' => 1,
            'alignment' => $this->extractCellAlignment($cell),
            'has_image' => $this->cellHasImage($cell),
            'v_merge_state' => $vMergeState,
        ];
    }

    private function extractCellAlignment(DOMElement $cell): ?string
    {
        $xpath = WordXml::createXPath($cell->ownerDocument);
        foreach ($xpath->query('./w:p', $cell) ?: [] as $paragraph) {
            if (! $paragraph instanceof DOMElement) {
                continue;
            }

            $alignment = $this->paragraphParser->alignmentForParagraph($paragraph);
            if (is_string($alignment) && $alignment !== '') {
                return $alignment;
            }
        }

        return null;
    }

    private function cellHasImage(DOMElement $cell): bool
    {
        foreach ($cell->getElementsByTagName('*') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->hasAttributeNS(WordXml::REL_NS, 'embed')) {
                return true;
            }
        }

        return false;
    }
}
