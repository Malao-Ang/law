<?php

namespace Tests\Feature;

use App\Services\DocumentExportService;
use App\Services\ReviewStore;
use Tests\TestCase;

class WordExportPublishTest extends TestCase
{
    public function test_word_export_sets_esign_exported_at_and_public_scope(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-word-publish-'.uniqid();
        $store->writeReviewDocument($docId, [
            'document_id' => $docId,
            'source_file' => 'test.docx',
            'pages' => [],
            'law_meta' => ['access_scope' => 'private'],
        ]);
        $store->setStatus($docId, ['status' => 'done']);

        $this->mock(DocumentExportService::class, function ($mock) {
            $mock->shouldReceive('toDocx')->andReturn('PK fake docx bytes');
            $mock->shouldReceive('safeFilenameBase')->andReturn('test');
        });

        $response = $this->postJson("/api/documents/{$docId}/export-word");

        $response->assertStatus(200);

        $doc = $store->getReviewDocument($docId);
        $this->assertSame('public', $doc['law_meta']['access_scope']);

        $status = $store->getStatus($docId);
        $this->assertNotNull($status['esign_exported_at'] ?? null);
    }
}
