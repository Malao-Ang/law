<?php

namespace App\Jobs;

use App\Services\DocumentPipelineClient;
use App\Services\ReviewStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExtractDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public readonly string $documentId,
        public readonly string $relativeFilePath,
        public readonly bool $enableAiCorrection,
        public readonly string $scanExtractionMode = 'auto',
    ) {}

    public function handle(DocumentPipelineClient $pipelineClient, ReviewStore $reviewStore): void
    {
        $reviewStore->setStatus($this->documentId, [
            'status' => 'processing',
            'progress' => 20,
            'current_step' => 'extract_document',
        ]);

        // IMPORTANT: Use the Docker-internal service name (laravel-app:8000), NOT APP_URL.
        // APP_URL points to localhost which resolves to the Python container itself inside Docker.
        // INTERNAL_CALLBACK_URL must be set to http://laravel-app:8000/api/internal/pipeline-callback
        // in .env so the Python service can reach Laravel across the Docker bridge network.
        $callbackUrl = config('services.ocr.internal_callback_url') ?: route('pipeline.callback');

        $pipelineClient->extract(
            documentId: $this->documentId,
            relativeInputPath: $this->relativeFilePath,
            enableAiCorrection: $this->enableAiCorrection,
            callbackUrl: $callbackUrl,
            scanExtractionMode: $this->scanExtractionMode,
        );

        // Python accepted the job (202). Status will be updated by PipelineCallbackController
        // when Python posts the result back to $callbackUrl.
    }

    public function failed(Throwable $exception): void
    {
        app(ReviewStore::class)->setStatus($this->documentId, [
            'status' => 'failed',
            'current_step' => 'extract_document',
            'error' => $exception->getMessage(),
        ]);
    }
}
