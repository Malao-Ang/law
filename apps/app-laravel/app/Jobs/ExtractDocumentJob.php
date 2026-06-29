<?php

namespace App\Jobs;

use App\Services\DocumentPipelineClient;
use App\Services\Fast\FastExtractionPipeline;
use App\Services\Fast\FastPathUnsupportedException;
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

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly string $documentId,
        public readonly string $relativeFilePath,
        public readonly bool $enableAiCorrection,
        public readonly string $scanExtractionMode = 'auto',
        public readonly string $extractionEngine = 'standard',
    ) {}

    public function handle(
        DocumentPipelineClient $pipelineClient,
        ReviewStore $reviewStore,
        FastExtractionPipeline $fastPipeline,
    ): void {
        $callbackUrl = config('services.ocr.internal_callback_url') ?: route('pipeline.callback');

        if ($this->extractionEngine === 'fast') {
            $this->runFast($fastPipeline, $reviewStore, $callbackUrl, $pipelineClient);

            return;
        }

        $this->runStandard($pipelineClient, $reviewStore, $callbackUrl);
    }

    private function runFast(
        FastExtractionPipeline $pipeline,
        ReviewStore $store,
        string $callbackUrl,
        DocumentPipelineClient $client,
    ): void {
        $store->setStatus($this->documentId, [
            'status' => 'processing',
            'progress' => 30,
            'current_step' => 'fast_extract',
            'correction_status' => 'pending',
            'extraction_engine' => 'fast',
        ]);

        try {
            $output = $pipeline->run($this->documentId, $this->relativeFilePath);
        } catch (FastPathUnsupportedException $exception) {
            $store->setStatus($this->documentId, [
                'status' => 'processing',
                'progress' => 25,
                'current_step' => 'fallback_to_standard',
                'fast_fallback_reason' => $exception->reason,
                'correction_status' => 'not_required',
                'extraction_engine' => 'fast',
            ]);

            $this->runStandard($client, $store, $callbackUrl);

            return;
        }

        $extraction = is_array($output['extraction'] ?? null) ? $output['extraction'] : [];

        $store->setStatus($this->documentId, [
            'status' => 'done',
            'progress' => 60,
            'current_step' => 'fast_extract_done',
            'review_path' => 'storage/app/poc/'.$store->reviewRelativePath($this->documentId),
            'correction_status' => 'pending',
            'timings' => $output['timings'] ?? null,
            'extraction_path' => $extraction['path'] ?? null,
            'conversion' => $extraction['conversion'] ?? null,
            'extraction_engine' => 'fast',
        ]);

        NormalizeDocumentJob::dispatch(
            documentId: $this->documentId,
        );
    }

    private function runStandard(DocumentPipelineClient $client, ReviewStore $store, string $callbackUrl): void
    {
        $store->setStatus($this->documentId, [
            'status' => 'processing',
            'progress' => 20,
            'current_step' => 'extract_document',
            'correction_status' => 'not_required',
            'extraction_engine' => 'standard',
        ]);

        $client->extract(
            documentId: $this->documentId,
            relativeInputPath: $this->relativeFilePath,
            enableAiCorrection: $this->enableAiCorrection,
            callbackUrl: $callbackUrl,
            scanExtractionMode: $this->scanExtractionMode,
        );
    }

    public function failed(Throwable $exception): void
    {
        app(ReviewStore::class)->setStatus($this->documentId, [
            'status' => 'failed',
            'progress' => 0,
            'current_step' => 'extract_document',
            'error' => $exception->getMessage(),
            'correction_status' => $this->extractionEngine === 'fast' ? 'failed' : 'not_required',
        ]);
    }
}
