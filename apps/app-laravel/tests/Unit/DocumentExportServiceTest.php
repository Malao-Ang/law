<?php

namespace Tests\Unit;

use App\Services\DocumentExportService;
use App\Services\DocumentHtmlService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DocumentExportServiceTest extends TestCase
{
    private function makeService(): DocumentExportService
    {
        return new DocumentExportService(new DocumentHtmlService());
    }

    /**
     * @return array<string, mixed>
     */
    private function block(array $layout): array
    {
        return [
            'block_id' => 'p1-b0001',
            'type' => 'paragraph',
            'reading_order' => 1,
            'approved_text' => 'ข้อความ',
            'normalized_text' => 'ข้อความ',
            'ai_suggested_text' => 'ข้อความ',
            'meta' => [
                'layout' => $layout,
            ],
        ];
    }

    public function test_build_html_uses_layout_indent_for_pdf(): void
    {
        $html = $this->makeService()->buildHtml([
            'pages' => [[
                'page_no' => 1,
                'blocks' => [$this->block(['indent_left' => 720])],
            ]],
        ]);

        $this->assertStringContainsString('margin-left:36pt', $html);
    }

    public function test_paragraph_style_for_block_keeps_word_indent_left(): void
    {
        $method = new ReflectionMethod(DocumentExportService::class, 'paragraphStyleForBlock');
        $method->setAccessible(true);

        $style = $method->invoke($this->makeService(), $this->block(['indent_left' => 720]));

        $this->assertSame(720, $style['indentation']['left'] ?? null);
    }
}
