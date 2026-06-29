<?php

namespace Tests\Feature;

use App\Services\Fast\FastDocxExtractor;
use Tests\TestCase;

require_once __DIR__.'/../Fixtures/Fast/build_raw_docx.php';

class FastDocxFidelityTest extends TestCase
{
    public function test_fast_docx_extractor_preserves_tabs_indents_tables_and_numbering(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'fast-fidelity-').'.docx';

        buildRawDocx(
            $out,
            '<w:p><w:pPr><w:jc w:val="center" /></w:pPr><w:r><w:t>ประกาศ</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:tabs><w:tab w:val="left" w:pos="1440" /></w:tabs></w:pPr>'
            .'<w:r><w:t>วันที่</w:t></w:r><w:r><w:tab /></w:r><w:r><w:t>21 มีนาคม 2569</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:ind w:left="720" w:firstLine="720" /></w:pPr><w:r><w:t>คำนิยาม ทดสอบ</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:numPr><w:ilvl w:val="0" /><w:numId w:val="1" /></w:numPr></w:pPr>'
            .'<w:r><w:t>รายการตัวอย่าง</w:t></w:r></w:p>'
            .'<w:tbl>'
            .'<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart" /></w:tcPr><w:p><w:r><w:t>MERGED</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:tcPr><w:gridSpan w:val="2" /></w:tcPr><w:p><w:r><w:t>HEADER</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:tcPr><w:vMerge /></w:tcPr><w:p /></w:tc>'
            .'<w:tc><w:p><w:r><w:t>B1</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>B2</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>',
            '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:start w:val="1" /><w:numFmt w:val="thaiNumbers" />'
            .'<w:lvlText w:val="(%1)" /><w:pPr><w:ind w:left="720" w:hanging="360" /></w:pPr></w:lvl></w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0" /></w:num>',
        );

        $output = (new FastDocxExtractor)->extract($out, 'doc-fast-fidelity');
        $blocks = $output['pages'][0]['blocks'];

        $this->assertSame(['title', 'paragraph', 'paragraph', 'list_item', 'table'], array_column($blocks, 'type'));
        $this->assertSame("วันที่\t21 มีนาคม 2569", $blocks[1]['raw_text']);
        $this->assertSame([['position' => 1440, 'type' => 'left']], $blocks[1]['meta']['layout']['tabs']);
        $this->assertSame(720, $blocks[2]['meta']['layout']['indent_left']);
        $this->assertSame(720, $blocks[2]['meta']['layout']['indent_first_line']);
        $this->assertSame('(๑) รายการตัวอย่าง', $blocks[3]['raw_text']);
        $this->assertSame(360, $blocks[3]['meta']['layout']['indent_hanging']);
        $this->assertSame(2, $blocks[4]['meta']['table']['cells'][0][0]['rowspan']);
        $this->assertSame(2, $blocks[4]['meta']['table']['cells'][0][1]['colspan']);
        $this->assertStringContainsString('rowspan="2"', $blocks[4]['meta']['table']['html']);
        $this->assertStringContainsString('colspan="2"', $blocks[4]['meta']['table']['html']);

        @unlink($out);
    }
}
