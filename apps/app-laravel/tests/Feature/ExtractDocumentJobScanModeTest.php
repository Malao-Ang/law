<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentJob;
use App\Services\DocumentPipelineClient;
use App\Services\Fast\FastExtractionPipeline;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ExtractDocumentJobScanModeTest extends TestCase
{
    public function test_gemini_pdf_skips_fast_path_and_calls_python_pipeline(): void
    {
        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();

        $stored = $store->storeUpload(
            UploadedFile::fake()->create('scan.pdf', 64, 'application/pdf'),
            $documentId,
        );

        $store->setStatus($documentId, [
            'status' => 'queued',
            'scan_extraction_mode_requested' => 'gemini',
            'extraction_engine' => 'standard',
        ]);

        /** @var DocumentPipelineClient&MockInterface $client */
        $client = Mockery::mock(DocumentPipelineClient::class);
        $client->shouldReceive('extract')
            ->once()
            ->withArgs(function (
                string $id,
                string $path,
                bool $enableAiCorrection,
                string $callbackUrl,
                string $scanExtractionMode,
            ) use ($documentId, $stored): bool {
                return $id === $documentId
                    && $path === $stored['relative_path']
                    && $scanExtractionMode === 'gemini';
            });

        /** @var FastExtractionPipeline&MockInterface $fastPipeline */
        $fastPipeline = Mockery::mock(FastExtractionPipeline::class);
        $fastPipeline->shouldNotReceive('run');

        $this->app->instance(DocumentPipelineClient::class, $client);

        $job = new ExtractDocumentJob(
            documentId: $documentId,
            relativeFilePath: $stored['relative_path'],
            enableAiCorrection: false,
            scanExtractionMode: 'gemini',
            extractionEngine: 'fast',
        );

        $job->handle($client, $store, $fastPipeline);

        $status = $store->getStatus($documentId);
        $this->assertSame('standard', $status['extraction_engine']);
        $this->assertSame('extract_document', $status['current_step']);
    }

    public function test_landingai_pdf_skips_fast_path_and_calls_python_pipeline(): void
    {
        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();

        $stored = $store->storeUpload(
            UploadedFile::fake()->create('scan.pdf', 64, 'application/pdf'),
            $documentId,
        );

        $store->setStatus($documentId, [
            'status' => 'queued',
            'scan_extraction_mode_requested' => 'landingai',
            'extraction_engine' => 'standard',
        ]);

        /** @var DocumentPipelineClient&MockInterface $client */
        $client = Mockery::mock(DocumentPipelineClient::class);
        $client->shouldReceive('extract')
            ->once()
            ->withArgs(function (
                string $id,
                string $path,
                bool $enableAiCorrection,
                string $callbackUrl,
                string $scanExtractionMode,
            ) use ($documentId, $stored): bool {
                return $id === $documentId
                    && $path === $stored['relative_path']
                    && $scanExtractionMode === 'landingai';
            });

        /** @var FastExtractionPipeline&MockInterface $fastPipeline */
        $fastPipeline = Mockery::mock(FastExtractionPipeline::class);
        $fastPipeline->shouldNotReceive('run');

        $this->app->instance(DocumentPipelineClient::class, $client);

        $job = new ExtractDocumentJob(
            documentId: $documentId,
            relativeFilePath: $stored['relative_path'],
            enableAiCorrection: false,
            scanExtractionMode: 'landingai',
            extractionEngine: 'fast',
        );

        $job->handle($client, $store, $fastPipeline);

        $status = $store->getStatus($documentId);
        $this->assertSame('standard', $status['extraction_engine']);
        $this->assertSame('extract_document', $status['current_step']);
    }

    public function test_docx_with_standard_engine_and_gemini_mode_still_uses_fast_path(): void
    {
        require_once __DIR__.'/../Fixtures/Fast/build_test_docx.php';

        $tmpDocx = tempnam(sys_get_temp_dir(), 'scan-mode-').'.docx';
        buildTestDocx($tmpDocx);

        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();

        $stored = $store->storeUpload(
            new UploadedFile($tmpDocx, 'sample.docx', null, null, true),
            $documentId,
        );

        /** @var DocumentPipelineClient&MockInterface $client */
        $client = Mockery::mock(DocumentPipelineClient::class);
        $client->shouldNotReceive('extract');

        $job = new ExtractDocumentJob(
            documentId: $documentId,
            relativeFilePath: $stored['relative_path'],
            enableAiCorrection: false,
            scanExtractionMode: 'gemini',
            extractionEngine: 'standard',
        );

        $job->handle($client, $store, app(FastExtractionPipeline::class));

        $review = $store->getReviewDocument($documentId);
        $this->assertSame('docx', $review['source_type']);

        $status = $store->getStatus($documentId);
        $this->assertSame('done', $status['status']);
        $this->assertSame('fast', $status['extraction_engine']);

        @unlink($tmpDocx);
    }
}
