<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class DocumentPipelineClient
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Fire an async extraction request.  The Python service returns 202 immediately
     * and posts results to $callbackUrl when processing completes.
     */
    public function extract(
        string $documentId,
        string $relativeInputPath,
        bool $enableAiCorrection,
        string $callbackUrl,
        string $scanExtractionMode = 'auto',
    ): void {
        $this->request(5)->post('/pipeline/extract', [
            'document_id' => $documentId,
            'file_path' => $this->toSharedPath($relativeInputPath),
            'enable_ai_correction' => $enableAiCorrection,
            'callback_url' => $callbackUrl,
            'scan_extraction_mode' => $scanExtractionMode,
        ])->throw();
    }

    /**
     * Reprocess a single page with the given extraction mode (defaults to landingai).
     * Returns 202 immediately; result is posted to $callbackUrl.
     */
    public function reprocessPage(
        string $documentId,
        string $relativeInputPath,
        int $pageNo,
        string $callbackUrl,
        string $scanExtractionMode = 'landingai',
    ): void {
        $this->request(5)->post('/pipeline/reprocess-page', [
            'document_id' => $documentId,
            'file_path' => $this->toSharedPath($relativeInputPath),
            'page_no' => $pageNo,
            'scan_extraction_mode' => $scanExtractionMode,
            'callback_url' => $callbackUrl,
        ])->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function reprocessBlock(string $documentId, int $pageNo, string $blockId, string $mode): array
    {
        $response = $this->request(120)->post('/pipeline/reprocess-block', [
            'document_id' => $documentId,
            'page_no' => $pageNo,
            'block_id' => $blockId,
            'mode' => $mode,
        ])->throw();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    /**
     * Stateless CPU-only normalize + spell auto-correct for a batch of blocks.
     *
     * @param  array<int, array{block_id: string, text: string}>  $blocks
     * @return array<string, mixed>
     */
    public function normalize(string $documentId, array $blocks, float $autocorrectMinConfidence = 1.0): array
    {
        $response = $this->request(120)->post('/pipeline/normalize', [
            'document_id' => $documentId,
            'blocks' => array_values($blocks),
            'autocorrect_min_confidence' => $autocorrectMinConfidence,
        ])->throw();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    public function correct(
        string $documentId,
        string $callbackUrl,
        bool $enableAiCorrection = false,
    ): void {
        $this->request(30)->post('/pipeline/correct', [
            'document_id' => $documentId,
            'callback_url' => $callbackUrl,
            'enable_ai_correction' => $enableAiCorrection,
        ])->throw();
    }

    private function request(int $timeout = 30): PendingRequest
    {
        return $this->http->baseUrl((string) config('services.ocr.base_url'))
            ->acceptJson()
            ->asJson()
            ->timeout($timeout);
    }

    private function toSharedPath(string $relativeInputPath): string
    {
        $base = rtrim((string) config('services.ocr.shared_storage_root', '/data/poc'), '/');
        $relative = ltrim(str_replace('\\', '/', $relativeInputPath), '/');

        if ($relative === '') {
            throw new RuntimeException('Input file path cannot be empty.');
        }

        return $base.'/'.$relative;
    }
}
