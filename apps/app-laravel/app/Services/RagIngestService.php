<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

class RagIngestService
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    /**
     * @return array{document_id: string, status: string, ingest_path: string, chunk_count: int}
     */
    public function ingest(string $documentId): array
    {
        $exportPath = $this->reviewStore->absolutePath($this->reviewStore->exportRelativePath($documentId));

        if (! File::exists($exportPath)) {
            throw new RuntimeException('RAG export file not found.');
        }

        /** @var array<string, mixed> $exportData */
        $exportData = json_decode((string) File::get($exportPath), true, 512, JSON_THROW_ON_ERROR);
        $chunks = is_array($exportData['chunks'] ?? null) ? $exportData['chunks'] : [];

        $ingestData = [
            'document_id' => $documentId,
            'status' => 'ingested',
            'ingested_at' => now()->toIso8601String(),
            'chunk_count' => count($chunks),
            'source_export_path' => 'storage/app/poc/'.$this->reviewStore->exportRelativePath($documentId),
            'chunks' => array_map(function (array $chunk): array {
                return [
                    'chunk_id' => (string) ($chunk['chunk_id'] ?? ''),
                    'text' => (string) ($chunk['text'] ?? ''),
                    'meta' => $chunk['meta'] ?? [],
                ];
            }, $chunks),
        ];

        $relativePath = $this->reviewStore->writeIngest($documentId, $ingestData);

        return [
            'document_id' => $documentId,
            'status' => 'ingested',
            'ingest_path' => 'storage/app/poc/'.$relativePath,
            'chunk_count' => count($chunks),
        ];
    }
}
