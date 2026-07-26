<?php

namespace Tests\Unit;

use App\Services\DocumentHtmlService;
use App\Services\Fast\FastDocxExtractor;
use App\Services\Fast\FastExtractionPipeline;
use App\Services\Fast\FastPathUnsupportedException;
use App\Services\Fast\FastPdfTextExtractor;
use App\Services\Fast\LibreOfficeConverter;
use App\Services\ReviewStore;
use App\Services\Storage\MongoBlobStore;
use MongoDB\Collection;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../Fixtures/Fast/build_test_docx.php';
require_once __DIR__.'/../Fixtures/Fast/build_test_pdf.php';

class FastExtractionPipelineTest extends TestCase
{
    private string $tmpRoot;

    private ReviewStore $reviewStore;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir().'/fast-pipeline-'.uniqid('', true);
        mkdir($this->tmpRoot.'/uploads', 0700, true);
        mkdir($this->tmpRoot.'/exports', 0700, true);
        mkdir($this->tmpRoot.'/ingested', 0700, true);
        mkdir($this->tmpRoot.'/pages', 0700, true);

        // ponytail: MongoBlobStore is final — fake the Collection it wraps instead
        $store = [];
        $col = $this->createMock(Collection::class);
        $col->method('findOne')->willReturnCallback(function (array $filter) use (&$store): ?array {
            $id = $filter['_id'] ?? null;

            return $id !== null && isset($store[(string) $id]) ? $store[(string) $id] : null;
        });
        $updateResult = $this->createMock(\MongoDB\UpdateResult::class);
        $updateResult->method('getMatchedCount')->willReturn(1);
        $col->method('updateOne')->willReturnCallback(function (array $filter, array $update) use (&$store, $updateResult): \MongoDB\UpdateResult {
            $id = (string) ($filter['_id'] ?? '');
            $existing = $store[$id] ?? ['_version' => 0];
            foreach ($update['$set'] ?? [] as $k => $v) {
                $existing[$k] = $v;
            }
            $existing['_version'] = ($existing['_version'] ?? 0) + 1;
            $store[$id] = $existing;

            return $updateResult;
        });
        $col->method('insertOne')->willReturnCallback(function (array $doc) use (&$store): void {
            $store[(string) $doc['_id']] = $doc;
        });
        $col->method('countDocuments')->willReturn(0);
        // ponytail: find() is not called by any test in this class — throw to surface accidental calls
        $col->method('find')->willThrowException(new \RuntimeException('find() not expected in FastExtractionPipelineTest'));

        $blob = new MongoBlobStore($col);
        $this->reviewStore = new ReviewStore(new DocumentHtmlService, $blob, $this->tmpRoot);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
    }

    public function test_extract_docx_writes_review_json(): void
    {
        $docxPath = $this->tmpRoot.'/uploads/sample.docx';
        buildTestDocx($docxPath);

        $pipeline = new FastExtractionPipeline(
            new LibreOfficeConverter,
            new FastDocxExtractor,
            new FastPdfTextExtractor(minTextLength: 5),
            $this->reviewStore,
        );

        $result = $pipeline->run('doc-pipe-001', 'uploads/sample.docx');

        $this->assertSame('docx', $result['source_type']);
        $this->assertSame('doc-pipe-001', $result['document_id']);
    }

    public function test_extract_pdf_text_routes_to_pdf_extractor(): void
    {
        buildTestPdf($this->tmpRoot.'/uploads/sample.pdf', 'Hello PDF text block');

        $pipeline = new FastExtractionPipeline(
            new LibreOfficeConverter,
            new FastDocxExtractor,
            new FastPdfTextExtractor(minTextLength: 5),
            $this->reviewStore,
        );

        $result = $pipeline->run('doc-pipe-002', 'uploads/sample.pdf');
        $this->assertSame('pdf_text', $result['source_type']);
    }

    public function test_unsupported_extension_throws(): void
    {
        file_put_contents($this->tmpRoot.'/uploads/bad.xyz', 'noop');

        $pipeline = new FastExtractionPipeline(
            new LibreOfficeConverter,
            new FastDocxExtractor,
            new FastPdfTextExtractor(minTextLength: 5),
            $this->reviewStore,
        );

        $this->expectException(FastPathUnsupportedException::class);
        $pipeline->run('doc-pipe-003', 'uploads/bad.xyz');
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
