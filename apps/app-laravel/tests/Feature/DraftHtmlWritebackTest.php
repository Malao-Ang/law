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

    public function test_saving_draft_html_writes_back_text_alignment(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_wb_align_'.uniqid();
        $this->seedDocument($store, $id);

        // TipTap renders alignment as an inline style on the block's own <p>
        // element (the same node carrying data-block-id).
        $this->putJson("/api/documents/{$id}/document-review", [
            'draft_html' => '<p data-block-id="p1-b0001" style="text-align:center">ข้อความ</p>',
        ])->assertOk();

        $doc = $store->getReviewDocument($id);
        $block = $doc['pages'][0]['blocks'][0];
        $this->assertSame('center', $block['meta']['layout']['alignment'] ?? null);
    }

    public function test_saving_draft_html_clears_alignment_when_reverted(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_wb_align2_'.uniqid();
        $this->seedDocument($store, $id);

        // First center it, then revert (TipTap omits the style for the default 'left').
        $this->putJson("/api/documents/{$id}/document-review", [
            'draft_html' => '<p data-block-id="p1-b0001" style="text-align:center">ข้อความ</p>',
        ])->assertOk();
        $this->putJson("/api/documents/{$id}/document-review", [
            'draft_html' => '<p data-block-id="p1-b0001">ข้อความ</p>',
        ])->assertOk();

        $doc = $store->getReviewDocument($id);
        $block = $doc['pages'][0]['blocks'][0];
        $this->assertNull($block['meta']['layout']['alignment'] ?? null);
    }

    public function test_saving_draft_html_writes_back_margin_left_as_indent_left(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_wb_indent_'.uniqid();
        $this->seedDocument($store, $id);

        $this->putJson("/api/documents/{$id}/document-review", [
            'draft_html' => '<p data-block-id="p1-b0001" style="margin-left:48px">ข้อความ</p>',
        ])->assertOk();

        $doc = $store->getReviewDocument($id);
        $block = $doc['pages'][0]['blocks'][0];
        $this->assertSame(720, $block['meta']['layout']['indent_left'] ?? null);
    }

    public function test_saving_draft_html_clears_indent_left_when_margin_is_removed(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_wb_indent2_'.uniqid();
        $this->seedDocument($store, $id);

        $this->putJson("/api/documents/{$id}/document-review", [
            'draft_html' => '<p data-block-id="p1-b0001" style="margin-left:48px">ข้อความ</p>',
        ])->assertOk();
        $this->putJson("/api/documents/{$id}/document-review", [
            'draft_html' => '<p data-block-id="p1-b0001">ข้อความ</p>',
        ])->assertOk();

        $doc = $store->getReviewDocument($id);
        $block = $doc['pages'][0]['blocks'][0];
        $this->assertNull($block['meta']['layout']['indent_left'] ?? null);
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
