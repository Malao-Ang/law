<?php

namespace Tests\Unit;

use App\Jobs\IngestRagJob;
use App\Services\ExportService;
use App\Services\RagIngestService;
use App\Services\ReviewStore;
use App\Services\Search\LawIndexer;
use Mockery;
use Tests\TestCase;

class IngestRagJobAutoExportTest extends TestCase
{
    public function test_export_is_built_when_file_missing(): void
    {
        $reviewStore = Mockery::mock(ReviewStore::class);
        $reviewStore->shouldReceive('absolutePath')->andReturn('/tmp/nonexistent-'.uniqid().'.json');
        $reviewStore->shouldReceive('exportRelativePath')->andReturn('exports/test.rag.json');
        $reviewStore->shouldReceive('setStatus')->once();

        $exportService = Mockery::mock(ExportService::class);
        $exportService->shouldReceive('export')->once()->andReturn(['export_path' => 'exports/test.rag.json']);

        $ragIngestService = Mockery::mock(RagIngestService::class);
        $ragIngestService->shouldReceive('ingest')->once()->andReturn([
            'document_id' => 'test-id',
            'status' => 'ingested',
            'ingest_path' => 'ingested/test.ingested.json',
            'chunk_count' => 1,
        ]);

        $lawIndexer = Mockery::mock(LawIndexer::class);
        $lawIndexer->shouldReceive('index')->once();

        $job = new IngestRagJob('test-id');
        $job->handle($ragIngestService, $lawIndexer, $reviewStore, $exportService);
    }

    public function test_export_is_skipped_when_file_exists(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rag_test_');

        $reviewStore = Mockery::mock(ReviewStore::class);
        $reviewStore->shouldReceive('absolutePath')->andReturn($tmpFile);
        $reviewStore->shouldReceive('exportRelativePath')->andReturn('exports/test.rag.json');
        $reviewStore->shouldReceive('setStatus')->once();

        $exportService = Mockery::mock(ExportService::class);
        $exportService->shouldNotReceive('export');

        $ragIngestService = Mockery::mock(RagIngestService::class);
        $ragIngestService->shouldReceive('ingest')->once()->andReturn([
            'document_id' => 'test-id',
            'status' => 'ingested',
            'ingest_path' => 'ingested/test.ingested.json',
            'chunk_count' => 1,
        ]);

        $lawIndexer = Mockery::mock(LawIndexer::class);
        $lawIndexer->shouldReceive('index')->once();

        $job = new IngestRagJob('test-id');
        $job->handle($ragIngestService, $lawIndexer, $reviewStore, $exportService);

        unlink($tmpFile);
    }
}
