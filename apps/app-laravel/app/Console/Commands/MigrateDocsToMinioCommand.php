<?php

namespace App\Console\Commands;

use App\Services\Buu\MinioUploadService;
use App\Services\ReviewStore;
use Illuminate\Console\Command;

class MigrateDocsToMinioCommand extends Command
{
    protected $signature = 'minio:migrate {--dry-run : Show what would be uploaded without doing it}';

    protected $description = 'Upload existing local documents to MinIO that do not have minio_source_filename yet.';

    public function handle(ReviewStore $store, MinioUploadService $minio): int
    {
        if (! $minio->isEnabled()) {
            $this->error('BUU_MINIO_ENABLED is false. Set it to true first.');

            return self::FAILURE;
        }

        $candidates = [];

        foreach ($store->listDocuments() as $doc) {
            $id = (string) ($doc['document_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $status = $store->getStatus($id);
            if ($status === null) {
                continue;
            }
            if (($status['minio_source_filename'] ?? '') !== '') {
                continue;
            }

            $sourcePath = (string) ($status['source_path'] ?? '');
            if ($sourcePath === '') {
                continue;
            }

            $absPath = $store->absolutePath($sourcePath);
            if (! is_file($absPath)) {
                continue;
            }

            $candidates[] = ['id' => $id, 'path' => $absPath, 'source_path' => $sourcePath];
        }

        $this->info(count($candidates).' documents to migrate.');

        if ($this->option('dry-run')) {
            foreach ($candidates as $candidate) {
                $this->line("  {$candidate['id']} -> {$candidate['source_path']}");
            }

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($candidates));
        $ok = 0;
        $fail = 0;

        foreach ($candidates as $candidate) {
            $ext = strtolower(pathinfo($candidate['source_path'], PATHINFO_EXTENSION));
            $filename = $minio->uploadIfEnabled(
                $candidate['path'],
                $ext,
                $candidate['id'],
                '/'.$candidate['id'],
            );

            if ($filename !== null) {
                $store->setStatus($candidate['id'], ['minio_source_filename' => $filename]);
                $ok++;
            } else {
                $fail++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Uploaded: {$ok}, Failed: {$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
