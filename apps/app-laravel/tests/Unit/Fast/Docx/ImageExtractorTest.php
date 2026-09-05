<?php

namespace Tests\Unit\Fast\Docx;

use App\Services\Fast\Docx\DocxArchive;
use App\Services\Fast\Docx\ImageExtractor;
use App\Services\Fast\Docx\WordXml;
use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once __DIR__.'/../../../Fixtures/Fast/build_raw_docx.php';

class ImageExtractorTest extends TestCase
{
    private string $docxPath;

    private string $imagesDir;

    protected function setUp(): void
    {
        $this->docxPath = sys_get_temp_dir().'/img-docx-'.uniqid('', true).'.docx';
        $this->imagesDir = sys_get_temp_dir().'/img-out-'.uniqid('', true);

        $body = '<w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<wp:extent cx="1080000" cy="720000"/>'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:graphicData><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:blipFill><a:blip r:embed="rId10" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
            .'</pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

        buildRawDocx($this->docxPath, $body);

        $zip = new ZipArchive;
        $zip->open($this->docxPath);
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
    }

    protected function tearDown(): void
    {
        @unlink($this->docxPath);
        @array_map('unlink', glob($this->imagesDir.'/*') ?: []);
        @rmdir($this->imagesDir);
    }

    public function test_extracts_inline_image_to_disk_and_returns_meta(): void
    {
        $archive = new DocxArchive($this->docxPath);
        $document = $archive->documentXml();
        $xpath = WordXml::createXPath($document);
        $paragraph = $xpath->query('//w:p')->item(0);

        $this->assertNotNull($paragraph);

        $extractor = new ImageExtractor($archive, 'doc-img-1', $this->imagesDir);
        $images = $extractor->fromParagraph($paragraph);

        $this->assertCount(1, $images);
        $meta = $images[0];
        $this->assertSame('/api/documents/doc-img-1/images/image1.png', $meta['src_url']);
        $this->assertSame(3.0, $meta['docx_width_cm']);
        $this->assertSame(2.0, $meta['docx_height_cm']);
        $this->assertFileExists($this->imagesDir.'/image1.png');
        $this->assertSame("\x89PNG\r\n\x1a\nFAKE", file_get_contents($this->imagesDir.'/image1.png'));
    }

    public function test_extracts_image_paragraph_alignment(): void
    {
        $docx = sys_get_temp_dir().'/img-align-'.uniqid('', true).'.docx';
        $imagesDir = sys_get_temp_dir().'/img-align-out-'.uniqid('', true);
        $body = '<w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:drawing>'
            .'<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<wp:extent cx="720000" cy="360000"/>'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:blip r:embed="rId10" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
            .'</a:graphic></wp:inline></w:drawing></w:r></w:p>';
        buildRawDocx($docx, $body);

        $zip = new ZipArchive;
        $zip->open($docx);
        $zip->addFromString('word/media/image1.png', "\x89PNG\r\n\x1a\nFAKE");
        $zip->addFromString(
            'word/_rels/document.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId10" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
            .'</Relationships>',
        );
        $zip->close();

        $archive = new DocxArchive($docx);
        $paragraph = WordXml::createXPath($archive->documentXml())->query('//w:p')->item(0);
        $images = (new ImageExtractor($archive, 'doc-img-align', $imagesDir))->fromParagraph($paragraph);

        $this->assertSame('right', $images[0]['alignment']);

        @unlink($docx);
        @array_map('unlink', glob($imagesDir.'/*') ?: []);
        @rmdir($imagesDir);
    }

    public function test_returns_empty_when_no_images(): void
    {
        $archive = new DocxArchive($this->docxPath);
        $document = $archive->documentXml();
        $paragraph = $document->createElementNS(WordXml::WORD_NS, 'w:p');

        $extractor = new ImageExtractor($archive, 'doc-img-2', $this->imagesDir);
        $this->assertSame([], $extractor->fromParagraph($paragraph));
    }
}
