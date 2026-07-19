<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use App\Services\Fast\LibreOfficeConverter;
use Tests\TestCase;

class OriginalPdfExportTest extends TestCase
{
    public function test_exports_original_docx_as_pdf(): void
    {
        $this->app->instance(LibreOfficeConverter::class, new LibreOfficeConverter(
            binary: 'libreoffice',
            commandRunner: function (array $cmd): int {
                $base = pathinfo($cmd[count($cmd) - 1], PATHINFO_FILENAME);
                $outDir = $cmd[array_search('--outdir', $cmd, true) + 1];
                file_put_contents("{$outDir}/{$base}.pdf", '%PDF-1.7 original');
                return 0;
            },
        ));

        $store = app(ReviewStore::class);
        $id = 'doc_orig_'.uniqid();

        $relative = 'uploads/'.$id.'.docx';
        $absolute = $store->absoluteUploadPath($relative);
        @mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, 'PK fake docx');
        $store->setStatus($id, [
            'document_id' => $id,
            'source_file' => 'ต้นฉบับ.docx',
            'source_path' => $relative,
        ]);

        $response = $this->post("/api/documents/{$id}/export-pdf-original", [], ['Accept' => 'application/pdf']);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $content = $response->getContent();
        $this->assertStringStartsWith('%PDF', $content);

        @unlink($absolute);
    }

    public function test_rejects_non_docx_source(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_orig_pdf_'.uniqid();
        $store->setStatus($id, [
            'document_id' => $id,
            'source_file' => 'scan.pdf',
            'source_path' => 'uploads/'.$id.'.pdf',
        ]);

        $this->post("/api/documents/{$id}/export-pdf-original", [], ['Accept' => 'application/pdf'])
            ->assertStatus(422);
    }
}
