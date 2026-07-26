<?php

namespace Tests\Feature;

use App\Services\DocumentExportService;
use App\Services\ReviewStore;
use Tests\TestCase;

class PdfExportPublishTest extends TestCase
{
    public function test_pdf_export_sets_esign_exported_at_and_public_scope(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-pdf-publish-'.uniqid();
        $store->writeReviewDocument($docId, [
            'document_id' => $docId,
            'source_file' => 'test.pdf',
            'pages' => [],
            'law_meta' => ['access_scope' => 'private'],
        ]);
        $store->setStatus($docId, ['status' => 'done']);

        $this->mock(DocumentExportService::class, function ($mock) {
            $mock->shouldReceive('toPdf')->andReturn('%PDF-1.4 fake');
            $mock->shouldReceive('safeFilenameBase')->andReturn('test');
        });

        $response = $this->postJson("/api/documents/{$docId}/export-pdf");

        $response->assertStatus(200);

        $doc = $store->getReviewDocument($docId);
        $this->assertSame('public', $doc['law_meta']['access_scope']);

        $status = $store->getStatus($docId);
        $this->assertNotNull($status['esign_exported_at'] ?? null);
    }

    public function test_pdf_preview_uses_review_pdf_inline_without_publishing(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-pdf-preview-'.uniqid();
        $store->writeReviewDocument($docId, [
            'document_id' => $docId,
            'source_file' => 'preview.pdf',
            'pages' => [],
            'law_meta' => ['access_scope' => 'private'],
        ]);
        $store->setStatus($docId, ['status' => 'done']);

        $this->mock(DocumentExportService::class, function ($mock) {
            $mock->shouldReceive('toPdf')->andReturn('%PDF-1.4 preview');
            $mock->shouldReceive('safeFilenameBase')->andReturn('preview');
        });

        $response = $this->get("/api/documents/{$docId}/export-pdf/preview");

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $doc = $store->getReviewDocument($docId);
        $this->assertSame('private', $doc['law_meta']['access_scope']);

        $status = $store->getStatus($docId);
        $this->assertArrayNotHasKey('esign_exported_at', $status);
    }
}
