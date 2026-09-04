<?php

namespace Tests\Feature;

use App\Services\Buu\MinioUploadService;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class MigrateDocsToMinioCommandTest extends TestCase
{
    public function test_migrate_refuses_to_run_when_minio_disabled(): void
    {
        config(['buu.minio_enabled' => false]);

        $exitCode = Artisan::call('minio:migrate', ['--dry-run' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('BUU_MINIO_ENABLED is false', Artisan::output());
    }

    public function test_dry_run_lists_documents_without_minio_source_filename(): void
    {
        $store = app(ReviewStore::class);

        // Document without minio_source_filename → should be listed
        $id1 = $store->generateDocumentId();
        $stored1 = $store->storeUpload(UploadedFile::fake()->create('law.pdf', 10, 'application/pdf'), $id1);
        $store->createHistoricalStub($id1, $stored1['source_file']);
        $store->setStatus($id1, [
            'status' => 'done',
            'document_type' => 'old',
            'source_path' => $stored1['relative_path'],
        ]);

        // Document already has minio_source_filename → should be skipped
        $id2 = $store->generateDocumentId();
        $stored2 = $store->storeUpload(UploadedFile::fake()->create('law2.pdf', 10, 'application/pdf'), $id2);
        $store->createHistoricalStub($id2, $stored2['source_file']);
        $store->setStatus($id2, [
            'status' => 'done',
            'document_type' => 'old',
            'source_path' => $stored2['relative_path'],
            'minio_source_filename' => 'already-uploaded.pdf',
        ]);

        $mock = Mockery::mock(MinioUploadService::class);
        $mock->shouldReceive('isEnabled')->once()->andReturn(true);
        $mock->shouldNotReceive('uploadIfEnabled');
        $this->app->instance(MinioUploadService::class, $mock);

        $exitCode = Artisan::call('minio:migrate', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString($id1, $output);
        $this->assertStringNotContainsString($id2, $output);

        $store->deleteDocument($id1);
        $store->deleteDocument($id2);
    }
}
