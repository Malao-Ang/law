<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class DraftHtmlWritebackTest extends TestCase
{
    private function seedDocument(ReviewStore $store, string $id): void
    {
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'x.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'p1-b0001', 'type' => 'paragraph', 'reading_order' => 1,
                    'raw_text' => 'ข้อความเดิม', 'normalized_text' => 'ข้อความเดิม',
                    'ai_suggested_text' => 'ข้อความเดิม', 'approved_text' => 'ข้อความเดิม',
                    'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                    'meta' => ['layout' => ['tabs' => []], 'formatting' => []],
                ]],
            ]],
        ]);
    }

    public function test_saving_draft_html_updates_block_text(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_wb_'.uniqid();
        $this->seedDocument($store, $id);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'draft_html' => '<p data-block-id="p1-b0001">ข้อความใหม่</p>',
        ]);
        $response->assertOk();

        $doc = $store->getReviewDocument($id);
        $block = $doc['pages'][0]['blocks'][0];
        $this->assertSame('ข้อความใหม่', $block['approved_text']);
        $this->assertSame('ข้อความใหม่', $block['normalized_text']);
    }

    public function test_block_without_matching_id_is_left_untouched(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_wb2_'.uniqid();
        $this->seedDocument($store, $id);

        $this->putJson("/api/documents/{$id}/document-review", [
            'draft_html' => '<p data-block-id="p9-b9999">ไม่เกี่ยว</p>',
        ])->assertOk();

        $doc = $store->getReviewDocument($id);
        $this->assertSame('ข้อความเดิม', $doc['pages'][0]['blocks'][0]['approved_text']);
    }
}
