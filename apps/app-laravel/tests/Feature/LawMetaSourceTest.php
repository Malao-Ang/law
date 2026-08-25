<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LawMetaSourceTest extends TestCase
{
    private function seedDoc(ReviewStore $store, string $documentId): void
    {
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'x.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_source_persists_via_document_review(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'law_meta_source_'.uniqid();
        $this->seedDoc($store, $documentId);

        $this->putJson("/api/documents/{$documentId}/document-review", [
            'law_meta' => ['law_type' => 'พระราชบัญญัติ', 'source' => 'external'],
        ])->assertOk();

        $doc = $store->getReviewDocument($documentId);
        $this->assertSame('external', $doc['law_meta']['source']);

        $store->deleteDocument($documentId);
    }

    public function test_invalid_source_is_rejected(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'law_meta_source_bad_'.uniqid();
        $this->seedDoc($store, $documentId);

        $this->putJson("/api/documents/{$documentId}/document-review", [
            'law_meta' => ['source' => 'bogus'],
        ])->assertStatus(422);

        $store->deleteDocument($documentId);
    }
}
