<?php

namespace Tests\Unit\Fast\Docx;

use Tests\TestCase;
use ZipArchive;

require_once __DIR__.'/../../../Fixtures/Fast/build_raw_docx.php';

class BuildRawDocxTest extends TestCase
{
    public function test_builds_readable_docx_with_custom_document_xml(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'raw-docx-').'.docx';

        buildRawDocx($out, '<w:p><w:r><w:t>hello</w:t></w:r></w:p>');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($out) === true);
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertIsString($documentXml);
        $this->assertStringContainsString('<w:t>hello</w:t>', $documentXml);
        $this->assertStringContainsString('xmlns:w=', $documentXml);

        @unlink($out);
    }

    public function test_includes_numbering_xml_when_provided(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'raw-docx-').'.docx';

        buildRawDocx($out, '<w:p/>', '<w:abstractNum w:abstractNumId="0"/>');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($out) === true);
        $numbering = $zip->getFromName('word/numbering.xml');
        $zip->close();

        $this->assertIsString($numbering);
        $this->assertStringContainsString('w:abstractNumId="0"', $numbering);

        @unlink($out);
    }
}
