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

        $tmp = tempnam(sys_get_temp_dir(), 'docx_font_').'.docx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $stylesXml = (string) $zip->getFromName('word/styles.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertStringContainsString('TH Sarabun PSK', $stylesXml);
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
}
