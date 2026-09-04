<?php

namespace App\Services\Buu;

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

    public function isEnabled(): bool
    {
        return (bool) config('buu.minio_enabled');
    }
}
