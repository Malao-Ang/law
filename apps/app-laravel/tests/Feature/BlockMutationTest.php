<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class BlockMutationTest extends TestCase
{
    private ReviewStore $store;

    private string $docId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = app(ReviewStore::class);
        $this->docId = 'doc-mutation-test-001';
        $this->store->setStatus($this->docId, ['status' => 'done', 'extraction_engine' => 'fast']);
        $this->store->writeReviewDocument($this->docId, [
            'document_id' => $this->docId,
            'source_file' => 'test.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'pages' => [[
                'page_no' => 1,
                'image_path' => null,
                'blocks' => [
                    ['block_id' => 'b1', 'type' => 'paragraph', 'bbox' => null, 'reading_order' => 1,
                        'raw_text' => 'Block one', 'normalized_text' => 'Block one', 'ai_suggested_text' => '',
                        'approved_text' => 'Block one', 'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                        'meta' => ['reviewed_html' => '<p>Block one</p>']],
                    ['block_id' => 'b2', 'type' => 'paragraph', 'bbox' => null, 'reading_order' => 2,
                        'raw_text' => 'Block two', 'normalized_text' => 'Block two', 'ai_suggested_text' => '',
                        'approved_text' => 'Block two', 'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                        'meta' => ['reviewed_html' => '<p>Block two</p>']],
                    ['block_id' => 'b3', 'type' => 'paragraph', 'bbox' => null, 'reading_order' => 3,
                        'raw_text' => 'Block three', 'normalized_text' => 'Block three', 'ai_suggested_text' => '',
                        'approved_text' => 'Block three', 'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                        'meta' => ['reviewed_html' => '<p>Block three</p>']],
                ],
            ]],
            'summary' => ['page_count' => 1, 'block_count' => 3, 'review_required_count' => 0],
        ]);
    }

    public function test_delete_block_removes_it(): void
    {
        $response = $this->deleteJson("/api/documents/{$this->docId}/blocks/b2?page_no=1");
        $response->assertStatus(200)->assertJsonFragment(['status' => 'deleted']);
        $doc = $this->store->getReviewDocument($this->docId);
        $ids = array_column($doc['pages'][0]['blocks'], 'block_id');
        $this->assertNotContains('b2', $ids);
        $this->assertCount(2, $ids);
    }

    public function test_merge_blocks_combines_text(): void
    {
        $response = $this->postJson("/api/documents/{$this->docId}/blocks/merge", [
            'block_ids' => ['b1', 'b2'],
        ]);
        $response->assertStatus(200)->assertJsonFragment(['status' => 'merged']);
        $doc = $this->store->getReviewDocument($this->docId);
        $ids = array_column($doc['pages'][0]['blocks'], 'block_id');
        $this->assertNotContains('b2', $ids);
        $block = collect($doc['pages'][0]['blocks'])->firstWhere('block_id', 'b1');
        $this->assertStringContainsString('Block one', $block['approved_text']);
        $this->assertStringContainsString('Block two', $block['approved_text']);
    }

    public function test_merge_includes_content_of_blocks_without_reviewed_html(): void
    {
        // b2 has approved_text but an EMPTY reviewed_html — its content must still
        // appear in the merged block's rendered HTML.
        $this->store->writeReviewDocument($this->docId, [
            'document_id' => $this->docId,
            'source_file' => 'test.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'pages' => [[
                'page_no' => 1,
                'image_path' => null,
                'blocks' => [
                    ['block_id' => 'b1', 'type' => 'paragraph', 'bbox' => null, 'reading_order' => 1,
                        'raw_text' => 'Alpha', 'normalized_text' => 'Alpha', 'ai_suggested_text' => '',
                        'approved_text' => 'Alpha', 'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                        'meta' => ['reviewed_html' => '<p>Alpha</p>']],
                    ['block_id' => 'b2', 'type' => 'paragraph', 'bbox' => null, 'reading_order' => 2,
                        'raw_text' => 'Beta', 'normalized_text' => 'Beta', 'ai_suggested_text' => '',
                        'approved_text' => 'Beta', 'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                        'meta' => ['reviewed_html' => '']],
                ],
            ]],
            'summary' => ['page_count' => 1, 'block_count' => 2, 'review_required_count' => 0],
        ]);

        $response = $this->postJson("/api/documents/{$this->docId}/blocks/merge", [
            'block_ids' => ['b1', 'b2'],
        ]);
        $response->assertStatus(200)->assertJsonFragment(['status' => 'merged']);

        $doc = $this->store->getReviewDocument($this->docId);
        $block = collect($doc['pages'][0]['blocks'])->firstWhere('block_id', 'b1');
        $this->assertStringContainsString('Alpha', $block['meta']['reviewed_html']);
        $this->assertStringContainsString('Beta', $block['meta']['reviewed_html']);
    }

    public function test_merge_orders_content_by_document_position_not_selection(): void
    {
        // Select in REVERSE document order; merged text and anchor must follow document order.
        $response = $this->postJson("/api/documents/{$this->docId}/blocks/merge", [
            'block_ids' => ['b3', 'b1'],
        ]);
        $response->assertStatus(200)->assertJsonFragment(['status' => 'merged']);

        $doc = $this->store->getReviewDocument($this->docId);
        $ids = array_column($doc['pages'][0]['blocks'], 'block_id');
        $this->assertContains('b1', $ids);        // topmost selected is the anchor
        $this->assertNotContains('b3', $ids);     // other selected block removed

        $block = collect($doc['pages'][0]['blocks'])->firstWhere('block_id', 'b1');
        $onePos = strpos($block['approved_text'], 'Block one');
        $threePos = strpos($block['approved_text'], 'Block three');
        $this->assertNotFalse($onePos);
        $this->assertNotFalse($threePos);
        $this->assertLessThan($threePos, $onePos); // "Block one" precedes "Block three"
    }

    public function test_merge_tolerates_duplicate_block_ids(): void
    {
        $response = $this->postJson("/api/documents/{$this->docId}/blocks/merge", [
            'block_ids' => ['b1', 'b2', 'b1'],
        ]);
        $response->assertStatus(200)->assertJsonFragment(['status' => 'merged']);

        $doc = $this->store->getReviewDocument($this->docId);
        $ids = array_column($doc['pages'][0]['blocks'], 'block_id');
        $this->assertContains('b1', $ids);
        $this->assertNotContains('b2', $ids);
        $block = collect($doc['pages'][0]['blocks'])->firstWhere('block_id', 'b1');
        $this->assertStringContainsString('Block one', $block['approved_text']);
        $this->assertStringContainsString('Block two', $block['approved_text']);
    }

    public function test_split_block_creates_two_blocks(): void
    {
        $response = $this->postJson("/api/documents/{$this->docId}/blocks/b1/split", [
            'page_no' => 1,
            'before_text' => 'Block',
            'before_html' => '<p>Block</p>',
            'after_text' => 'one',
            'after_html' => '<p>one</p>',
        ]);
        $response->assertStatus(200)->assertJsonFragment(['status' => 'split']);
        $data = $response->json();
        $this->assertNotEmpty($data['first']['block_id']);
        $this->assertNotEmpty($data['second']['block_id']);
        $this->assertNotEquals($data['first']['block_id'], $data['second']['block_id']);
        $doc = $this->store->getReviewDocument($this->docId);
        $this->assertCount(4, $doc['pages'][0]['blocks']);
    }

    public function test_create_block_inserts_after_target(): void
    {
        $response = $this->postJson("/api/documents/{$this->docId}/blocks", [
            'page_no' => 1,
            'after_block_id' => 'b1',
            'type' => 'paragraph',
            'approved_text' => 'New block',
            'reviewed_html' => '<p>New block</p>',
        ]);
        $response->assertStatus(200)->assertJsonFragment(['status' => 'created']);
        $doc = $this->store->getReviewDocument($this->docId);
        $blocks = $doc['pages'][0]['blocks'];
        $this->assertCount(4, $blocks);
        $this->assertEquals('b1', $blocks[0]['block_id']);
        $this->assertEquals('New block', $blocks[1]['approved_text']);
    }
}
