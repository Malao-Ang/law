<?php

namespace Tests\Unit\Fast\Docx;

use App\Services\Fast\Docx\DocxArchive;
use Tests\TestCase;

require_once __DIR__.'/../../../Fixtures/Fast/build_raw_docx.php';

class DocxArchiveTest extends TestCase
{
    public function test_loads_document_and_optional_numbering_xml(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'archive-docx-').'.docx';

        buildRawDocx(
            $out,
            '<w:p><w:r><w:t>body</w:t></w:r></w:p>',
            '<w:abstractNum w:abstractNumId="0"/>',
        );

        $archive = new DocxArchive($out);
        $documentXml = $archive->documentXml()->saveXML();
        $numberingXml = $archive->numberingXml()?->saveXML();

        $this->assertIsString($documentXml);
        $this->assertStringContainsString('<w:t>body</w:t>', $documentXml);
        $this->assertIsString($numberingXml);
        $this->assertStringContainsString('w:abstractNumId="0"', $numberingXml);

        @unlink($out);
    }

    public function test_builds_relationship_map(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'archive-docx-').'.docx';
        buildRawDocx($out, '<w:p/>');

        $archive = new DocxArchive($out);

        $this->assertSame([], $archive->relationshipMap());

        @unlink($out);
    }

    public function test_binary_returns_media_bytes_and_null_for_missing(): void
    {
        $path = sys_get_temp_dir().'/docx-bin-'.uniqid('', true).'.docx';
        buildRawDocx($path, '<w:p><w:r><w:t>hi</w:t></w:r></w:p>');

        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('word/media/image1.png', "\x89PNG\r\n\x1a\nFAKE");
        $zip->close();

        $archive = new DocxArchive($path);
        $this->assertSame("\x89PNG\r\n\x1a\nFAKE", $archive->binary('word/media/image1.png'));
        $this->assertNull($archive->binary('word/media/missing.png'));

        @unlink($path);
    }
}
