<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentJob;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    public function test_upload_queues_extraction_and_sets_status(): void
    {
        Queue::fake();

        $response = $this->post('/api/documents', [
            'file' => UploadedFile::fake()->create('thai-legal.pdf', 64, 'application/pdf'),
        ]);

        $response->assertStatus(202)->assertJsonStructure(['document_id', 'status']);

        $documentId = (string) $response->json('document_id');

        Queue::assertPushed(ExtractDocumentJob::class);

        $this->getJson('/api/documents/'.$documentId)
            ->assertOk()
            ->assertJson([
                'document_id' => $documentId,
                'status' => 'queued',
            ]);
    }

    public function test_patch_and_export_flow_returns_success(): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);

        $documentId = 'doc_test_flow';
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'example.pdf',
            'source_type' => 'pdf_text',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => 1,
                'review_required_count' => 1,
            ],
            'pages' => [[
                'page_no' => 1,
                'image_path' => null,
                'blocks' => [[
                    'block_id' => '1-1',
                    'type' => 'paragraph',
                    'bbox' => [20, 20, 400, 120],
                    'reading_order' => 1,
                    'raw_text' => 'พระราชบญญตั ิ',
                    'normalized_text' => 'พระราชบัญญัติ',
                    'ai_suggested_text' => 'พระราชบัญญัติ',
                    'approved_text' => '',
                    'confidence' => 0.7,
                    'needs_review' => true,
                    'flags' => ['low_confidence'],
                    'meta' => [],
                ]],
            ]],
        ]);

        $this->patchJson('/api/documents/'.$documentId.'/blocks/1-1', [
            'page_no' => 1,
            'approved_text' => 'พระราชบัญญัติ',
            'mark_uncertain' => false,
            'type' => 'section_header',
            'reading_order' => 3,
            'bbox' => [32, 44, 420, 140],
            'reviewed_html' => '<h2>พระราชบัญญัติ</h2>',
        ])->assertOk()->assertJson(['status' => 'updated']);

        $exportResponse = $this->postJson('/api/documents/'.$documentId.'/export')
            ->assertOk()
            ->assertJson([
                'document_id' => $documentId,
                'status' => 'exported',
            ]);

        $this->assertNotNull($exportResponse->json('export_path'));

        $review = $store->getReviewDocument($documentId);
        $block = $review['pages'][0]['blocks'][0];
        $this->assertSame('section_header', $block['type']);
        $this->assertSame(3, $block['reading_order']);
        $this->assertSame('<h2>พระราชบัญญัติ</h2>', $block['meta']['reviewed_html']);
    }
}
