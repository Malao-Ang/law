<?php

namespace Tests\Feature;

use App\Jobs\CorrectDocumentJob;
use App\Jobs\IngestRagJob;
use App\Services\ReviewStore;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExportGatingTest extends TestCase
{
    public function test_export_returns_409_when_correction_pending(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'doc-export-gate-001';

        $store->setStatus($documentId, [
            'status' => 'done',
            'extraction_engine' => 'fast',
            'correction_status' => 'pending',
        ]);
        $store->writeReviewDocument($documentId, ['document_id' => $documentId, 'pages' => []]);

        $response = $this->postJson("/api/documents/{$documentId}/export");

        $response->assertStatus(409);
        $response->assertJsonFragment(['error_code' => 'CORRECTION_PENDING']);
    }

    public function test_export_proceeds_with_warning_when_correction_failed(): void
    {
        Queue::fake([IngestRagJob::class]);

        $store = app(ReviewStore::class);
        $documentId = 'doc-export-gate-004';

        $store->setStatus($documentId, [
            'status' => 'done',
            'extraction_engine' => 'fast',
            'correction_status' => 'failed',
        ]);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'x.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'pages' => [],
            'summary' => ['page_count' => 0, 'block_count' => 0, 'review_required_count' => 0],
        ]);

        $response = $this->postJson("/api/documents/{$documentId}/export");

        $response->assertStatus(200);
        $response->assertJsonFragment(['correction_warning' => true]);
    }

    public function test_retry_correction_queues_job_and_resets_status(): void
    {
        Queue::fake([CorrectDocumentJob::class]);

        $store = app(ReviewStore::class);
        $documentId = 'doc-export-gate-005';

        $store->setStatus($documentId, [
            'status' => 'done',
            'extraction_engine' => 'fast',
            'correction_status' => 'failed',
        ]);

        $response = $this->postJson("/api/documents/{$documentId}/retry-correction");

        $response->assertStatus(200);
        $response->assertJsonFragment(['correction_status' => 'pending']);

        Queue::assertPushed(CorrectDocumentJob::class, function (CorrectDocumentJob $job) use ($documentId): bool {
            return $job->documentId === $documentId && $job->enableAiCorrection === true;
        });
    }

    public function test_retry_correction_returns_409_when_already_running(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'doc-export-gate-006';

        $store->setStatus($documentId, [
            'status' => 'done',
            'correction_status' => 'in_progress',
        ]);

        $response = $this->postJson("/api/documents/{$documentId}/retry-correction");

        $response->assertStatus(409);
    }

    public function test_export_proceeds_when_correction_done(): void
    {
        Queue::fake([IngestRagJob::class]);

        $store = app(ReviewStore::class);
        $documentId = 'doc-export-gate-002';

        $store->setStatus($documentId, [
            'status' => 'done',
            'extraction_engine' => 'fast',
            'correction_status' => 'done',
        ]);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'x.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'pages' => [],
            'summary' => ['page_count' => 0, 'block_count' => 0, 'review_required_count' => 0],
        ]);

        $response = $this->postJson("/api/documents/{$documentId}/export");

        $response->assertStatus(200);
    }

    public function test_export_proceeds_when_correction_not_required(): void
    {
        Queue::fake([IngestRagJob::class]);

        $store = app(ReviewStore::class);
        $documentId = 'doc-export-gate-003';

        $store->setStatus($documentId, [
            'status' => 'done',
            'extraction_engine' => 'standard',
            'correction_status' => 'not_required',
        ]);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'x.pdf',
            'source_type' => 'pdf_text',
            'language' => 'th',
            'pages' => [],
            'summary' => ['page_count' => 0, 'block_count' => 0, 'review_required_count' => 0],
        ]);

        $response = $this->postJson("/api/documents/{$documentId}/export");

        $response->assertStatus(200);
    }
}
