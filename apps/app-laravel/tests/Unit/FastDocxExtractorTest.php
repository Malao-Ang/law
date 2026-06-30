<?php

namespace Tests\Unit;

use App\Services\Fast\FastDocxExtractor;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../Fixtures/Fast/build_test_docx.php';
require_once __DIR__.'/../Fixtures/Fast/build_raw_docx.php';

class FastDocxExtractorTest extends TestCase
{
    private string $docxPath;

    protected function setUp(): void
    {
        $this->docxPath = sys_get_temp_dir().'/fast-docx-'.uniqid('', true).'.docx';
        buildTestDocx($this->docxPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->docxPath);
    }

    public function test_extract_returns_pages_with_blocks(): void
    {
        $extractor = new FastDocxExtractor;
        $output = $extractor->extract($this->docxPath, 'doc-test-001');

        $this->assertSame('doc-test-001', $output['document_id']);
        $this->assertSame('docx', $output['source_type']);
        $this->assertSame('th', $output['language']);
        $this->assertCount(1, $output['pages']);
        $this->assertGreaterThan(0, $output['summary']['block_count']);
    }

    public function test_blocks_have_required_text_layers(): void
    {
        $extractor = new FastDocxExtractor;
        $output = $extractor->extract($this->docxPath, 'doc-test-002');

        $firstBlock = $output['pages'][0]['blocks'][0];
        $this->assertArrayHasKey('raw_text', $firstBlock);
        $this->assertArrayHasKey('normalized_text', $firstBlock);
        $this->assertArrayHasKey('ai_suggested_text', $firstBlock);
        $this->assertArrayHasKey('approved_text', $firstBlock);
        $this->assertSame($firstBlock['raw_text'], $firstBlock['normalized_text']);
        $this->assertSame($firstBlock['raw_text'], $firstBlock['approved_text']);
    }

    public function test_blocks_carry_fast_extracted_flag(): void
    {
        $extractor = new FastDocxExtractor;
        $output = $extractor->extract($this->docxPath, 'doc-test-003');

        foreach ($output['pages'][0]['blocks'] as $block) {
            $this->assertContains('fast_extracted', $block['flags']);
        }
    }

    public function test_extracts_title_block(): void
    {
        $extractor = new FastDocxExtractor;
        $output = $extractor->extract($this->docxPath, 'doc-test-004');

        $titleBlock = $output['pages'][0]['blocks'][0];
        $this->assertSame('title', $titleBlock['type']);
        $this->assertStringContainsString('ประกาศมหาวิทยาลัยบูรพา', $titleBlock['raw_text']);
    }

    public function test_extracts_table_block(): void
    {
        $extractor = new FastDocxExtractor;
        $output = $extractor->extract($this->docxPath, 'doc-test-005');

        $tableBlocks = array_filter(
            $output['pages'][0]['blocks'],
            static fn (array $block): bool => $block['type'] === 'table',
        );

        $this->assertCount(1, $tableBlocks);

        $tableBlock = array_values($tableBlocks)[0];
        $this->assertArrayHasKey('table', $tableBlock['meta']);
        $this->assertCount(2, $tableBlock['meta']['table']['rows']);
    }

    public function test_preserves_bold_formatting(): void
    {
        $extractor = new FastDocxExtractor;
        $output = $extractor->extract($this->docxPath, 'doc-test-006');

        $boldBlock = null;
        foreach ($output['pages'][0]['blocks'] as $block) {
            if (str_contains($block['raw_text'], 'เรื่อง หลักเกณฑ์')) {
                $boldBlock = $block;
                break;
            }
        }

        $this->assertNotNull($boldBlock, 'Bold block not found');
        $this->assertTrue((bool) ($boldBlock['meta']['formatting']['bold'] ?? false));
    }

    public function test_preserves_indent_on_list_items(): void
    {
        $extractor = new FastDocxExtractor;
        $output = $extractor->extract($this->docxPath, 'doc-test-007');

        $indentedBlock = null;
        foreach ($output['pages'][0]['blocks'] as $block) {
            if (str_contains($block['raw_text'], 'ข้อ 1.1')) {
                $indentedBlock = $block;
                break;
            }
        }

        $this->assertNotNull($indentedBlock, 'Indented block not found');
        $this->assertSame(720, $indentedBlock['meta']['layout']['indent_left']);
    }

    public function test_emits_image_block_when_images_dir_given(): void
    {
        $docx = sys_get_temp_dir().'/fast-img-'.uniqid('', true).'.docx';
        $imagesDir = sys_get_temp_dir().'/fast-img-out-'.uniqid('', true);

        $body = '<w:p><w:r><w:drawing><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:blip r:embed="rId10" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
            .'</a:graphic></w:drawing></w:r></w:p>'
            .'<w:p><w:r><w:t>หลังรูป</w:t></w:r></w:p>';
        buildRawDocx($docx, $body);

        $zip = new \ZipArchive;
        $zip->open($docx);
        $zip->addFromString('word/media/image1.png', "\x89PNG\r\n\x1a\nFAKE");
        $zip->addFromString(
            'word/_rels/document.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId10" '
            .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
            .'Target="media/image1.png"/></Relationships>'
        );
        $zip->close();

        $output = (new FastDocxExtractor)->extract($docx, 'doc-img-9', $imagesDir);

        $imageBlocks = array_values(array_filter(
            $output['pages'][0]['blocks'],
            static fn (array $block): bool => $block['type'] === 'image',
        ));

        $this->assertCount(1, $imageBlocks);
        $this->assertSame(
            '/api/documents/doc-img-9/images/image1.png',
            $imageBlocks[0]['meta']['image']['src_url'],
        );

        @unlink($docx);
        @array_map('unlink', glob($imagesDir.'/*') ?: []);
        @rmdir($imagesDir);
    }
}
