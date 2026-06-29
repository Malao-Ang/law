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

class CorrectDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 600;

    public function __construct(
        public readonly string $documentId,
        public readonly bool $enableAiCorrection,
    ) {}

    public function handle(DocumentPipelineClient $client, ReviewStore $store): void
    {
        $store->setStatus($this->documentId, [
            'correction_status' => 'in_progress',
            'current_step' => 'python_correct',
        ]);

        $callbackUrl = config('services.ocr.internal_callback_url')
            ?: route('pipeline.callback');

        $client->correct(
            documentId: $this->documentId,
            callbackUrl: $callbackUrl,
            enableAiCorrection: $this->enableAiCorrection,
        );
    }

    public function failed(Throwable $exception): void
    {
        app(ReviewStore::class)->setStatus($this->documentId, [
            'correction_status' => 'failed',
            'error' => $exception->getMessage(),
        ]);
    }
}
