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

class NormalizeDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 600;

    public function __construct(
        public readonly string $documentId,
    ) {}

    public function handle(DocumentPipelineClient $client, ReviewStore $store): void
    {
        $store->setStatus($this->documentId, [
            'correction_status' => 'in_progress',
            'current_step' => 'normalize',
        ]);

        $doc = $store->getReviewDocument($this->documentId);

        $blocks = [];
        foreach ($doc['pages'] ?? [] as $page) {
            foreach ($page['blocks'] ?? [] as $block) {
                $type = (string) ($block['type'] ?? '');
                if (in_array($type, ['table', 'image'], true)) {
                    continue;
                }
                $blocks[] = [
                    'block_id' => (string) ($block['block_id'] ?? ''),
                    'text' => (string) ($block['raw_text'] ?? ''),
                ];
            }
        }

        if ($blocks !== []) {
            $minConfidence = (float) config('services.ocr.normalize_autocorrect_min_confidence', 1.0);
            $response = $client->normalize($this->documentId, $blocks, $minConfidence);
            $store->applyNormalizationResults($this->documentId, $response['results'] ?? []);
        }

        $store->setStatus($this->documentId, [
            'status' => 'done',
            'progress' => 100,
            'current_step' => 'normalize_done',
            'correction_status' => 'done',
        ]);
    }

    public function failed(Throwable $exception): void
    {
        app(ReviewStore::class)->setStatus($this->documentId, [
            'correction_status' => 'failed',
            'error' => $exception->getMessage(),
        ]);
    }
}
