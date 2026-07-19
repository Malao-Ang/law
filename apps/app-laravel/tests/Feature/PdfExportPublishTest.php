<?php

namespace Tests\Feature;

use App\Jobs\IngestRagJob;
use App\Services\DocumentExportService;
use App\Services\ReviewStore;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PdfExportPublishTest extends TestCase
{
    public function test_pdf_export_dispatches_ingest_job_and_sets_public(): void
    {
        Queue::fake();

        $store = app(ReviewStore::class);
        $docId = 'test-pdf-publish-'.uniqid();
        $store->writeReviewDocument($docId, [
            'document_id' => $docId,
            'source_file' => 'test.pdf',
            'pages' => [],
            'law_meta' => ['access_scope' => 'private'],
        ]);
        $store->setStatus($docId, ['status' => 'done']);

        $this->mock(DocumentExportService::class, function ($mock) {
            $mock->shouldReceive('toPdf')->andReturn('%PDF-1.4 fake');
            $mock->shouldReceive('safeFilenameBase')->andReturn('test');
        });

        $response = $this->postJson("/api/documents/{$docId}/export-pdf");

        $response->assertStatus(200);
        Queue::assertPushed(IngestRagJob::class, fn ($job) => $job->documentId === $docId);

        $doc = $store->getReviewDocument($docId);
        $this->assertSame('public', $doc['law_meta']['access_scope']);
    }
}
