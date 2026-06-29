<?php

namespace Tests\Unit\Fast\Docx;

use App\Services\Fast\Docx\TableHtmlRenderer;
use Tests\TestCase;

class TableHtmlRendererTest extends TestCase
{
    public function test_renders_colspan_rowspan_and_alignment(): void
    {
        $renderer = new TableHtmlRenderer;

        $html = $renderer->render([
            [
                ['text' => 'MERGED', 'colspan' => 1, 'rowspan' => 2, 'alignment' => null],
                ['text' => 'HEADER', 'colspan' => 2, 'rowspan' => 1, 'alignment' => 'center'],
            ],
            [
                ['text' => 'B1', 'colspan' => 1, 'rowspan' => 1, 'alignment' => null],
                ['text' => 'B2', 'colspan' => 1, 'rowspan' => 1, 'alignment' => 'right'],
            ],
        ]);

        $this->assertStringContainsString('rowspan="2"', $html);
        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringContainsString('data-cell-align="center"', $html);
        $this->assertStringContainsString('style="text-align:center;"', $html);
    }

    public function test_renders_image_placeholder_for_empty_image_cell(): void
    {
        $renderer = new TableHtmlRenderer;

        $html = $renderer->render([
            [
                ['text' => '', 'colspan' => 1, 'rowspan' => 1, 'alignment' => null, 'has_image' => true],
            ],
        ]);

        $this->assertStringContainsString('doc-cell-image', $html);
        $this->assertStringContainsString('[image]', $html);
    }
}
