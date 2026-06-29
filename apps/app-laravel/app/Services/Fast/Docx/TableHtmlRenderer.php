<?php

namespace App\Services\Fast\Docx;

final class TableHtmlRenderer
{
    /**
     * @param  array<int, array<int, array<string, mixed>>>  $rows
     */
    public function render(array $rows): string
    {
        $htmlRows = [];

        foreach ($rows as $rowIndex => $row) {
            $renderedCells = [];
            $tag = $rowIndex === 0 ? 'th' : 'td';

            foreach ($row as $cell) {
                $attrs = [];
                $colspan = max(1, (int) ($cell['colspan'] ?? 1));
                $rowspan = max(1, (int) ($cell['rowspan'] ?? 1));
                $alignment = $cell['alignment'] ?? null;

                if ($colspan > 1) {
                    $attrs[] = ' colspan="'.$colspan.'"';
                }
                if ($rowspan > 1) {
                    $attrs[] = ' rowspan="'.$rowspan.'"';
                }
                if (is_string($alignment) && $alignment !== '') {
                    $escapedAlignment = $this->escape($alignment);
                    $attrs[] = ' data-cell-align="'.$escapedAlignment.'" style="text-align:'.$escapedAlignment.';"';
                }

                $text = str_replace("\n", '<br>', $this->escape((string) ($cell['text'] ?? '')));
                if ($text === '' && (($cell['has_image'] ?? false) === true)) {
                    $text = '<span class="doc-cell-image">[image]</span>';
                }

                $renderedCells[] = '<'.$tag.implode('', $attrs).'>'.$text.'</'.$tag.'>';
            }

            $htmlRows[] = '<tr>'.implode('', $renderedCells).'</tr>';
        }

        return '<table class="doc-table" border="1" cellspacing="0" cellpadding="4"><tbody>'.implode('', $htmlRows).'</tbody></table>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
