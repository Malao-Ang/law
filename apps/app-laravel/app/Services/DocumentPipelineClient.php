<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class DocumentPipelineClient
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(string $documentId, string $relativeInputPath, bool $enableAiCorrection): array
    {
        $payload = [
            'document_id' => $documentId,
            'file_path' => $this->toSharedPath($relativeInputPath),
            'enable_ai_correction' => $enableAiCorrection,
        ];

        $response = $this->request()->post('/pipeline/extract', $payload)->throw();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public function reprocessBlock(string $documentId, int $pageNo, string $blockId, string $mode): array
    {
        $response = $this->request()->post('/pipeline/reprocess-block', [
            'document_id' => $documentId,
            'page_no' => $pageNo,
            'block_id' => $blockId,
            'mode' => $mode,
        ])->throw();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    private function request(): PendingRequest
    {
        return $this->http->baseUrl((string) config('services.ocr.base_url'))
            ->acceptJson()
            ->asJson()
            ->timeout(600); // Increased to 10 minutes for EasyOCR model downloads
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
