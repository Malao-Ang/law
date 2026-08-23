<?php

namespace App\Jobs;

use App\Services\DocumentPipelineClient;
use App\Services\ReviewStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Old-document path only. Runs Gemini OCR purely to feed fulltext search.
 * The Python callback (PipelineCallbackController) handles the result for
 * document_type='old' by writing export chunks + indexing — it never touches
 * the review blob the wizard is editing. Non-fatal: search lights up later.
 */
class IndexHistoricalDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        public readonly string $documentId,
        public readonly string $relativeFilePath,
    ) {}

    public function handle(DocumentPipelineClient $client, ReviewStore $store): void
    {
        $callbackUrl = config('services.ocr.internal_callback_url') ?: route('pipeline.callback');
        $store->setStatus($this->documentId, ['search_status' => 'indexing']);

        try {
            $client->extract(
                documentId: $this->documentId,
                relativeInputPath: $this->relativeFilePath,
                enableAiCorrection: false,
                callbackUrl: $callbackUrl,
                scanExtractionMode: 'gemini',
            );
        } catch (Throwable $e) {
            Log::warning('Historical OCR indexing dispatch failed (non-fatal)', [
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);
            $store->setStatus($this->documentId, ['search_status' => 'failed']);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(ReviewStore::class)->setStatus($this->documentId, ['search_status' => 'failed']);
    }
}
