<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LawMetaNewFieldsTest extends TestCase
{
    public function test_change_status_and_signer_group_persist(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'law_meta_new_'.uniqid();
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'x.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        $this->putJson("/api/documents/{$documentId}/document-review", [
            'law_meta' => ['change_status' => 'amended', 'signer_group' => 'คณะกรรมการ ก'],
        ])->assertOk();

        $doc = $store->getReviewDocument($documentId);
        $this->assertSame('amended', $doc['law_meta']['change_status']);
        $this->assertSame('คณะกรรมการ ก', $doc['law_meta']['signer_group']);
    }

    public function test_invalid_change_status_is_rejected(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'law_meta_bad_'.uniqid();
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'x.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        $this->putJson("/api/documents/{$documentId}/document-review", [
            'law_meta' => ['change_status' => 'not-a-real-status'],
        ])->assertStatus(422);
    }
}
