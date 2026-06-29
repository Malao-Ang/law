<?php

namespace Tests\Feature;

use App\Jobs\CorrectDocumentJob;
use App\Jobs\ExtractDocumentJob;
use App\Services\DocumentPipelineClient;
use App\Services\Fast\FastExtractionPipeline;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

require_once __DIR__.'/../Fixtures/Fast/build_test_docx.php';

class FastExtractionTest extends TestCase
{
    public function test_upload_with_fast_engine_dispatches_extract_job_with_fast_flag(): void
    {
        Queue::fake();

        $tmpDocx = tempnam(sys_get_temp_dir(), 'fast-up-').'.docx';
        buildTestDocx($tmpDocx);

        $uploaded = new UploadedFile(
            $tmpDocx,
            'sample.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );

        $response = $this->post('/api/documents', [
            'file' => $uploaded,
            'extraction_engine' => 'fast',
        ]);

        $response->assertStatus(202);
        $documentId = $response->json('document_id');
        $this->assertNotEmpty($documentId);

        Queue::assertPushed(ExtractDocumentJob::class, function (ExtractDocumentJob $job) use ($documentId): bool {
            return $job->documentId === $documentId
                && $job->extractionEngine === 'fast';
        });

        $status = app(ReviewStore::class)->getStatus($documentId);
        $this->assertSame('fast', $status['extraction_engine']);
        $this->assertSame('pending', $status['correction_status']);

        @unlink($tmpDocx);
    }

    public function test_fast_job_runs_pipeline_and_dispatches_correction(): void
    {
        Queue::fake([CorrectDocumentJob::class]);

        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();

        $tmpDocx = tempnam(sys_get_temp_dir(), 'fast-job-').'.docx';
        buildTestDocx($tmpDocx);

        $stored = $store->storeUpload(
            new UploadedFile($tmpDocx, 'job.docx', null, null, true),
            $documentId,
        );

        $store->setStatus($documentId, [
            'status' => 'queued',
            'source_file' => $stored['source_file'],
            'source_path' => $stored['relative_path'],
            'extraction_engine' => 'fast',
            'correction_status' => 'pending',
        ]);

        $job = new ExtractDocumentJob(
            documentId: $documentId,
            relativeFilePath: $stored['relative_path'],
            enableAiCorrection: true,
            scanExtractionMode: 'auto',
            extractionEngine: 'fast',
        );

        $job->handle(
            app(DocumentPipelineClient::class),
            $store,
            app(FastExtractionPipeline::class),
        );

        $review = $store->getReviewDocument($documentId);
        $this->assertSame($documentId, $review['document_id']);
        $this->assertSame('docx', $review['source_type']);

        Queue::assertPushed(CorrectDocumentJob::class, function (CorrectDocumentJob $job) use ($documentId): bool {
            return $job->documentId === $documentId;
        });

        @unlink($tmpDocx);
    }
}
