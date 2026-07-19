<?php

namespace Tests\Unit;

use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexDefinition;
use App\Services\Search\LawIndexer;
use Mockery;
use Tests\TestCase;

class LawIndexerAutoCreateTest extends TestCase
{
    public function test_index_is_created_when_missing(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'law_idx_test_');
        file_put_contents($tmpFile, json_encode(['chunks' => [
            ['chunk_id' => 'c1', 'text' => 'hello', 'page_no' => 1, 'block_ids' => [], 'section_path' => null],
        ]]));

        $store = Mockery::mock(ReviewStore::class);
        $store->shouldReceive('absolutePath')->andReturn($tmpFile);
        $store->shouldReceive('exportRelativePath')->andReturn('exports/test.rag.json');
        $store->shouldReceive('getReviewDocument')->andReturn(['law_meta' => ['title' => 'Test', 'access_scope' => 'public']]);

        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('indexExists')->once()->andReturn(false);
        $client->shouldReceive('createIndex')->once()->with(LawIndexDefinition::definition());
        $client->shouldReceive('deleteByLawId')->once();
        $client->shouldReceive('bulkIndex')->once();

        $indexer = new LawIndexer($client, $store);
        $indexer->index('test-doc-id');

        unlink($tmpFile);
    }

    public function test_index_is_not_recreated_when_already_exists(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'law_idx_test_');
        file_put_contents($tmpFile, json_encode(['chunks' => [
            ['chunk_id' => 'c1', 'text' => 'hello', 'page_no' => 1, 'block_ids' => [], 'section_path' => null],
        ]]));

        $store = Mockery::mock(ReviewStore::class);
        $store->shouldReceive('absolutePath')->andReturn($tmpFile);
        $store->shouldReceive('exportRelativePath')->andReturn('exports/test.rag.json');
        $store->shouldReceive('getReviewDocument')->andReturn(['law_meta' => ['title' => 'Test', 'access_scope' => 'public']]);

        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('indexExists')->once()->andReturn(true);
        $client->shouldNotReceive('createIndex');
        $client->shouldReceive('deleteByLawId')->once();
        $client->shouldReceive('bulkIndex')->once();

        $indexer = new LawIndexer($client, $store);
        $indexer->index('test-doc-id');

        unlink($tmpFile);
    }
}
