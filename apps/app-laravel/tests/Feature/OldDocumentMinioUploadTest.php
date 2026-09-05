<?php

namespace Tests\Feature;

use App\Services\Buu\BuuApiException;
use App\Services\Buu\BuuMinioService;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class OldDocumentMinioUploadTest extends TestCase
{
    public function test_old_document_upload_uploads_to_minio_and_stores_filename(): void
    {
        Bus::fake();
        config(['buu.minio_enabled' => true]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('putFile')
            ->once()
            ->andReturn('abc123random.pdf');
        $this->app->instance(BuuMinioService::class, $mock);

        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('old-law.pdf', 100, 'application/pdf'),
            'document_type' => 'old',
            'source' => 'internal',
            'law_type' => config('lookups.document_types.0.value'),
        ]);

        $response->assertStatus(202)->assertJsonPath('status', 'done');

        $docId = $response->json('document_id');
        $store = app(ReviewStore::class);
        $status = $store->getStatus($docId);

        $this->assertSame('abc123random.pdf', $status['minio_source_filename']);
        // Local file is kept (uploadIfEnabled, not uploadAndCleanup)
        $this->assertFileExists($store->absolutePath((string) $status['source_path']));

        $store->deleteDocument($docId);
    }

    public function test_old_document_upload_succeeds_even_when_minio_fails(): void
    {
        Bus::fake();
        config(['buu.minio_enabled' => true]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('putFile')
            ->once()
            ->andThrow(new BuuApiException('MinIO down'));
        $this->app->instance(BuuMinioService::class, $mock);

        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('old-law.pdf', 100, 'application/pdf'),
            'document_type' => 'old',
            'source' => 'internal',
            'law_type' => config('lookups.document_types.0.value'),
        ]);

        $response->assertStatus(202)->assertJsonPath('status', 'done');

        $docId = $response->json('document_id');
        $store = app(ReviewStore::class);
        $status = $store->getStatus($docId);

        $this->assertArrayNotHasKey('minio_source_filename', $status);
        $this->assertFileExists($store->absolutePath((string) $status['source_path']));

        $store->deleteDocument($docId);
    }

    public function test_old_document_upload_skips_minio_when_disabled(): void
    {
        Bus::fake();
        config(['buu.minio_enabled' => false]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldNotReceive('putFile');
        $this->app->instance(BuuMinioService::class, $mock);

        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('old-law.pdf', 100, 'application/pdf'),
            'document_type' => 'old',
            'source' => 'internal',
            'law_type' => config('lookups.document_types.0.value'),
        ]);

        $response->assertStatus(202)->assertJsonPath('status', 'done');

        $docId = $response->json('document_id');
        $store = app(ReviewStore::class);
        $status = $store->getStatus($docId);

        $this->assertArrayNotHasKey('minio_source_filename', $status);
        $this->assertFileExists($store->absolutePath((string) $status['source_path']));

        $store->deleteDocument($docId);
    }

    public function test_old_external_upload_prefills_metadata_source_external(): void
    {
        Bus::fake();
        config(['buu.minio_enabled' => false]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldNotReceive('putFile');
        $this->app->instance(BuuMinioService::class, $mock);

        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('external-law.pdf', 100, 'application/pdf'),
            'document_type' => 'old',
            'source' => 'external',
        ]);

        $response->assertStatus(202)->assertJsonPath('status', 'done');

        $docId = $response->json('document_id');
        $store = app(ReviewStore::class);
        $review = $store->getReviewDocument($docId);

        $this->assertSame('old', $review['law_meta']['document_type']);
        $this->assertSame('external', $review['law_meta']['source']);
        $this->assertSame('', $review['law_meta']['law_type']);

        $store->deleteDocument($docId);
    }
}
