<?php

namespace Tests\Unit;

use App\Services\ReviewStore;
use Tests\TestCase;

class ReviewStorePatchLawMetaTest extends TestCase
{
    public function test_patch_law_meta_sets_access_scope_public(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-patch-law-meta-'.uniqid();

        $store->writeReviewDocument($docId, [
            'document_id' => $docId,
            'pages' => [],
            'law_meta' => ['access_scope' => 'private'],
        ]);

        $store->patchLawMeta($docId, ['access_scope' => 'public']);

        $doc = $store->getReviewDocument($docId);
        $this->assertSame('public', $doc['law_meta']['access_scope']);
        $this->assertSame([], $doc['law_meta']['permission_group_ids'] ?? []);
    }

    public function test_patch_law_meta_merges_fields_without_overwriting_others(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-patch-law-meta-merge-'.uniqid();

        $store->writeReviewDocument($docId, [
            'document_id' => $docId,
            'pages' => [],
            'law_meta' => ['title' => 'Original Title', 'access_scope' => 'private'],
        ]);

        $store->patchLawMeta($docId, ['access_scope' => 'public']);

        $doc = $store->getReviewDocument($docId);
        $this->assertSame('Original Title', $doc['law_meta']['title']);
        $this->assertSame('public', $doc['law_meta']['access_scope']);
    }
}
