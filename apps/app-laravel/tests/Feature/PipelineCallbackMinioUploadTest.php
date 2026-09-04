<?php

namespace Tests\Feature;

use App\Services\Buu\BuuMinioService;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class PipelineCallbackMinioUploadTest extends TestCase
{
    public function test_success_callback_uploads_source_to_minio_after_extraction(): void
    {
        config(['buu.minio_enabled' => true]);

        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();
        $stored = $store->storeUpload(
            UploadedFile::fake()->create('source.pdf', 10, 'application/pdf'),
            $documentId,
        );
        $store->setStatus($documentId, [
            'status' => 'processing',
            'source_path' => $stored['relative_path'],
            'source_file' => $stored['source_file'],
            'document_type' => 'new',
        ]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('putFile')
            ->once()
            ->andReturn('stored-source.pdf');
        $this->app->instance(BuuMinioService::class, $mock);

        $response = $this->postJson('/api/internal/pipeline-callback', [
            'document_id' => $documentId,
            'status' => 'success',
            'output' => [
                'document_id' => $documentId,
                'source_file' => 'source.pdf',
                'source_type' => 'pdf_text',
                'language' => 'th',
                'summary' => [
                    'page_count' => 1,
                    'block_count' => 0,
                    'review_required_count' => 0,
                ],
                'pages' => [],
            ],
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);

        $status = $store->getStatus($documentId);
        $this->assertSame('stored-source.pdf', $status['minio_source_filename']);

        $store->deleteDocument($documentId);
    }

    public function test_success_callback_skips_minio_when_disabled(): void
    {
        config(['buu.minio_enabled' => false]);

        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();
        $stored = $store->storeUpload(
            UploadedFile::fake()->create('source.pdf', 10, 'application/pdf'),
            $documentId,
        );
        $store->setStatus($documentId, [
            'status' => 'processing',
            'source_path' => $stored['relative_path'],
            'source_file' => $stored['source_file'],
            'document_type' => 'new',
        ]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldNotReceive('putFile');
        $this->app->instance(BuuMinioService::class, $mock);

        $response = $this->postJson('/api/internal/pipeline-callback', [
            'document_id' => $documentId,
            'status' => 'success',
            'output' => [
                'document_id' => $documentId,
                'source_file' => 'source.pdf',
                'source_type' => 'pdf_text',
                'language' => 'th',
                'summary' => [
                    'page_count' => 1,
                    'block_count' => 0,
                    'review_required_count' => 0,
                ],
                'pages' => [],
            ],
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);

        $status = $store->getStatus($documentId);
        $this->assertArrayNotHasKey('minio_source_filename', $status);

        $store->deleteDocument($documentId);
    }
}
