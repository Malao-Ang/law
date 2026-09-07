<?php

namespace App\Services\Buu;

use App\Services\ReviewStore;
use Illuminate\Support\Facades\Log;

class MinioUploadService
{
    public function __construct(private readonly BuuMinioService $minio) {}

    /**
     * Upload file to MinIO if enabled. Returns stored filename or null.
     * Never throws; all errors are caught and logged.
     */
    public function uploadIfEnabled(
        string $absolutePath,
        string $originalExtension,
        string $documentId,
        string $folderPath = '/',
    ): ?string {
        if (! config('buu.minio_enabled')) {
            return null;
        }

        try {
            return $this->minio->putFile(
                absolutePath: $absolutePath,
                originalExtension: $originalExtension,
                folderPath: $folderPath,
            );
        } catch (\Throwable $e) {
            Log::warning("MinIO upload failed for {$documentId}, keeping local file", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Upload to MinIO and delete the local file on success.
     * Returns stored filename or null.
     */
    public function uploadAndCleanup(
        string $absolutePath,
        string $originalExtension,
        string $documentId,
        string $folderPath = '/',
    ): ?string {
        $filename = $this->uploadIfEnabled($absolutePath, $originalExtension, $documentId, $folderPath);

        if ($filename !== null && is_file($absolutePath)) {
            unlink($absolutePath);
        }

        return $filename;
    }

    /**
     * Upload a document's source file (from its status source_path) to MinIO
     * under folder /{documentId}, and persist minio_source_filename.
     * Non-fatal + no-op when MinIO disabled, source_path empty, or file missing.
     * Returns the stored MinIO object name, or null.
     */
    public function uploadSource(string $documentId, ReviewStore $reviewStore): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $status = $reviewStore->getStatus($documentId);
        $relative = (string) ($status['source_path'] ?? '');
        if ($relative === '') {
            return null;
        }

        $sourcePath = $reviewStore->absolutePath($relative);
        if (! is_file($sourcePath)) {
            return null;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $minioFilename = $this->uploadIfEnabled(
            absolutePath: $sourcePath,
            originalExtension: $ext,
            documentId: $documentId,
            folderPath: '/'.$documentId,
        );

        if ($minioFilename !== null) {
            $reviewStore->setStatus($documentId, [
                'minio_source_filename' => $minioFilename,
            ]);
        }

        return $minioFilename;
    }

    public function isEnabled(): bool
    {
        return (bool) config('buu.minio_enabled');
    }
}
