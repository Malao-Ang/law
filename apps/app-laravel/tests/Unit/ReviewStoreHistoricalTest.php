<?php

namespace Tests\Unit;

use App\Services\ReviewStore;
use Tests\TestCase;

class ReviewStoreHistoricalTest extends TestCase
{
    public function test_stub_seeds_document_type_and_source(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_test_'.uniqid();

        $store->createHistoricalStub($id, 'old.pdf', [
            'source' => 'internal',
            'law_type' => 'ประกาศ',
        ]);

        $review = $store->getReviewDocument($id);
        $this->assertSame('old', $review['law_meta']['document_type']);
        $this->assertSame('internal', $review['law_meta']['source']);
        $this->assertSame('ประกาศ', $review['law_meta']['law_type']);
        $this->assertSame([], $review['pages']);

        $store->deleteDocument($id);
    }

    public function test_defaults_backfill_new_document_type(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_test_'.uniqid();
        $store->writeReviewDocument($id, ['document_id' => $id, 'pages' => []]);

        $review = $store->getReviewDocument($id);
        $this->assertSame('new', $review['law_meta']['document_type']);
        $this->assertSame('', $review['law_meta']['source']);

        $store->deleteDocument($id);
    }
}
