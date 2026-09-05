<?php

namespace Tests\Unit;

use App\Services\DocumentExportService;
use App\Services\DocumentHtmlService;
use App\Services\Fast\LibreOfficeConverter;
use App\Services\ReviewStore;
use App\Services\Storage\MongoBlobStore;
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
        $html = new DocumentHtmlService();
        $blob = $this->createStub(MongoBlobStore::class);
        $store = new ReviewStore($html, $blob, sys_get_temp_dir().'/doc-export-'.uniqid('', true));

        return new DocumentExportService($html, $converter ?? new LibreOfficeConverter(), $store);
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

    public function test_paragraph_style_includes_widow_control(): void
    {
        $method = new ReflectionMethod(DocumentExportService::class, 'paragraphStyleForBlock');
        $method->setAccessible(true);

        $style = $method->invoke($this->makeService(), [
            'block_id' => 'b1',
            'type' => 'paragraph',
            'reading_order' => 1,
            'approved_text' => 'ข้อความ',
            'meta' => ['layout' => []],
        ]);

        $this->assertTrue($style['widowControl'] ?? false, 'widowControl must be true for paragraphs');
    }

    public function test_heading_style_keeps_with_next(): void
    {
        $method = new ReflectionMethod(DocumentExportService::class, 'paragraphStyleForBlock');
        $method->setAccessible(true);

        $style = $method->invoke($this->makeService(), [
            'block_id' => 'h1',
            'type' => 'section_header',
            'reading_order' => 1,
            'approved_text' => 'หัวข้อ',
            'meta' => ['layout' => []],
        ]);

        $this->assertTrue($style['keepWithNext'] ?? false, 'keepWithNext must be true for section_header');
    }

    public function test_docx_declares_th_sarabun_new_default_font(): void
    {
        $bytes = $this->makeService()->toDocx($this->sampleDocument());
        $stylesXml = $this->readDocxXml($bytes, 'word/styles.xml');

        // Must match the editor's installed primary face, not the unavailable PSK
        // name that fontconfig would substitute with a different-metric font.
        $this->assertStringContainsString('TH Sarabun New', $stylesXml);
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

    public function test_parse_html_runs_normalizes_font_stack_to_first_name(): void
    {
        $runs = $this->makeService()->parseHtmlRuns(
            '<span style="font-family: \'TH Sarabun PSK\', \'Sarabun\', sans-serif">hello</span>',
        );

        $this->assertCount(1, $runs);
        $this->assertSame('TH Sarabun PSK', $runs[0]['fontFamily'] ?? null);
    }

    public function test_parse_html_runs_strips_quotes_from_single_font_name(): void
    {
        $runs = $this->makeService()->parseHtmlRuns('<span style=\'font-family: "Sarabun"\'>hello</span>');

        $this->assertCount(1, $runs);
        $this->assertSame('Sarabun', $runs[0]['fontFamily'] ?? null);
    }

    public function test_plain_paragraph_run_uses_docx_default_font_size(): void
    {
        $runs = $this->makeService()->parseHtmlRuns('<p>ข้อความปกติ</p>');

        $this->assertCount(1, $runs);
        $this->assertArrayNotHasKey('size', $this->fontStyleForRun($runs[0]));
        $this->assertSame('TH Sarabun New', $this->fontStyleForRun($runs[0])['name'] ?? null);

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

    public function test_build_html_includes_page_break_css(): void
    {
        $html = $this->makeService()->buildHtml([
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'reading_order' => 1,
                    'approved_text' => 'ข้อความ',
                    'normalized_text' => 'ข้อความ',
                    'raw_text' => 'ข้อความ',
                    'meta' => ['layout' => []],
                ]],
            ]],
        ]);

        $this->assertStringContainsString('page-break-inside: avoid', $html);
        $this->assertStringContainsString('orphans:', $html);
        $this->assertStringContainsString('widows:', $html);
        $this->assertStringContainsString('page-break-after: avoid', $html);
    }

    public function test_build_html_allows_tables_to_break_only_between_rows(): void
    {
        $html = $this->makeService()->buildHtml([
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'tbl1',
                    'type' => 'table',
                    'reading_order' => 1,
                    'meta' => [
                        'layout' => [],
                        'table' => [
                            'cells' => [
                                [['text' => 'หัวข้อ', 'colspan' => 1, 'rowspan' => 2]],
                                [['text' => 'ข้อมูล', 'colspan' => 1, 'rowspan' => 1]],
                            ],
                        ],
                    ],
                ]],
            ]],
        ]);

        $this->assertStringContainsString('class="block block--table"', $html);
        $this->assertStringContainsString('.block--table { page-break-inside: auto; break-inside: auto; }', $html);
        $this->assertStringContainsString('tr { page-break-inside: avoid; break-inside: avoid; }', $html);
        $this->assertStringContainsString('border: 1pt solid #000', $html);
        $this->assertStringNotContainsString('rowspan=', $html);
        $this->assertSame(2, substr_count($html, 'หัวข้อ'));
    }

    public function test_docx_table_cells_include_borders_and_sarabun_font(): void
    {
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'tbl1',
                    'type' => 'table',
                    'reading_order' => 1,
                    'meta' => [
                        'table' => [
                            'cells' => [
                                [
                                    ['text' => 'หัวข้อ 1', 'colspan' => 1, 'rowspan' => 1, 'alignment' => 'center'],
                                    ['text' => 'หัวข้อ 2', 'colspan' => 1, 'rowspan' => 1, 'alignment' => 'center'],
                                ],
                                [
                                    ['text' => 'ข้อมูล A', 'colspan' => 1, 'rowspan' => 1, 'alignment' => 'left'],
                                    ['text' => 'ข้อมูล B', 'colspan' => 1, 'rowspan' => 1, 'alignment' => 'left'],
                                ],
                            ],
                        ],
                    ],
                ]],
            ]],
        ];

        $documentXml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringContainsString('<w:tbl>', $documentXml);
        $this->assertStringContainsString('<w:tcBorders>', $documentXml);
        $this->assertStringContainsString('w:val="single"', $documentXml);
        $this->assertStringContainsString('w:sz="8"', $documentXml);
        $this->assertStringContainsString('<w:cantSplit w:val="1"/>', $documentXml);
        $this->assertStringContainsString('TH Sarabun New', $documentXml);
    }

    public function test_docx_expands_rowspan_tables_for_page_safe_pdf_export_duplicate_fixture(): void
    {
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'tbl1',
                    'type' => 'table',
                    'reading_order' => 1,
                    'meta' => [
                        'table' => [
                            'cells' => [
                                [
                                    ['text' => 'รวมช่อง', 'colspan' => 1, 'rowspan' => 2],
                                    ['text' => 'แถวแรก', 'colspan' => 1, 'rowspan' => 1],
                                ],
                                [
                                    ['text' => 'แถวสอง', 'colspan' => 1, 'rowspan' => 1],
                                ],
                            ],
                        ],
                    ],
                ]],
            ]],
        ];

        $documentXml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringNotContainsString('w:vMerge', $documentXml);
        $this->assertStringContainsString('<w:cantSplit w:val="1"/>', $documentXml);
        $this->assertSame(2, substr_count($documentXml, 'รวมช่อง'));
    }

    public function test_docx_expands_rowspan_tables_for_page_safe_pdf_export(): void
    {
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'tbl1',
                    'type' => 'table',
                    'reading_order' => 1,
                    'meta' => [
                        'table' => [
                            'cells' => [
                                [
                                    ['text' => 'รวมช่อง', 'colspan' => 1, 'rowspan' => 2],
                                    ['text' => 'แถวแรก', 'colspan' => 1, 'rowspan' => 1],
                                ],
                                [
                                    ['text' => 'แถวสอง', 'colspan' => 1, 'rowspan' => 1],
                                ],
                            ],
                        ],
                    ],
                ]],
            ]],
        ];

        $documentXml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringNotContainsString('w:vMerge', $documentXml);
        $this->assertStringContainsString('<w:cantSplit w:val="1"/>', $documentXml);
        $this->assertSame(2, substr_count($documentXml, 'รวมช่อง'));
    }

    public function test_docx_embeds_image_blocks(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'img1',
                    'type' => 'image',
                    'reading_order' => 1,
                    'meta' => ['image' => ['data_uri' => $png, 'display_width_px' => 120]],
                ]],
            ]],
        ];

        $bytes = $this->makeService()->toDocx($document);

        $tmp = tempnam(sys_get_temp_dir(), 'docx_img_').'.docx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $hasMedia = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'word/media/')) {
                $hasMedia = true;
                break;
            }
        }
        $zip->close();
        @unlink($tmp);

        $this->assertTrue($hasMedia, 'expected an embedded image under word/media/');
    }

    public function test_inline_image_keeps_position_from_draft_html(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    [
                        'block_id' => 'img1',
                        'type' => 'image',
                        'reading_order' => 1,
                        'meta' => ['image' => ['data_uri' => $png, 'display_width_px' => 120]],
                    ],
                    [
                        'block_id' => 't2',
                        'type' => 'paragraph',
                        'reading_order' => 2,
                        'approved_text' => 'ท้ายเอกสาร',
                        'normalized_text' => 'ท้ายเอกสาร',
                        'meta' => ['reviewed_html' => '<p data-block-id="t2">ท้ายเอกสาร</p>', 'layout' => []],
                    ],
                ],
            ]],
            // TipTap serializes an inline image as <img data-block-id> nested in a <p>.
            'document_review' => [
                'draft_html' => '<p><img data-block-id="img1"></p><p data-block-id="t2">ท้ายเอกสาร</p>',
            ],
        ];

        $documentXml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $drawingPos = strpos($documentXml, '<w:pict');
        $tailPos = strpos($documentXml, 'ท้ายเอกสาร');
        $this->assertNotFalse($drawingPos, 'expected an inline image drawing in the document');
        $this->assertNotFalse($tailPos, 'expected the trailing paragraph text in the document');
        $this->assertLessThan($tailPos, $drawingPos, 'image must keep its in-flow position, not land at the end/footer');
    }

    public function test_docx_respects_page_break_nodes_from_draft_html(): void
    {
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    [
                        'block_id' => 'b1',
                        'type' => 'paragraph',
                        'reading_order' => 1,
                        'approved_text' => 'หน้าแรก',
                        'normalized_text' => 'หน้าแรก',
                        'raw_text' => 'หน้าแรก',
                        'meta' => ['reviewed_html' => '<p data-block-id="b1">หน้าแรก</p>', 'layout' => []],
                    ],
                    [
                        'block_id' => 'b2',
                        'type' => 'paragraph',
                        'reading_order' => 2,
                        'approved_text' => 'หน้าสอง',
                        'normalized_text' => 'หน้าสอง',
                        'raw_text' => 'หน้าสอง',
                        'meta' => ['reviewed_html' => '<p data-block-id="b2">หน้าสอง</p>', 'layout' => []],
                    ],
                ],
            ]],
            'document_review' => [
                'draft_html' => '<p data-block-id="b1">หน้าแรก</p><div data-page-break="" style="page-break-after: always">แบ่งหน้า</div><p data-block-id="b2">หน้าสอง</p>',
            ],
        ];

        $documentXml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringContainsString('w:type="page"', $documentXml);
    }

    public function test_docx_preserves_reviewer_inserted_blank_lines_from_draft_html(): void
    {
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    [
                        'block_id' => 'b1',
                        'type' => 'paragraph',
                        'reading_order' => 1,
                        'approved_text' => 'บรรทัดแรก',
                        'normalized_text' => 'บรรทัดแรก',
                        'raw_text' => 'บรรทัดแรก',
                        'meta' => ['reviewed_html' => '<p data-block-id="b1">บรรทัดแรก</p>', 'layout' => []],
                    ],
                    [
                        'block_id' => 'b2',
                        'type' => 'paragraph',
                        'reading_order' => 2,
                        'approved_text' => 'บรรทัดสาม',
                        'normalized_text' => 'บรรทัดสาม',
                        'raw_text' => 'บรรทัดสาม',
                        'meta' => ['reviewed_html' => '<p data-block-id="b2">บรรทัดสาม</p>', 'layout' => []],
                    ],
                ],
            ]],
            'document_review' => [
                // The reviewer pressed Enter between the two blocks: a bodiless <p>.
                'draft_html' => '<p data-block-id="b1">บรรทัดแรก</p><p></p><p data-block-id="b2">บรรทัดสาม</p>',
            ],
        ];

        $documentXml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        // Three paragraphs must survive: the two blocks plus the empty spacer,
        // in that order — otherwise the blank line vanishes from the PDF.
        $this->assertSame(
            1,
            preg_match('/บรรทัดแรก.*<w:p\b[^>]*>(?:(?!บรรทัด).)*<\/w:p>.*บรรทัดสาม/su', $documentXml),
        );
    }

    public function test_paragraphs_emit_default_line_spacing(): void
    {
        // Regression: the export used the invalid PhpWord keys line/lineRule, so
        // NO line spacing reached the DOCX and 1.85 never rendered in the PDF.
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1', 'type' => 'paragraph', 'reading_order' => 1,
                    'approved_text' => 'ก', 'normalized_text' => 'ก',
                    'meta' => ['reviewed_html' => '<p>ก</p>', 'layout' => []],
                ]],
            ]],
        ];

        $xml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringContainsString('w:line="240"', $xml);   // 1.0 × 240
        $this->assertStringContainsString('w:lineRule="auto"', $xml);
    }

    public function test_docx_line_spacing_clamps_low_values_and_preserves_one_point_five(): void
    {
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    [
                        'block_id' => 'b1', 'type' => 'paragraph', 'reading_order' => 1,
                        'approved_text' => 'ก', 'normalized_text' => 'ก',
                        'meta' => ['reviewed_html' => '<p>ก</p>', 'layout' => ['line_spacing' => 120]],
                    ],
                    [
                        'block_id' => 'b2', 'type' => 'paragraph', 'reading_order' => 2,
                        'approved_text' => 'ข', 'normalized_text' => 'ข',
                        'meta' => ['reviewed_html' => '<p>ข</p>', 'layout' => ['line_spacing' => 360]],
                    ],
                ],
            ]],
        ];

        $xml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringContainsString('w:line="240"', $xml);
        $this->assertStringContainsString('w:line="360"', $xml);
    }

    public function test_user_line_height_from_draft_html_is_honored(): void
    {
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1', 'type' => 'paragraph', 'reading_order' => 1,
                    'approved_text' => 'ก', 'normalized_text' => 'ก',
                    'meta' => ['reviewed_html' => '<p data-block-id="b1">ก</p>', 'layout' => []],
                ]],
            ]],
            'document_review' => [
                'draft_html' => '<p data-block-id="b1" style="line-height: 2">ก</p>',
            ],
        ];

        $xml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringContainsString('w:line="480"', $xml);   // 2 × 240
    }

    public function test_blank_lines_do_not_collapse(): void
    {
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    ['block_id' => 'b1', 'type' => 'paragraph', 'reading_order' => 1, 'approved_text' => 'ข้อ ๑', 'normalized_text' => 'ข้อ ๑', 'meta' => ['reviewed_html' => '<p data-block-id="b1">ข้อ ๑</p>', 'layout' => []]],
                    ['block_id' => 'b2', 'type' => 'paragraph', 'reading_order' => 2, 'approved_text' => 'ข้อ ๒', 'normalized_text' => 'ข้อ ๒', 'meta' => ['reviewed_html' => '<p data-block-id="b2">ข้อ ๒</p>', 'layout' => []]],
                ],
            ]],
            'document_review' => [
                'draft_html' => '<p data-block-id="b1">ข้อ ๑</p><p></p><p></p><p data-block-id="b2">ข้อ ๒</p>',
            ],
        ];

        $xml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        // Four paragraphs (2 text + 2 blank), each carrying the default line box so
        // LibreOffice cannot collapse the blanks.
        $this->assertSame(4, substr_count($xml, 'w:line="240"'));
        // Every paragraph — including the two blanks — carries a preserved-whitespace
        // text run so the blank lines hold their height. Assert the invariant, not
        // the exact filler character (it has churned between ZWSP and space).
        $this->assertSame(4, substr_count($xml, 'xml:space="preserve"'));
    }

    public function test_blockHtmlOrFallback_emits_font_size_from_formatting(): void
    {
        $block = [
            'block_id' => 'p1-b0001',
            'type' => 'paragraph',
            'reading_order' => 1,
            'approved_text' => 'ข้อความ',
            'normalized_text' => 'ข้อความ',
            'meta' => [
                'reviewed_html' => '',
                'formatting' => ['font_size_pt' => 14.0],
                'layout' => [],
            ],
        ];

        $method = new ReflectionMethod(DocumentExportService::class, 'blockHtmlOrFallback');
        $method->setAccessible(true);

        $html = $method->invoke($this->makeService(), $block);

        $this->assertStringContainsString('font-size: 14pt', $html);
    }

    public function test_docx_uses_logo_display_width_cm_and_spacing(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $document = [
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'img1',
                    'type' => 'image',
                    'reading_order' => 1,
                    'meta' => [
                        'image' => [
                            'data_uri' => $png,
                            'is_logo' => true,
                            'display_width_cm' => 2.99,
                            'spacing_after_line_height' => 1.5,
                        ],
                    ],
                ]],
            ]],
        ];

        $xml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringContainsString('wp:extent cx="1076400"', $xml);
        $this->assertStringContainsString('w:jc w:val="center"', $xml);
        $this->assertStringContainsString('w:line="360"', $xml);
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
