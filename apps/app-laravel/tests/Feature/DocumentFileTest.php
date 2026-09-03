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
        $stored = $store->storeUpload(\Illuminate\Http\UploadedFile::fake()->create('test.pdf', 10, 'application/pdf'), $id);
        $store->setStatus($id, [
            'status' => 'done',
            'source_path' => $stored['relative_path'],
            'source_file' => 'test.pdf',
        ]);

        $response = $this->get("/api/documents/{$id}/file?download=1");
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $store->deleteDocument($id);
    }

    public function test_docx_file_served_with_correct_mime(): void
    {
        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $stored = $store->storeUpload(
            \Illuminate\Http\UploadedFile::fake()->create('law.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            $id
        );
        $store->setStatus($id, [
            'status' => 'done',
            'source_path' => $stored['relative_path'],
            'source_file' => 'law.docx',
        ]);

        $response = $this->get("/api/documents/{$id}/file");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $store->deleteDocument($id);
    }

    public function test_related_file_404_when_no_relation(): void
    {
        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $store->setStatus($id, ['status' => 'done', 'source_file' => 'x.pdf']);
        $store->writeReviewDocument($id, [
            'document_id' => $id, 'source_file' => 'x.pdf', 'source_type' => 'pdf',
            'language' => 'th', 'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        $response = $this->get("/api/documents/{$id}/related/nonexistent-doc/file");
        $response->assertNotFound();

        $store->deleteDocument($id);
    }

    public function test_related_file_served_when_relation_exists(): void
    {
        $store = app(ReviewStore::class);

        // Source doc with a relation
        $sourceId = $store->generateDocumentId();
        $store->setStatus($sourceId, ['status' => 'done', 'source_file' => 'source.pdf']);
        $store->writeReviewDocument($sourceId, [
            'document_id' => $sourceId, 'source_file' => 'source.pdf', 'source_type' => 'pdf',
            'language' => 'th', 'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'relations' => [[
                'id' => 'rel-1', 'scope' => 'document', 'type' => 'related',
                'target_document_id' => 'TARGET_DOC_PLACEHOLDER',
                'target_title' => 'เอกสารที่เกี่ยวข้อง',
            ]],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        // Target doc with an actual file
        $targetId = $store->generateDocumentId();
        $stored = $store->storeUpload(\Illuminate\Http\UploadedFile::fake()->create('target.pdf', 5, 'application/pdf'), $targetId);
        $store->setStatus($targetId, [
            'status' => 'done',
            'source_path' => $stored['relative_path'],
            'source_file' => 'target.pdf',
        ]);

        // Update the relation to point at the real targetId
        $store->writeReviewDocument($sourceId, array_merge(
            $store->getReviewDocument($sourceId),
            ['relations' => [[
                'id' => 'rel-1', 'scope' => 'document', 'type' => 'related',
                'target_document_id' => $targetId,
                'target_title' => 'เอกสารที่เกี่ยวข้อง',
            ]]]
        ));

        $response = $this->get("/api/documents/{$sourceId}/related/{$targetId}/file");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        $store->deleteDocument($sourceId);
        $store->deleteDocument($targetId);
    }
}
