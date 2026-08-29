<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use App\Services\Search\LawSearchService;
use Tests\TestCase;

class LawSearchRestrictedTest extends TestCase
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
            'law_meta' => array_merge(['title' => $id, 'access_scope' => 'public', 'published_date' => '2565-01-01'], $meta),
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_private_docs_appear_as_restricted_teasers(): void
    {
        $this->mock(LawSearchService::class, fn ($mock) => $mock->shouldReceive('search')->andThrow(new \RuntimeException('es down')));

        $store = app(ReviewStore::class);
        $privateId = 'priv_'.uniqid();
        $this->seedDocument($store, $privateId, ['law_type' => 'ระเบียบ', 'access_scope' => 'private']);

        cache()->forget('law-meta-list');

        $res = $this->postJson('/api/laws/search', ['q' => '', 'filters' => []]);

        $res->assertOk();
        $hit = collect($res->json('results'))->firstWhere('law_id', $privateId);
        $this->assertNotNull($hit, 'private doc should appear as a teaser');
        $this->assertTrue($hit['restricted']);
        $this->assertNull($hit['summary']);
        $this->assertSame([], $hit['snippets']);
        $this->assertSame($privateId, $hit['law_id']);
    }

    public function test_child_types_populated_from_parent_link(): void
    {
        $this->mock(LawSearchService::class, fn ($mock) => $mock->shouldReceive('search')->andThrow(new \RuntimeException('es down')));

        $store = app(ReviewStore::class);
        $parent = 'par_'.uniqid();
        $this->seedDocument($store, $parent, ['law_type' => 'พระราชบัญญัติ']);
        $this->seedDocument($store, 'ch1_'.uniqid(), ['law_type' => 'ระเบียบ', 'parent_document_id' => $parent]);

        cache()->forget('law-meta-list');

        $res = $this->postJson('/api/laws/search', ['q' => '', 'filters' => []]);
        $hit = collect($res->json('results'))->firstWhere('law_id', $parent);

        $this->assertNotNull($hit);
        $this->assertSame(1, $hit['child_types']['rabiap'] ?? 0);
    }
}
