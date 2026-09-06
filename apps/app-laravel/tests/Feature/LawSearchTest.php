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
                'confidence' => 0.93,
                'match_mode' => 'exact',
                'snippets' => ['<mark>ภาษี</mark>'],
            ]],
            'facets' => [
                'law_type' => [['value' => 'phrb', 'count' => 1]],
            ],
            'meta' => [
                'engine' => 'elastic',
                'mode' => 'exact',
                'confidence' => 0.93,
                'suggestions' => [],
            ],
        ];

        $this->mock(LawSearchService::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn($fake));

        $store = app(ReviewStore::class);
        $store->setStatus('L1', ['status' => 'ingested']);
        $store->writeReviewDocument('L1', [
            'document_id' => 'L1',
            'law_meta' => [
                'title' => 'พ.ร.บ.',
                'access_scope' => 'public',
                'published_date' => '2565-01-01',
            ],
            'pages' => [],
        ]);
        cache()->forget('law-meta-list');

        $this->postJson('/api/laws/search', ['q' => 'ภาษี', 'filters' => ['law_type' => ['phrb']]])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.law_id', 'L1')
            ->assertJsonPath('results.0.confidence', 0.93)
            ->assertJsonPath('meta.engine', 'elastic')
            ->assertJsonPath('meta.confidence', 0.93)
            ->assertJsonPath('results.0.snippets.0', '<mark>ภาษี</mark>');
    }

    public function test_search_endpoint_accepts_thai_change_status_filter(): void
    {
        $this->mock(LawSearchService::class, fn ($mock) => $mock
            ->shouldReceive('search')->never());

        $store = app(ReviewStore::class);
        $documentId = 'law_change_status_'.uniqid();
        $store->setStatus($documentId, ['status' => 'ingested']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'law_meta' => [
                'title' => 'ประกาศทดสอบ',
                'law_type' => 'ประกาศ',
                'change_status' => 'กฎหมายล่าสุด',
                'access_scope' => 'public',
                'published_date' => '2565-01-01',
            ],
            'pages' => [],
        ]);
        cache()->forget('law-meta-list');

        $response = $this->postJson('/api/laws/search', [
            'q' => '',
            'filters' => ['change_status' => ['กฎหมายล่าสุด']],
        ])
            ->assertOk();

        $this->assertContains($documentId, collect($response->json('results'))->pluck('law_id')->all());
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
        config()->set('search.file_heavy_details', true);

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
                'published_date' => '2565-01-01',
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
            ->assertJsonPath('results.0.law_id', $documentId)
            ->assertJsonPath('results.0.match_mode', 'file_exact')
            ->assertJsonPath('meta.engine', 'file');

        // Snippet is highlighted around the matched content.
        $snippet = $response->json('results.0.snippets.0');
        $this->assertStringContainsString('<mark>', (string) $snippet);
    }

    public function test_file_based_search_matches_review_block_content_without_export_file(): void
    {
        // ES unavailable → file-based fallback.
        $this->mock(LawSearchService::class, fn ($mock) => $mock->shouldReceive('search')->andThrow(new \RuntimeException('es down')));

        $store = app(ReviewStore::class);
        $documentId = 'law_noexport_'.uniqid();

        $store->setStatus($documentId, ['status' => 'ingested']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'law_meta' => ['title' => 'ประกาศมหาวิทยาลัย', 'access_scope' => 'public', 'published_date' => '2565-01-01'],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    ['block_id' => 'b1', 'type' => 'paragraph', 'approved_text' => 'ภาควิชานิเทศศาสตร์และการสื่อสาร'],
                ],
            ]],
        ]);

        // Deliberately NO export file written — content must come from review blocks.
        cache()->forget('law-meta-list');

        $this->postJson('/api/laws/search', ['q' => 'นิเทศศาสตร์'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.law_id', $documentId);
    }

    public function test_file_based_search_finds_nearby_title_when_query_has_typo(): void
    {
        $this->mock(LawSearchService::class, fn ($mock) => $mock->shouldReceive('search')->andThrow(new \RuntimeException('es down')));

        $store = app(ReviewStore::class);
        $documentId = 'law_fuzzy_'.uniqid();

        $store->setStatus($documentId, ['status' => 'ingested']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'pages' => [],
            'law_meta' => [
                'title' => 'ประกาศมหาวิทยาลัยว่าด้วยการทดสอบระบบ',
                'law_type' => 'ประกาศ',
                'status' => 'มีผลบังคับใช้',
                'access_scope' => 'public',
                'published_date' => '2565-01-01',
            ],
        ]);

        cache()->forget('law-meta-list');

        $this->postJson('/api/laws/search', ['q' => 'มหาวิทยาลับ'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.law_id', $documentId)
            ->assertJsonPath('results.0.match_mode', 'file_fuzzy')
            ->assertJsonPath('meta.mode', 'file_fuzzy');
    }

    public function test_metadata_only_document_is_found_by_keyword(): void
    {
        $this->mock(\App\Services\Search\LawSearchService::class, fn ($mock) => $mock
            ->shouldReceive('search')->andReturn(['total' => 0, 'results' => [], 'facets' => []]));

        $store = app(\App\Services\ReviewStore::class);
        $store->setStatus('OLD1', ['status' => 'ingested', 'document_type' => 'old']);
        $store->writeReviewDocument('OLD1', [
            'document_id' => 'OLD1',
            'law_meta' => [
                'title' => 'ระเบียบเก่า',
                'access_scope' => 'public',
                'published_date' => '2565-01-01',
                'keywords' => ['ภาษีป้าย'],
                'gazette_reference' => 'เล่ม 140 ตอนที่ 5',
            ],
            'pages' => [],
        ]);
        cache()->forget('law-meta-list');

        $this->postJson('/api/laws/search', ['q' => 'ภาษีป้าย'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.law_id', 'OLD1');

        $this->postJson('/api/laws/search', ['q' => 'ตอนที่ 5'])
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_results_expose_source_and_issuer(): void
    {
        $this->mock(\App\Services\Search\LawSearchService::class, fn ($mock) => $mock
            ->shouldReceive('search')->andReturn(['total' => 0, 'results' => [], 'facets' => []]));

        $store = app(\App\Services\ReviewStore::class);

        $store->setStatus('EXT1', ['status' => 'ingested']);
        $store->writeReviewDocument('EXT1', [
            'document_id' => 'EXT1',
            'law_meta' => ['title' => 'ประกาศกระทรวงการคลัง', 'access_scope' => 'public',
                'published_date' => '2565-01-01', 'law_type' => 'ประกาศกระทรวง'],
            'pages' => [],
        ]);

        $store->setStatus('PRK1', ['status' => 'ingested']);
        $store->writeReviewDocument('PRK1', [
            'document_id' => 'PRK1',
            'law_meta' => ['title' => 'ประกาศมหาวิทยาลัย', 'access_scope' => 'public',
                'published_date' => '2565-01-01', 'law_type' => 'ประกาศ', 'issuer' => 'มหาวิทยาลัย'],
            'pages' => [],
        ]);
        cache()->forget('law-meta-list');

        $response = $this->postJson('/api/laws/search', ['q' => ''])->assertOk();
        $bySource = collect($response->json('results'))->keyBy('law_id');

        $this->assertSame('external', $bySource['EXT1']['source']);
        $this->assertSame('internal', $bySource['PRK1']['source']);
        $this->assertSame('มหาวิทยาลัย', $bySource['PRK1']['issuer']);
    }

    public function test_external_law_filter_includes_all_external_subtypes(): void
    {
        $this->mock(\App\Services\Search\LawSearchService::class, fn ($mock) => $mock
            ->shouldReceive('search')->never());

        $store = app(\App\Services\ReviewStore::class);
        $token = 'external_filter_'.uniqid();
        $documents = [
            'act' => 'พระราชบัญญัติ',
            'decree' => 'พระราชกำหนด',
            'rule' => 'กฎกระทรวง',
            'announcement' => 'ประกาศกระทรวง',
        ];

        foreach ($documents as $suffix => $lawType) {
            $id = "{$token}_{$suffix}";
            $store->setStatus($id, ['status' => 'ingested']);
            $store->writeReviewDocument($id, [
                'document_id' => $id,
                'law_meta' => [
                    'title' => "{$token} {$lawType}",
                    'law_type' => $lawType,
                    'access_scope' => 'public',
                    'published_date' => '2565-01-01',
                ],
                'pages' => [],
            ]);
        }
        cache()->forget('law-meta-list');

        $response = $this->postJson('/api/laws/search', [
            'q' => $token,
            'filters' => ['law_type' => ['kotmai-phaainok']],
        ])->assertOk();

        $ids = collect($response->json('results'))->pluck('law_id')->all();
        foreach (array_keys($documents) as $suffix) {
            $this->assertContains("{$token}_{$suffix}", $ids);
        }
    }

    public function test_external_law_subtype_filter_stays_specific(): void
    {
        $this->mock(\App\Services\Search\LawSearchService::class, fn ($mock) => $mock
            ->shouldReceive('search')->never());

        $store = app(\App\Services\ReviewStore::class);
        $token = 'external_subtype_'.uniqid();
        foreach (['act' => 'พระราชบัญญัติ', 'decree' => 'พระราชกำหนด'] as $suffix => $lawType) {
            $id = "{$token}_{$suffix}";
            $store->setStatus($id, ['status' => 'ingested']);
            $store->writeReviewDocument($id, [
                'document_id' => $id,
                'law_meta' => [
                    'title' => "{$token} {$lawType}",
                    'law_type' => $lawType,
                    'access_scope' => 'public',
                    'published_date' => '2565-01-01',
                ],
                'pages' => [],
            ]);
        }
        cache()->forget('law-meta-list');

        $response = $this->postJson('/api/laws/search', [
            'q' => $token,
            'filters' => ['law_type' => ['พระราชกำหนด']],
        ])->assertOk();

        $ids = collect($response->json('results'))->pluck('law_id')->all();
        $this->assertContains("{$token}_decree", $ids);
        $this->assertNotContains("{$token}_act", $ids);
    }
}
