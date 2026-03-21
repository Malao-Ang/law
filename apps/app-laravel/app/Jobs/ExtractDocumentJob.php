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

    public int $timeout = 1800;

    public function __construct(
        public readonly string $documentId,
        public readonly string $relativeFilePath,
        public readonly bool $enableAiCorrection,
    ) {}

    public function handle(DocumentPipelineClient $pipelineClient, ReviewStore $reviewStore): void
    {
        $reviewStore->setStatus($this->documentId, [
            'status' => 'processing',
            'progress' => 20,
            'current_step' => 'extract_document',
        ]);

        $output = $pipelineClient->extract(
            documentId: $this->documentId,
            relativeInputPath: $this->relativeFilePath,
            enableAiCorrection: $this->enableAiCorrection,
        );

        $reviewStore->writeReviewDocument($this->documentId, $output);

        $reviewStore->setStatus($this->documentId, [
            'status' => 'done',
            'progress' => 100,
            'current_step' => 'completed',
            'review_path' => 'storage/app/poc/'.$reviewStore->reviewRelativePath($this->documentId),
        ]);
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
