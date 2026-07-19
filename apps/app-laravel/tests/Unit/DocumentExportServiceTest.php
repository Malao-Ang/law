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

    public function test_safe_filename_base_preserves_thai(): void
    {
        $document = [
            'source_file' => 'ประกาศ (1).pdf',
            'law_meta' => ['title' => 'something else'],
        ];

        $result = $this->makeService()->safeFilenameBase($document);

        $this->assertSame('ประกาศ (1)', $result);
    }

    public function test_safe_filename_base_prefers_source_file_over_law_meta_title(): void
    {
        $document = [
            'source_file' => 'original.pdf',
            'law_meta' => ['title' => 'other title'],
        ];

        $result = $this->makeService()->safeFilenameBase($document);

        $this->assertSame('original', $result);
    }

    public function test_safe_filename_base_falls_back_to_law_meta_title_when_source_file_missing(): void
    {
        $document = [
            'law_meta' => ['title' => 'กฎหมาย.pdf'],
        ];

        $result = $this->makeService()->safeFilenameBase($document);

        $this->assertSame('กฎหมาย', $result);
    }

    public function test_safe_filename_base_strips_filesystem_illegal_chars(): void
    {
        $document = [
            'source_file' => 'a/b\\c:d*e?f"g<h>i|j.pdf',
        ];

        $result = $this->makeService()->safeFilenameBase($document);

        $this->assertSame('abcdefghij', $result);
    }

    public function test_safe_filename_base_collapses_whitespace(): void
    {
        $document = [
            'source_file' => "กฎหมาย  ฉบับ   ที่.pdf",
        ];

        $result = $this->makeService()->safeFilenameBase($document);

        $this->assertSame('กฎหมาย ฉบับ ที่', $result);
    }

    public function test_safe_filename_base_returns_document_when_empty(): void
    {
        $result = $this->makeService()->safeFilenameBase([]);

        $this->assertSame('document', $result);
    }
}
