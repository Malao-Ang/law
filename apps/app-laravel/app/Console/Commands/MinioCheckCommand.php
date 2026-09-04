<?php

namespace App\Console\Commands;

use App\Services\Buu\BuuMinioService;
use App\Services\ReviewStore;
use Illuminate\Console\Command;

class MinioCheckCommand extends Command
{
    protected $signature = 'minio:check {documentId? : Document ID to check} {--bucket= : Override bucket}';

    protected $description = 'List files in MinIO bucket (optionally filtered by document folder).';

    public function handle(BuuMinioService $minio, ReviewStore $store): int
    {
        if (! config('buu.minio_enabled')) {
            $this->error('BUU_MINIO_ENABLED is false.');

            return self::FAILURE;
        }

        $documentId = $this->argument('documentId');
        $bucket = $this->option('bucket') ?: null;

        // Show config
        $this->info('Bucket: '.($bucket ?? config('buu.default_bucket')));
        $this->info('Kong domain: '.config('buu.domain'));
        $this->newLine();

        // If documentId given, check status + list folder
        if ($documentId) {
            $status = $store->getStatus($documentId);
            if ($status === null) {
                $this->error("Document {$documentId} not found in ReviewStore.");

                return self::FAILURE;
            }

            $minioFilename = $status['minio_source_filename'] ?? null;
            $sourcePath = $status['source_path'] ?? null;
            $localExists = $sourcePath ? is_file($store->absolutePath($sourcePath)) : false;

            $this->table(['Key', 'Value'], [
                ['document_id', $documentId],
                ['source_path', $sourcePath ?? '(none)'],
                ['local_file_exists', $localExists ? '✓ YES' : '✗ NO'],
                ['minio_source_filename', $minioFilename ?? '(not uploaded)'],
            ]);

            $this->newLine();
            $path = '/'.$documentId;
            $this->info("Listing MinIO path: {$path}");

            try {
                $files = $minio->listFiles($path, $bucket);
            } catch (\Throwable $e) {
                $this->error('MinIO list failed: '.$e->getMessage());

                return self::FAILURE;
            }

            if (empty($files)) {
                $this->warn('No files found in MinIO for this document.');
            } else {
                $this->info(count($files).' file(s) found:');
                foreach ($files as $file) {
                    $this->line("  • {$file}");
                }
            }

            // Try presigned URL
            if ($minioFilename) {
                $this->newLine();
                $this->info('Generating presigned URL...');

                try {
                    $links = $minio->getPublicLinks(
                        ['file' => $minioFilename],
                        ['file' => basename($sourcePath ?? $minioFilename)],
                        bucket: $bucket,
                    );
                    $viewUrl = $links['file']['view'] ?? null;
                    $downloadUrl = $links['file']['download'] ?? null;

                    if ($viewUrl) {
                        $this->line("  View:     {$viewUrl}");
                    }
                    if ($downloadUrl) {
                        $this->line("  Download: {$downloadUrl}");
                    }
                    if (! $viewUrl && ! $downloadUrl) {
                        $this->warn('  No URLs returned.');
                    }
                } catch (\Throwable $e) {
                    $this->error('Presigned URL failed: '.$e->getMessage());
                }
            }

            return self::SUCCESS;
        }

        // No documentId — list root
        $this->info('Listing MinIO root /');

        try {
            $files = $minio->listFiles('/', $bucket);
        } catch (\Throwable $e) {
            $this->error('MinIO list failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($files)) {
            $this->warn('No files found in MinIO root.');
        } else {
            $this->info(count($files).' item(s):');
            foreach ($files as $file) {
                $this->line("  • {$file}");
            }
        }

        return self::SUCCESS;
    }
}
