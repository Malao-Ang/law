<?php

namespace Tests\Feature;

use App\Services\Buu\BuuMinioService;
use App\Services\ReviewStore;
use Mockery;
use Tests\TestCase;

class EsignSignedPdfTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_signed_pdf_is_not_available_before_callback(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-esign-pdf-wait-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_doc_filename' => 'waiting.pdf',
            'esign_sign_status' => null,
        ]);

        $this->getJson("/api/documents/{$docId}/esign/signed-pdf")->assertNotFound();
    }

    public function test_signed_pdf_returns_minio_view_and_download_links(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-esign-pdf-signed-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_sign_status' => 'Y',
            'esign_signed_filename' => 'signed-abc.pdf',
            'esign_signed_bucket' => 'library.elaw.storage',
        ]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('getPublicLinks')
            ->once()
            ->with(
                Mockery::on(fn ($fp) => ($fp['file'] ?? '') === 'signed-abc.pdf'),
                Mockery::any(),
                60,
                'M',
                'library.elaw.storage',
            )
            ->andReturn([
                'file' => [
                    'view' => 'https://minio.test/signed-abc.pdf?view',
                    'download' => 'https://minio.test/signed-abc.pdf?download',
                ],
            ]);
        $this->app->instance(BuuMinioService::class, $mock);

        $this->getJson("/api/documents/{$docId}/esign/signed-pdf")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('filename', 'signed-abc.pdf')
            ->assertJsonPath('view', 'https://minio.test/signed-abc.pdf?view')
            ->assertJsonPath('download', 'https://minio.test/signed-abc.pdf?download');
    }

    public function test_signed_pdf_download_redirects_to_minio_download_link(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-esign-pdf-dl-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_sign_status' => 'Y',
            'esign_signed_filename' => 'signed-abc.pdf',
            'esign_signed_bucket' => 'library.elaw.storage',
        ]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('getPublicLinks')
            ->once()
            ->andReturn([
                'file' => [
                    'view' => 'https://minio.test/signed-abc.pdf?view',
                    'download' => 'https://minio.test/signed-abc.pdf?download',
                ],
            ]);
        $this->app->instance(BuuMinioService::class, $mock);

        $this->get("/api/documents/{$docId}/esign/signed-pdf?download=1")
            ->assertRedirect('https://minio.test/signed-abc.pdf?download');
    }

    public function test_signed_pdf_redirect_query_redirects_to_minio_view_link(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-esign-pdf-redirect-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_sign_status' => 'Y',
            'esign_signed_filename' => 'signed-abc.pdf',
            'esign_signed_bucket' => 'library.elaw.storage',
        ]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('getPublicLinks')
            ->once()
            ->andReturn([
                'file' => [
                    'view' => 'https://minio.test/signed-abc.pdf?view',
                    'download' => 'https://minio.test/signed-abc.pdf?download',
                ],
            ]);
        $this->app->instance(BuuMinioService::class, $mock);

        $this->get("/api/documents/{$docId}/esign/signed-pdf?redirect=1&v=3")
            ->assertRedirect('https://minio.test/signed-abc.pdf?view');
    }

    public function test_signed_pdf_falls_back_to_doc_filename_when_status_is_y(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-esign-pdf-fallback-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_sign_status' => 'Y',
            'esign_doc_filename' => 'JsVeQwczBvzwnVeYtSVXHhLDDRAMFnSvB178gXtH.pdf',
            'esign_bucket' => 'library.elaw.storage',
        ]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('getPublicLinks')
            ->once()
            ->with(
                Mockery::on(fn ($fp) => ($fp['file'] ?? '') === 'JsVeQwczBvzwnVeYtSVXHhLDDRAMFnSvB178gXtH.pdf'),
                Mockery::any(),
                60,
                'M',
                'library.elaw.storage',
            )
            ->andReturn([
                'file' => [
                    'view' => 'https://minio.test/fallback.pdf?view',
                    'download' => 'https://minio.test/fallback.pdf?download',
                ],
            ]);
        $this->app->instance(BuuMinioService::class, $mock);

        $this->getJson("/api/documents/{$docId}/esign/signed-pdf")
            ->assertOk()
            ->assertJsonPath('view', 'https://minio.test/fallback.pdf?view');
    }
}
