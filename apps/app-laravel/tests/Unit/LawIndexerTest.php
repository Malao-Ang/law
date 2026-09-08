<?php

namespace Tests\Unit;

use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexer;
use Tests\TestCase;

class LawIndexerTest extends TestCase
{
    public function test_parse_year_extracts_four_digit_year_as_gregorian(): void
    {
        $this->assertSame(2022, LawIndexer::parseYear('announced 2565'));
        $this->assertSame(2022, LawIndexer::parseYear('2565-01-03'));
        $this->assertSame(2022, LawIndexer::parseYear('2022-01-03'));
        $this->assertNull(LawIndexer::parseYear(null));
        $this->assertNull(LawIndexer::parseYear('no year'));
    }

    public function test_index_builds_chunk_docs_with_denormalized_meta(): void
    {
        $store = app(ReviewStore::class);
        $id = 'law_idx_'.uniqid();

        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'x.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'law_meta' => [
                'title' => 'Test Act',
                'law_type' => 'phrb',
                'status' => 'active',
                'agency' => 'Test Agency',
                'published_date' => '2565-01-03',
                'keywords' => ['tax', ' land ', 'tax'],
            ],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        $exportPath = $store->absolutePath($store->exportRelativePath($id));
        @mkdir(dirname($exportPath), 0775, true);
        file_put_contents($exportPath, json_encode([
            'document_id' => $id,
            'document_title' => 'Test Act',
            'chunks' => [
                [
                    'chunk_id' => "$id-c1",
                    'page_no' => 1,
                    'block_ids' => ['p1-b1'],
                    'section_path' => 'Section 1',
                    'text' => 'Section one text',
                    'meta' => [],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $captured = [];
        $mock = \Mockery::mock(ElasticClient::class);
        $mock->shouldReceive('indexExists')->once()->andReturn(true);
        $mock->shouldReceive('deleteByLawId')->once()->with($id);
        $mock->shouldReceive('bulkIndex')->once()->andReturnUsing(function ($docs) use (&$captured) {
            $captured = $docs;
        });

        $indexer = new LawIndexer($mock, $store);
        $indexer->index($id);

        $this->assertCount(1, $captured);
        $doc = $captured[0];
        $this->assertSame($id, $doc['law_id']);
        $this->assertSame("$id-c1", $doc['chunk_id']);
        $this->assertSame('Test Act', $doc['title']);
        $this->assertSame('phrb', $doc['law_type']);
        $this->assertSame('Test Agency', $doc['agency']);
        $this->assertSame(2022, $doc['published_year']);
        $this->assertSame('Section 1', $doc['section_path']);
        $this->assertSame(['tax', 'land'], $doc['keywords']);
        $this->assertSame('tax land', $doc['keywords_text']);
        $this->assertSame('tax land', $doc['keywords_suggest']);
    }
}
