<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class ReviewStoreMongoTest extends TestCase
{
    private ReviewStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = app(ReviewStore::class);
    }

    public function test_set_status_then_get_status_round_trips(): void
    {
        $id = 'smoke_'.uniqid();

        $this->store->setStatus($id, ['status' => 'queued', 'source_file' => 'a.docx']);
        $status = $this->store->getStatus($id);

        $this->assertSame('queued', $status['status']);
        $this->assertSame('a.docx', $status['source_file']);
        $this->assertSame($id, $status['document_id']);
        $this->assertArrayHasKey('updated_at', $status);
    }

    public function test_get_status_returns_null_for_unknown(): void
    {
        $this->assertNull($this->store->getStatus('no_such_doc_'.uniqid()));
    }

    private function minimalDoc(string $id): array
    {
        return [
            'document_id' => $id,
            'source_file' => 'test.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'bbox' => null,
                    'reading_order' => 1,
                    'raw_text' => 'Hello',
                    'normalized_text' => 'Hello',
                    'ai_suggested_text' => 'Hello',
                    'approved_text' => 'Hello',
                    'confidence' => 1.0,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => ['reviewed_html' => '<p>Hello</p>'],
                ]],
            ]],
        ];
    }

    public function test_write_then_get_review_document_round_trips(): void
    {
        $id = 'smoke_'.uniqid();
        $doc = $this->minimalDoc($id);
        $this->store->writeReviewDocument($id, $doc);

        $result = $this->store->getReviewDocument($id);

        $this->assertSame($id, $result['document_id']);
        $this->assertSame('Hello', $result['pages'][0]['blocks'][0]['approved_text']);
    }

    public function test_get_review_document_throws_for_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Review document not found.');
        $this->store->getReviewDocument('no_such_doc_'.uniqid());
    }

    public function test_list_documents_reflects_written_status_and_review(): void
    {
        $id = 'smoke_'.uniqid();
        $this->store->setStatus($id, ['status' => 'done', 'source_file' => 'law.docx']);
        $doc = $this->minimalDoc($id);
        $doc['law_meta'] = ['title' => 'กฎหมายทดสอบ', 'law_type' => 'ระเบียบ'];
        $this->store->writeReviewDocument($id, $doc);

        $list = $this->store->listDocuments();
        $found = collect($list)->firstWhere('document_id', $id);

        $this->assertNotNull($found);
        $this->assertSame('done', $found['status']);
        $this->assertSame('กฎหมายทดสอบ', $found['title']);
    }

    public function test_patch_approved_block_persists_across_read(): void
    {
        $id = 'smoke_'.uniqid();
        $this->store->writeReviewDocument($id, $this->minimalDoc($id));

        $this->store->patchApprovedBlock($id, 1, 'b1', [
            'approved_text' => 'Updated',
            'mark_uncertain' => false,
        ]);

        $doc = $this->store->getReviewDocument($id);
        $this->assertSame('Updated', $doc['pages'][0]['blocks'][0]['approved_text']);
    }
}
