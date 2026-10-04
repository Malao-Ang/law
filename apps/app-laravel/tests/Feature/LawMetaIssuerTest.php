<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LawMetaIssuerTest extends TestCase
{
    public function test_incoming_issuer_is_ignored_by_document_review(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'law_meta_issuer_'.uniqid();
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'x.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        $this->putJson("/api/documents/{$documentId}/document-review", [
            'law_meta' => ['law_type' => 'LTY09', 'issuer' => 'สภามหาวิทยาลัย'],
        ])->assertOk();

        $doc = $store->getReviewDocument($documentId);
        $this->assertArrayHasKey('issuer', $doc['law_meta']);
        $this->assertNull($doc['law_meta']['issuer']);
    }

    public function test_issuer_defaults_to_null_when_absent(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'law_meta_issuer_default_'.uniqid();
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'x.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        $this->putJson("/api/documents/{$documentId}/document-review", [
            'law_meta' => ['law_type' => 'LTY02'],
        ])->assertOk();

        $doc = $store->getReviewDocument($documentId);
        $this->assertArrayHasKey('issuer', $doc['law_meta']);
        $this->assertNull($doc['law_meta']['issuer']);
    }
}
