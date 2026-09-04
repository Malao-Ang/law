<?php

namespace Tests\Feature;

use App\Services\Buu\BuuMinioService;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class DocumentFileMinioTest extends TestCase
{
    public function test_redirects_to_minio_presigned_url_when_minio_source_filename_exists(): void
    {
        config(['buu.minio_enabled' => true]);

        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $stored = $store->storeUpload(UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'), $id);
        $store->createHistoricalStub($id, $stored['source_file']);
        $store->setStatus($id, [
            'status' => 'done',
            'document_type' => 'old',
            'source_path' => $stored['relative_path'],
            'source_file' => $stored['source_file'],
            'minio_source_filename' => 'abc123.pdf',
        ]);

        // Delete local file so MinIO fallback is triggered
        $localPath = $store->absolutePath($stored['relative_path']);
        if (is_file($localPath)) {
            unlink($localPath);
        }

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('getPublicLinks')
            ->once()
            ->with(
                Mockery::on(fn ($fp) => ($fp['file'] ?? '') === 'abc123.pdf'),
                Mockery::any(),
            )
            ->andReturn([
                'file' => [
                    'view' => 'https://minio-cluster-dev.buu.ac.th:9000/law-space/abc123.pdf?presigned',
                ],
            ]);
        $this->app->instance(BuuMinioService::class, $mock);

        $response = $this->get("/api/documents/{$id}/file");
        $response->assertRedirect('https://minio-cluster-dev.buu.ac.th:9000/law-space/abc123.pdf?presigned');

        $store->deleteDocument($id);
    }

    public function test_falls_back_to_local_when_minio_source_filename_absent(): void
    {
        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $stored = $store->storeUpload(UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'), $id);
        $store->createHistoricalStub($id, $stored['source_file']);
        $store->setStatus($id, [
            'status' => 'done',
            'document_type' => 'old',
            'source_path' => $stored['relative_path'],
        ]);

        $this->get("/api/documents/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $store->deleteDocument($id);
    }

    public function test_serves_local_when_minio_presigned_fails_and_local_exists(): void
    {
        config(['buu.minio_enabled' => true]);

        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $stored = $store->storeUpload(UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'), $id);
        $store->createHistoricalStub($id, $stored['source_file']);
        $store->setStatus($id, [
            'status' => 'done',
            'document_type' => 'old',
            'source_path' => $stored['relative_path'],
            'minio_source_filename' => 'abc123.pdf',
        ]);

        // Local file exists → should serve from local, MinIO is not called
        $this->get("/api/documents/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $store->deleteDocument($id);
    }

    public function test_does_not_call_minio_when_disabled(): void
    {
        config(['buu.minio_enabled' => false]);

        $store = app(ReviewStore::class);
        $id = $store->generateDocumentId();
        $stored = $store->storeUpload(UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'), $id);
        $store->createHistoricalStub($id, $stored['source_file']);
        $store->setStatus($id, [
            'status' => 'done',
            'document_type' => 'old',
            'source_path' => $stored['relative_path'],
            'minio_source_filename' => 'abc123.pdf',
        ]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldNotReceive('getPublicLinks');
        $this->app->instance(BuuMinioService::class, $mock);

        $this->get("/api/documents/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $store->deleteDocument($id);
    }
}
