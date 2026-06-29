<?php

namespace Tests\Unit\Fast\Docx;

use App\Services\Fast\Docx\ParagraphParser;
use App\Services\Fast\Docx\TableHtmlRenderer;
use App\Services\Fast\Docx\TableParser;
use Tests\TestCase;

class TableParserTest extends TestCase
{
    use LoadsWordXml;

    public function test_parses_merged_cells_and_table_text(): void
    {
        [, , $table] = $this->loadWordFragment(
            '<w:tr>'
            .'<w:tc><w:tcPr><w:vMerge w:val="restart" /></w:tcPr><w:p><w:r><w:t>MERGED</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:tcPr><w:gridSpan w:val="2" /></w:tcPr><w:p><w:r><w:t>HEADER</w:t></w:r></w:p></w:tc>'
            .'</w:tr>'
            .'<w:tr>'
            .'<w:tc><w:tcPr><w:vMerge /></w:tcPr><w:p /></w:tc>'
            .'<w:tc><w:p><w:r><w:t>B1</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>B2</w:t></w:r></w:p></w:tc>'
            .'</w:tr>',
            'w:tbl',
        );

        $parser = new TableParser(new ParagraphParser, new TableHtmlRenderer);
        $parsed = $parser->parse($table);

        $this->assertNotNull($parsed);
        $this->assertSame('MERGED', $parsed['cells'][0][0]['text']);
        $this->assertSame(2, $parsed['cells'][0][0]['rowspan']);
        $this->assertSame(2, $parsed['cells'][0][1]['colspan']);
        $this->assertSame("MERGED\tHEADER\nB1\tB2", $parsed['text']);
        $this->assertStringContainsString('rowspan="2"', $parsed['html']);
        $this->assertStringContainsString('colspan="2"', $parsed['html']);
    }

    public function test_parses_multi_paragraph_cell_in_reading_order(): void
    {
        [, , $table] = $this->loadWordFragment(
            '<w:tr><w:tc>'
            .'<w:p><w:r><w:t>First</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Second</w:t></w:r></w:p>'
            .'</w:tc></w:tr>',
            'w:tbl',
        );

        $parser = new TableParser(new ParagraphParser, new TableHtmlRenderer);
        $parsed = $parser->parse($table);

        $this->assertNotNull($parsed);
        $this->assertSame("First\nSecond", $parsed['cells'][0][0]['text']);
    }

    public function test_detects_images_inside_cells(): void
    {
        [, , $table] = $this->loadWordFragment(
            '<w:tr><w:tc xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:graphicData><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:blipFill><a:blip r:embed="rId1"/></pic:blipFill>'
            .'</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>'
            .'</w:tc></w:tr>',
            'w:tbl',
        );

        $parser = new TableParser(new ParagraphParser, new TableHtmlRenderer);
        $parsed = $parser->parse($table);

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed['cells'][0][0]['has_image']);
    }
}
