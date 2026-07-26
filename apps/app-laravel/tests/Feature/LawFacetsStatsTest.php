<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LawFacetsStatsTest extends TestCase
{
    private function seedDocument(ReviewStore $store, string $id, array $meta): void
    {
        $store->setStatus($id, ['status' => 'ingested', 'source_file' => $id.'.docx']);
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => $id.'.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => array_merge(['title' => $id, 'access_scope' => 'public'], $meta),
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_facets_include_parent_and_child_counts(): void
    {
        $store = app(ReviewStore::class);
        $parent = 'p_'.uniqid();
        $child = 'c_'.uniqid();

        $this->seedDocument($store, $parent, ['law_type' => 'พระราชบัญญัติ']);
        $this->seedDocument($store, $child, ['law_type' => 'ระเบียบ', 'parent_document_id' => $parent]);

        cache()->forget('law-meta-list');

        $res = $this->getJson('/api/laws/facets');

        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, $res->json('stats.parent_laws'));
        $this->assertGreaterThanOrEqual(1, $res->json('stats.child_laws'));
    }
}
