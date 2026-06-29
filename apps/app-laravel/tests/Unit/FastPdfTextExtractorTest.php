<?php

namespace Tests\Unit;

use App\Services\Fast\FastPathUnsupportedException;
use App\Services\Fast\FastPdfTextExtractor;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../Fixtures/Fast/build_test_pdf.php';

class FastPdfTextExtractorTest extends TestCase
{
    private string $samplePdf;

    protected function setUp(): void
    {
        $this->samplePdf = sys_get_temp_dir().'/fast-pdf-'.uniqid('', true).'.pdf';
        buildTestPdf($this->samplePdf, 'Hello PDF text block');
    }

    protected function tearDown(): void
    {
        @unlink($this->samplePdf);
    }

    public function test_extracts_text_blocks_from_text_pdf(): void
    {
        $extractor = new FastPdfTextExtractor(minTextLength: 5);
        $output = $extractor->extract($this->samplePdf, 'pdf-test-001');

        $this->assertSame('pdf-test-001', $output['document_id']);
        $this->assertSame('pdf_text', $output['source_type']);
        $this->assertGreaterThan(0, $output['summary']['page_count']);
        $this->assertGreaterThan(0, $output['summary']['block_count']);
    }

    public function test_blocks_carry_fast_extracted_flag(): void
    {
        $extractor = new FastPdfTextExtractor(minTextLength: 5);
        $output = $extractor->extract($this->samplePdf, 'pdf-test-002');

        $firstBlock = $output['pages'][0]['blocks'][0];
        $this->assertContains('fast_extracted', $firstBlock['flags']);
        $this->assertSame($firstBlock['raw_text'], $firstBlock['approved_text']);
    }

    public function test_throws_when_pdf_has_no_extractable_text(): void
    {
        $scanPath = sys_get_temp_dir().'/empty-'.uniqid('', true).'.pdf';
        buildTestPdf($scanPath, '');

        $extractor = new FastPdfTextExtractor(minTextLength: 100);

        try {
            $extractor->extract($scanPath, 'pdf-test-003');
            $this->fail('Expected FastPathUnsupportedException');
        } catch (FastPathUnsupportedException $exception) {
            $this->assertSame('pdf_scan', $exception->detectedType);
            $this->assertStringContainsString('too little text', $exception->reason);
        } finally {
            @unlink($scanPath);
        }
    }
}
