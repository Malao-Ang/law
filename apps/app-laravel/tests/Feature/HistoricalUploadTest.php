<?php

namespace Tests\Feature;

use App\Jobs\IndexHistoricalDocumentJob;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HistoricalUploadTest extends TestCase
{
    public function test_old_upload_creates_stub_marks_done_and_dispatches_indexing(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('old.pdf', 40, 'application/pdf'),
            'document_type' => 'old',
            'source' => 'internal',
            'law_type' => 'ประกาศ',
        ]);

        $response->assertStatus(202);
        $id = $response->json('document_id');

        $store = app(ReviewStore::class);
        $status = $store->getStatus($id);
        $this->assertSame('done', $status['status']);
        $this->assertSame('old', $status['document_type']);

        $review = $store->getReviewDocument($id);
        $this->assertSame('internal', $review['law_meta']['source']);

        Queue::assertPushed(IndexHistoricalDocumentJob::class);

        $store->deleteDocument($id);
    }
}
