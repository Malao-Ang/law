<?php

namespace Tests\Feature;

use App\Jobs\IngestRagJob;
use App\Services\ExportService;
use App\Services\RagIngestService;
use App\Services\ReviewStore;
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

        $exportService = \Mockery::mock(ExportService::class);
        $exportService->shouldNotReceive('export');

        $store = app(ReviewStore::class);
        // Ensure export file exists so auto-build is skipped
        $exportPath = $store->absolutePath($store->exportRelativePath($documentId));
        @mkdir(dirname($exportPath), 0775, true);
        file_put_contents($exportPath, json_encode(['chunks' => []]));

        (new IngestRagJob($documentId))->handle(app(RagIngestService::class), app(LawIndexer::class), $store, $exportService);

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

    public function test_search_endpoint_accepts_thai_change_status_filter(): void
    {
        $this->mock(LawSearchService::class, function ($mock): void {
            $mock->shouldReceive('search')
                ->once()
                ->with(\Mockery::on(fn (array $params): bool => ($params['filters']['change_status'] ?? null) === ['กฎหมายใหม่']))
                ->andReturn([
                    'total' => 0,
                    'results' => [],
                    'facets' => [],
                ]);
        });

        $this->postJson('/api/laws/search', [
            'q' => '',
            'filters' => ['change_status' => ['กฎหมายใหม่']],
        ])->assertOk();
    }

    public function test_search_falls_back_to_file_based_when_es_unavailable(): void
    {
        $this->mock(LawSearchService::class, fn ($mock) => $mock->shouldReceive('search')->andThrow(new \RuntimeException('no route to host')));

        // ES down but the file-based path still answers (200, not 503).
        $this->postJson('/api/laws/search', ['q' => 'x'])
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_file_based_search_matches_document_content_not_just_title(): void
    {
        // ES unavailable → controller uses the file-based fallback.
        $this->mock(LawSearchService::class, fn ($mock) => $mock->shouldReceive('search')->andThrow(new \RuntimeException('es down')));

        $store = app(ReviewStore::class);
        $documentId = 'law_content_'.uniqid();

        $store->setStatus($documentId, ['status' => 'ingested']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'pages' => [],
            'law_meta' => [
                'title' => 'ระเบียบทั่วไปว่าด้วยการเงิน',
                'law_type' => 'ระเบียบ',
                'status' => 'มีผลบังคับใช้',
                'access_scope' => 'public',
            ],
        ]);

        // The query word lives ONLY in the body, never in the title.
        $exportPath = $store->absolutePath($store->exportRelativePath($documentId));
        @mkdir(dirname($exportPath), 0775, true);
        file_put_contents($exportPath, json_encode([
            'chunks' => [['chunk_id' => 'c1', 'text' => 'ผู้เสียภาษีที่ดินและสิ่งปลูกสร้างต้องยื่นแบบภายในกำหนด']],
        ]));

        cache()->forget('law-meta-list');

        $response = $this->postJson('/api/laws/search', ['q' => 'ภาษีที่ดิน'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.law_id', $documentId);

        // Snippet is highlighted around the matched content.
        $snippet = $response->json('results.0.snippets.0');
        $this->assertStringContainsString('<mark>', (string) $snippet);
    }
}
