<?php

namespace Tests\Feature;

use App\Services\Fast\LibreOfficeConverter;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PdfExportControllerTest extends TestCase
{
    public function test_export_pdf_returns_pdf_and_stamps_esign_exported_at(): void
    {
        $this->app->instance(LibreOfficeConverter::class, new LibreOfficeConverter(
            binary: 'libreoffice',
            commandRunner: function (array $cmd): int {
                $base = pathinfo($cmd[count($cmd) - 1], PATHINFO_FILENAME);
                $outDir = $cmd[array_search('--outdir', $cmd, true) + 1];
                file_put_contents("{$outDir}/{$base}.pdf", '%PDF-1.7 test');

                return 0;
            },
        ));

        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();

        $store->storeUpload(
            UploadedFile::fake()->create('law.pdf', 64, 'application/pdf'),
            $documentId,
        );

        $store->setStatus($documentId, ['status' => 'done']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'law.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => 1,
                'review_required_count' => 0,
            ],
            'law_meta' => [
                'title' => 'กฎหมายทดสอบ',
            ],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'bbox' => null,
                    'reading_order' => 1,
                    'raw_text' => 'ข้อความทดสอบ',
                    'normalized_text' => '',
                    'ai_suggested_text' => '',
                    'approved_text' => 'ข้อความที่อนุมัติแล้ว',
                    'confidence' => 1,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => [
                        'reviewed_html' => '<p><strong>ข้อความที่อนุมัติแล้ว</strong></p>',
                        'layout' => ['tabs' => []],
                        'formatting' => [],
                    ],
                ]],
            ]],
            'document_review' => [
                'generated_html' => '',
                'draft_html' => '',
                'html_mode' => 'generated',
                'out_of_sync' => false,
            ],
        ]);

        $response = $this->post('/api/documents/'.$documentId.'/export-pdf');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $status = $store->getStatus($documentId);
        $this->assertNotNull($status['esign_exported_at'] ?? null);
    }

    public function test_export_pdf_returns_503_when_service_is_unavailable(): void
    {
        $this->app->instance(LibreOfficeConverter::class, new LibreOfficeConverter(
            binary: 'libreoffice',
            commandRunner: fn (array $cmd): int => 1,
        ));

        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();
        $store->setStatus($documentId, ['status' => 'done']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'law.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => 1,
                'review_required_count' => 0,
            ],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'bbox' => null,
                    'reading_order' => 1,
                    'raw_text' => 'ข้อความทดสอบ',
                    'normalized_text' => '',
                    'ai_suggested_text' => '',
                    'approved_text' => 'ข้อความที่อนุมัติแล้ว',
                    'confidence' => 1,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => ['layout' => ['tabs' => []], 'formatting' => []],
                ]],
            ]],
            'document_review' => [
                'generated_html' => '',
                'draft_html' => '',
                'html_mode' => 'generated',
                'out_of_sync' => false,
            ],
        ]);

        $this->post('/api/documents/'.$documentId.'/export-pdf')
            ->assertStatus(503)
            ->assertSeeText('PDF service unavailable');
    }
}
