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

    public function test_download_param_returns_attachment_disposition(): void
    {
        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $stored = $store->storeUpload(UploadedFile::fake()->create('test.pdf', 10, 'application/pdf'), $id);
        $store->createHistoricalStub($id, $stored['source_file'], ['source' => 'internal', 'law_type' => 'ประกาศ']);
        $store->setStatus($id, [
            'status' => 'done',
            'document_type' => 'old',
            'source_path' => $stored['relative_path'],
            'source_file' => $stored['source_file'],
        ]);

        $response = $this->get("/api/documents/{$id}/file?download=1");

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $store->deleteDocument($id);
    }

    public function test_streams_stored_docx_with_word_mime(): void
    {
        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $stored = $store->storeUpload(UploadedFile::fake()->create('original.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), $id);
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => $stored['source_file'],
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
            'relations' => [],
        ]);
        $store->setStatus($id, [
            'status' => 'done',
            'source_path' => $stored['relative_path'],
            'source_file' => $stored['source_file'],
        ]);

        $this->get("/api/documents/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $store->deleteDocument($id);
    }

    public function test_related_file_404_when_no_relation(): void
    {
        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'x.pdf',
            'source_type' => 'pdf_text',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
            'relations' => [],
        ]);
        $store->setStatus($id, ['status' => 'done']);

        $this->get("/api/documents/{$id}/related/nonexistent/file")
            ->assertNotFound();

        $store->deleteDocument($id);
    }

    public function test_related_file_serves_file_when_relation_exists(): void
    {
        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $targetId = $store->generateDocumentId();
        $stored = $store->storeUpload(UploadedFile::fake()->create('related.pdf', 10, 'application/pdf'), $targetId);

        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'parent.pdf',
            'source_type' => 'pdf_text',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
            'relations' => [[
                'id' => 'rel_1',
                'scope' => 'document',
                'type' => 'related',
                'target_document_id' => $targetId,
                'target_title' => 'Related document',
            ]],
        ]);
        $store->setStatus($id, ['status' => 'done']);
        $store->createHistoricalStub($targetId, $stored['source_file'], ['source' => 'internal', 'law_type' => 'ประกาศ']);
        $store->setStatus($targetId, [
            'status' => 'done',
            'document_type' => 'old',
            'source_path' => $stored['relative_path'],
            'source_file' => $stored['source_file'],
        ]);

        $this->get("/api/documents/{$id}/related/{$targetId}/file?download=1")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $store->deleteDocument($id);
        $store->deleteDocument($targetId);
    }
}
