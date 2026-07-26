<?php

namespace Tests\Unit\Fast\Docx;

use App\Services\Fast\Docx\NumberingResolver;
use App\Services\Fast\Docx\ParagraphParser;
use DOMDocument;
use Tests\TestCase;

class ParagraphParserTest extends TestCase
{
    use LoadsWordXml;

    public function test_extracts_tabs_layout_and_formatting(): void
    {
        [, , $paragraph] = $this->loadWordFragment(
            '<w:pPr><w:tabs><w:tab w:val="left" w:pos="1440" /></w:tabs><w:jc w:val="justify" /></w:pPr>'
            .'<w:r><w:rPr><w:b /></w:rPr><w:t>วันที่</w:t></w:r>'
            .'<w:r><w:tab /></w:r>'
            .'<w:r><w:t>21 มีนาคม 2569</w:t></w:r>',
        );

        $parser = new ParagraphParser;
        $parsed = $parser->parse($paragraph, 2, new NumberingResolver(null));

        $this->assertNotNull($parsed);
        $this->assertSame("วันที่\t21 มีนาคม 2569", $parsed['text']);
        $this->assertSame('paragraph', $parsed['type']);
        $this->assertSame('justify', $parsed['layout']['alignment']);
        $this->assertSame([['position' => 1440, 'type' => 'left']], $parsed['layout']['tabs']);
        $this->assertTrue($parsed['formatting']['bold']);
    }

    public function test_infers_first_line_indent_from_leading_tab(): void
    {
        [, , $paragraph] = $this->loadWordFragment(
            '<w:pPr />'
            .'<w:r><w:tab /></w:r>'
            .'<w:r><w:t>บทบัญญัติทดสอบ</w:t></w:r>',
        );

        $parser = new ParagraphParser;
        $parsed = $parser->parse($paragraph, 5, new NumberingResolver(null));

        $this->assertNotNull($parsed);
        $this->assertSame('บทบัญญัติทดสอบ', $parsed['text']);
        $this->assertSame(720, $parsed['layout']['indent_first_line']);
        $this->assertSame('leading_tab', $parsed['layout']['first_line_inferred']);
    }

    public function test_classifies_centered_first_block_as_title(): void
    {
        [, , $paragraph] = $this->loadWordFragment(
            '<w:pPr><w:jc w:val="center" /></w:pPr>'
            .'<w:r><w:t>ประกาศ</w:t></w:r>',
        );

        $parser = new ParagraphParser;
        $parsed = $parser->parse($paragraph, 1, new NumberingResolver(null));

        $this->assertNotNull($parsed);
        $this->assertSame('title', $parsed['type']);
    }

    public function test_classifies_legal_heading_and_list_item_patterns(): void
    {
        [, , $heading] = $this->loadWordFragment('<w:r><w:t>มาตรา ๑ เรื่องทดสอบ</w:t></w:r>');
        [, , $listItem] = $this->loadWordFragment('<w:r><w:t>(ก) รายการย่อย</w:t></w:r>');

        $parser = new ParagraphParser;

        $parsedHeading = $parser->parse($heading, 3, new NumberingResolver(null));
        $parsedListItem = $parser->parse($listItem, 4, new NumberingResolver(null));

        $this->assertNotNull($parsedHeading);
        $this->assertNotNull($parsedListItem);
        $this->assertSame('section_header', $parsedHeading['type']);
        $this->assertSame('list_item', $parsedListItem['type']);
    }

    public function test_extractFormatting_reads_font_size_from_w_sz(): void
    {
        [, , $paragraph] = $this->loadWordFragment(
            '<w:r><w:rPr><w:sz w:val="28"/></w:rPr><w:t>ข้อความ</w:t></w:r>',
        );

        $parser = new ParagraphParser;
        $parsed = $parser->parse($paragraph, 1, new NumberingResolver(null));

        $this->assertNotNull($parsed);
        $this->assertSame(14.0, $parsed['formatting']['font_size_pt']);
    }

    public function test_extractFormatting_ignores_non_numeric_w_sz_val(): void
    {
        [, , $paragraph] = $this->loadWordFragment(
            '<w:r><w:rPr><w:sz w:val="bad"/></w:rPr><w:t>ข้อความ</w:t></w:r>',
        );

        $parser = new ParagraphParser;
        $parsed = $parser->parse($paragraph, 1, new NumberingResolver(null));

        $this->assertNotNull($parsed);
        $this->assertArrayNotHasKey('font_size_pt', $parsed['formatting']);
    }

    public function test_numbering_prefix_suppresses_leading_tab_inference(): void
    {
        $numberingXml = new DOMDocument;
        $numberingXml->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:start w:val="1" /><w:numFmt w:val="decimal" />'
            .'<w:lvlText w:val="%1." /><w:pPr><w:ind w:left="720" w:hanging="360" /></w:pPr></w:lvl></w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0" /></w:num>'
            .'</w:numbering>',
        );

        [, , $paragraph] = $this->loadWordFragment(
            '<w:pPr><w:numPr><w:ilvl w:val="0" /><w:numId w:val="1" /></w:numPr></w:pPr>'
            .'<w:r><w:t>รายการตัวอย่าง</w:t></w:r>',
        );

        $parser = new ParagraphParser;
        $parsed = $parser->parse($paragraph, 1, new NumberingResolver($numberingXml));

        $this->assertNotNull($parsed);
        $this->assertSame('1. รายการตัวอย่าง', $parsed['text']);
        $this->assertSame('list_item', $parsed['type']);
        $this->assertNull($parsed['layout']['first_line_inferred']);
        $this->assertSame(720, $parsed['layout']['indent_left']);
        $this->assertSame(360, $parsed['layout']['indent_hanging']);
    }
}
