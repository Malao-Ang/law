<?php

namespace Tests\Unit\Fast\Docx;

use App\Services\Fast\Docx\NumberingResolver;
use DOMDocument;
use Tests\TestCase;

class NumberingResolverTest extends TestCase
{
    use LoadsWordXml;

    public function test_resolves_thai_numbering_and_indents(): void
    {
        $resolver = new NumberingResolver($this->numberingXml(
            '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:start w:val="1" />'
            .'<w:numFmt w:val="thaiNumbers" /><w:lvlText w:val="(%1)" />'
            .'<w:pPr><w:ind w:left="720" w:hanging="360" /></w:pPr></w:lvl></w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0" /></w:num>',
        ));

        [, , $paragraph] = $this->loadWordFragment(
            '<w:pPr><w:numPr><w:ilvl w:val="0" /><w:numId w:val="1" /></w:numPr></w:pPr>'
            .'<w:r><w:t>รายการตัวอย่าง</w:t></w:r>',
        );

        $resolved = $resolver->resolve($paragraph, [
            'indent_left' => null,
            'indent_hanging' => null,
            'indent_first_line' => null,
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame('(๑)', $resolved['prefix']);
        $this->assertSame(720, $resolved['layout']['indent_left']);
        $this->assertSame(360, $resolved['layout']['indent_hanging']);
    }

    public function test_resolves_multilevel_prefixes_statefully(): void
    {
        $resolver = new NumberingResolver($this->numberingXml(
            '<w:abstractNum w:abstractNumId="0">'
            .'<w:lvl w:ilvl="0"><w:start w:val="1" /><w:numFmt w:val="decimal" /><w:lvlText w:val="%1." /></w:lvl>'
            .'<w:lvl w:ilvl="1"><w:start w:val="1" /><w:numFmt w:val="decimal" /><w:lvlText w:val="%1.%2." /></w:lvl>'
            .'</w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0" /></w:num>',
        ));

        [, , $parent] = $this->loadWordFragment(
            '<w:pPr><w:numPr><w:ilvl w:val="0" /><w:numId w:val="1" /></w:numPr></w:pPr><w:r><w:t>A</w:t></w:r>',
        );
        [, , $child] = $this->loadWordFragment(
            '<w:pPr><w:numPr><w:ilvl w:val="1" /><w:numId w:val="1" /></w:numPr></w:pPr><w:r><w:t>B</w:t></w:r>',
        );

        $resolvedParent = $resolver->resolve($parent, []);
        $resolvedChild = $resolver->resolve($child, []);

        $this->assertNotNull($resolvedParent);
        $this->assertNotNull($resolvedChild);
        $this->assertSame('1.', $resolvedParent['prefix']);
        $this->assertSame('1.1.', $resolvedChild['prefix']);
    }

    private function numberingXml(string $innerXml): DOMDocument
    {
        $document = new DOMDocument;
        $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .$innerXml
            .'</w:numbering>',
        );

        return $document;
    }
}
