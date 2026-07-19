<?php

namespace Tests\Unit;

use App\Services\DocumentExportService;
use App\Services\DocumentHtmlService;
use App\Services\Fast\LibreOfficeConverter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ZipArchive;

class DocumentExportServiceTest extends TestCase
{
    private function fontStyleForRun(array $run): array
    {
        $method = new ReflectionMethod(DocumentExportService::class, 'fontStyleForRun');
        $method->setAccessible(true);

        return $method->invoke($this->makeService(), $run);
    }

    private function readDocxXml(string $bytes, string $entry): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx_xml_').'.docx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $xml = (string) $zip->getFromName($entry);
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    private function makeService(?LibreOfficeConverter $converter = null): DocumentExportService
    {
        return new DocumentExportService(new DocumentHtmlService(), $converter ?? new LibreOfficeConverter());
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

    /**
     * @return array<string, mixed>
     */
    private function sampleDocument(): array
    {
        return [
            'document_id' => 'doc_test',
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'reading_order' => 1,
                    'approved_text' => 'ข้อความ',
                    'normalized_text' => 'ข้อความ',
                    'raw_text' => 'ข้อความ',
                    'meta' => [
                        'reviewed_html' => '<p>ข้อความ</p>',
                        'layout' => ['indent_left' => 720, 'tabs' => []],
                    ],
                ]],
            ]],
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

    public function test_docx_declares_th_sarabun_psk_default_font(): void
    {
        $bytes = $this->makeService()->toDocx($this->sampleDocument());
        $stylesXml = $this->readDocxXml($bytes, 'word/styles.xml');

        $this->assertStringContainsString('TH Sarabun PSK', $stylesXml);
    }

    public function test_heading_runs_use_true_scale_point_sizes(): void
    {
        $runs = $this->makeService()->parseHtmlRuns('<h1>หัวข้อหนึ่ง</h1><h2>หัวข้อสอง</h2><h3>หัวข้อสาม</h3>');

        $byText = [];
        foreach ($runs as $run) {
            $byText[$run['text']] = $this->fontStyleForRun($run);
        }

        $this->assertSame(22.0, $byText['หัวข้อหนึ่ง']['size'] ?? null);
        $this->assertSame(18.0, $byText['หัวข้อสอง']['size'] ?? null);
        $this->assertSame(16.0, $byText['หัวข้อสาม']['size'] ?? null);
    }

    public function test_inline_point_font_size_is_preserved_in_export_runs(): void
    {
        $runs = $this->makeService()->parseHtmlRuns('<p><span style="font-size: 12pt">เล็ก</span></p>');

        $this->assertCount(1, $runs);
        $this->assertSame(12.0, $this->fontStyleForRun($runs[0])['size'] ?? null);
    }

    public function test_plain_paragraph_run_uses_docx_default_font_size(): void
    {
        $runs = $this->makeService()->parseHtmlRuns('<p>ข้อความปกติ</p>');

        $this->assertCount(1, $runs);
        $this->assertArrayNotHasKey('size', $this->fontStyleForRun($runs[0]));

        $stylesXml = $this->readDocxXml($this->makeService()->toDocx($this->sampleDocument()), 'word/styles.xml');

        $this->assertStringContainsString('w:sz w:val="32"', $stylesXml);
    }

    public function test_docx_uses_custom_page_margins_when_present(): void
    {
        $document = $this->sampleDocument();
        $document['compose_state'] = [
            'page_margins' => [
                'top' => 720,
                'bottom' => 900,
                'left' => 1080,
                'right' => 1260,
            ],
        ];

        $documentXml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringContainsString('w:top="720"', $documentXml);
        $this->assertStringContainsString('w:bottom="900"', $documentXml);
        $this->assertStringContainsString('w:left="1080"', $documentXml);
        $this->assertStringContainsString('w:right="1260"', $documentXml);
    }

    public function test_docx_uses_default_page_margins_when_absent(): void
    {
        $documentXml = $this->readDocxXml($this->makeService()->toDocx($this->sampleDocument()), 'word/document.xml');

        $this->assertStringContainsString('w:top="1440"', $documentXml);
        $this->assertStringContainsString('w:bottom="1440"', $documentXml);
        $this->assertStringContainsString('w:left="1800"', $documentXml);
        $this->assertStringContainsString('w:right="1800"', $documentXml);
    }

    public function test_build_html_uses_custom_page_margins_and_heading_sizes(): void
    {
        $html = $this->makeService()->buildHtml([
            'compose_state' => [
                'page_margins' => [
                    'top' => 720,
                    'bottom' => 900,
                    'left' => 1080,
                    'right' => 1260,
                ],
            ],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'h1',
                    'type' => 'section_header',
                    'reading_order' => 1,
                    'approved_text' => 'หัวข้อ',
                    'normalized_text' => 'หัวข้อ',
                    'raw_text' => 'หัวข้อ',
                    'meta' => [
                        'reviewed_html' => '<h1>หัวข้อ</h1>',
                        'layout' => [],
                    ],
                ]],
            ]],
        ]);

        $this->assertStringContainsString('@page { size: A4; margin: 12.7mm 22.22mm 15.88mm 19.05mm; }', $html);
        $this->assertStringContainsString('h1 { font-size: 22pt;', $html);
        $this->assertStringContainsString('h2 { font-size: 18pt;', $html);
        $this->assertStringContainsString('h3 { font-size: 16pt;', $html);
    }

    public function test_to_pdf_renders_docx_via_libreoffice(): void
    {
        $converter = new LibreOfficeConverter(
            binary: 'libreoffice',
            commandRunner: function (array $cmd): int {
                $base = pathinfo($cmd[count($cmd) - 1], PATHINFO_FILENAME);
                $outDir = $cmd[array_search('--outdir', $cmd, true) + 1];
                file_put_contents("{$outDir}/{$base}.pdf", '%PDF-1.7 from-docx');

                return 0;
            },
        );

        $bytes = $this->makeService($converter)->toPdf($this->sampleDocument());

        $this->assertStringStartsWith('%PDF', $bytes);
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
            'source_file' => 'a:b*c?d"e<f>g|h.pdf',
        ];

        $result = $this->makeService()->safeFilenameBase($document);

        $this->assertSame('abcdefgh', $result);
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
