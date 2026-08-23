<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DocumentFileTest extends TestCase
{
    public function test_streams_stored_pdf(): void
    {
        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $stored = $store->storeUpload(UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'), $id);
        $store->createHistoricalStub($id, $stored['source_file'], ['source' => 'internal', 'law_type' => 'ประกาศ']);
        $store->setStatus($id, ['status' => 'done', 'document_type' => 'old', 'source_path' => $stored['relative_path']]);

        $this->get("/api/documents/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $store->deleteDocument($id);
    }

    public function test_404_for_missing_document(): void
    {
        $this->get('/api/documents/doc_nope/file')->assertNotFound();
    }
}
