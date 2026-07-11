<?php

namespace Tests\Feature;

use App\Jobs\IngestRagJob;
use App\Services\RagIngestService;
use App\Services\Search\LawIndexer;
use App\Services\Search\LawSearchService;
use Tests\TestCase;

class LawSearchTest extends TestCase
{
    public function test_ingest_job_triggers_law_indexer(): void
    {
        $documentId = 'law_hook_'.uniqid();

        $this->mock(RagIngestService::class, fn ($mock) => $mock->shouldReceive('ingest')->once()->with($documentId)->andReturn([
            'ingest_path' => 'ingested/test.json',
            'chunk_count' => 1,
        ]));

        $indexer = \Mockery::mock(LawIndexer::class);
        $indexer->shouldReceive('index')->once()->with($documentId);
        $this->app->instance(LawIndexer::class, $indexer);

        (new IngestRagJob($documentId))->handle(app(RagIngestService::class), app(LawIndexer::class), app(\App\Services\ReviewStore::class));

        $this->assertTrue(true);
    }

    public function test_search_endpoint_returns_results_and_facets(): void
    {
        $fake = [
            'total' => 1,
            'results' => [[
                'law_id' => 'L1',
                'title' => 'พ.ร.บ.',
                'law_type' => 'phrb',
                'status' => 'active',
                'summary' => 's',
                'published_date' => '2565',
                'agency' => 'ก',
                'snippets' => ['<mark>ภาษี</mark>'],
            ]],
            'facets' => [
                'law_type' => [['value' => 'phrb', 'count' => 1]],
            ],
        ];

        $this->mock(LawSearchService::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn($fake));

        $this->postJson('/api/laws/search', ['q' => 'ภาษี', 'filters' => ['law_type' => ['phrb']]])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.law_id', 'L1')
            ->assertJsonPath('results.0.snippets.0', '<mark>ภาษี</mark>');
    }

    public function test_search_endpoint_returns_503_when_es_unavailable(): void
    {
        $this->mock(LawSearchService::class, fn ($mock) => $mock->shouldReceive('search')->andThrow(new \RuntimeException('no route to host')));

        $this->postJson('/api/laws/search', ['q' => 'x'])->assertStatus(503);
    }
}
