<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WordExportControllerTest extends TestCase
{
    public function test_export_word_returns_docx_and_stamps_esign_exported_at(): void
    {
        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();

        $store->storeUpload(
            UploadedFile::fake()->create('ประกาศ (2).pdf', 64, 'application/pdf'),
            $documentId,
        );

        $store->setStatus($documentId, ['status' => 'done']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'ประกาศ (2).pdf',
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

        $response = $this->postJson("/api/documents/{$documentId}/export-word");

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            (string) $response->headers->get('Content-Type'),
        );
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString("filename*=utf-8''", strtolower($disposition));
        $this->assertStringContainsString('%E0%B8%9B%E0%B8%A3%E0%B8%B0%E0%B8%81%E0%B8%B2%E0%B8%A8', $disposition);

        $status = $store->getStatus($documentId);
        $this->assertNotNull($status['esign_exported_at'] ?? null);
    }

    public function test_export_word_returns_404_for_unknown_document(): void
    {
        $this->postJson('/api/documents/nonexistent-id/export-word')
            ->assertStatus(404);
    }
}
