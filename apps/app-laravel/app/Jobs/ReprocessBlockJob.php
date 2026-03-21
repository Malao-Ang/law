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

class ReprocessBlockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $documentId,
        public readonly int $pageNo,
        public readonly string $blockId,
        public readonly string $mode,
    ) {}

    public function handle(DocumentPipelineClient $pipelineClient, ReviewStore $reviewStore): void
    {
        $result = $pipelineClient->reprocessBlock(
            documentId: $this->documentId,
            pageNo: $this->pageNo,
            blockId: $this->blockId,
            mode: $this->mode,
        );

        $reviewStore->applyReprocessResult($this->documentId, $result);

        $reviewStore->setStatus($this->documentId, [
            'status' => 'done',
            'current_step' => 'review_ready',
        ]);
    }

    public function failed(Throwable $exception): void
    {
        app(ReviewStore::class)->setStatus($this->documentId, [
            'status' => 'failed',
            'current_step' => 'reprocess_block',
            'error' => $exception->getMessage(),
        ]);
    }
}
