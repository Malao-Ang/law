<?php

namespace App\Jobs;

use App\Services\ExportService;
use App\Services\RagIngestService;
use App\Services\ReviewStore;
use App\Services\Search\LawIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class IngestRagJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $documentId) {}

    public function handle(RagIngestService $ragIngestService, LawIndexer $lawIndexer, ReviewStore $reviewStore, ExportService $exportService): void
    {
        $exportPath = $reviewStore->absolutePath($reviewStore->exportRelativePath($this->documentId));
        if (! is_file($exportPath)) {
            $exportService->export($this->documentId);
        }

        $result = $ragIngestService->ingest($this->documentId);

        try {
            $lawIndexer->index($this->documentId);
        } catch (Throwable $exception) {
            Log::warning('Law indexing failed (non-fatal)', [
                'document_id' => $this->documentId,
                'error' => $exception->getMessage(),
            ]);
        }

        $reviewStore->setStatus($this->documentId, [
            'status' => 'ingested',
            'current_step' => 'rag_ingested',
            'progress' => 100,
            'ingest_path' => $result['ingest_path'],
            'ingested_chunk_count' => $result['chunk_count'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        app(ReviewStore::class)->setStatus($this->documentId, [
            'status' => 'failed',
            'current_step' => 'rag_ingest',
            'error' => $exception->getMessage(),
        ]);
    }
}
