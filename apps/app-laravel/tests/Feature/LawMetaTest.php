<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LawMetaTest extends TestCase
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
                    'block_id' => 'p1-b0001',
                    'type' => 'title',
                    'reading_order' => 1,
                    'raw_text' => 'พระราชบัญญัติ',
                    'normalized_text' => 'พระราชบัญญัติ',
                    'ai_suggested_text' => 'พระราชบัญญัติ',
                    'approved_text' => 'พระราชบัญญัติ',
                    'confidence' => 1.0,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => ['layout' => ['tabs' => []], 'formatting' => []],
                ]],
            ]],
        ]);
    }

    public function test_review_document_has_law_meta_defaults(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_'.uniqid();
        $this->seedDocument($store, $id);

        $doc = $store->getReviewDocument($id);

        $this->assertArrayHasKey('law_meta', $doc);
        $this->assertSame('', $doc['law_meta']['status']);
        $this->assertSame('', $doc['law_meta']['law_type']);
        $this->assertSame([], $doc['law_meta']['repealed_laws']);
    }

    public function test_update_document_review_persists_law_meta(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_upd_'.uniqid();
        $this->seedDocument($store, $id);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'status' => 'มีผลใช้บังคับ',
                'law_type' => 'พระราชบัญญัติ',
                'law_group' => 'ด้านวิชาการ',
                'agency' => 'มหาวิทยาลัยบูรพา',
                'promulgation_date' => '9 มกราคม 2551',
                'repealed_laws' => ['พ.ร.บ. เก่า ๒๕๓๓', ''],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('law_meta.status', 'มีผลใช้บังคับ');
        $response->assertJsonPath('law_meta.agency', 'มหาวิทยาลัยบูรพา');
        $response->assertJsonPath('law_meta.repealed_laws', ['พ.ร.บ. เก่า ๒๕๓๓']);

        $doc = $store->getReviewDocument($id);
        $this->assertSame('พระราชบัญญัติ', $doc['law_meta']['law_type']);
    }

    public function test_parent_document_id_persists_in_law_meta(): void
    {
        $store = app(ReviewStore::class);
        $parentId = 'doc_parent_'.uniqid();
        $childId = 'doc_child_'.uniqid();
        $this->seedDocument($store, $parentId);
        $this->seedDocument($store, $childId);

        $response = $this->putJson("/api/documents/{$childId}/document-review", [
            'law_meta' => [
                'title' => 'กฎหมายลูก',
                'parent_document_id' => $parentId,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('law_meta.parent_document_id', $parentId);

        $doc = $store->getReviewDocument($childId);
        $this->assertSame($parentId, $doc['law_meta']['parent_document_id']);
    }

    public function test_list_documents_includes_parent_document_id_and_title(): void
    {
        $store = app(ReviewStore::class);
        $parentId = 'doc_cat_parent_'.uniqid();
        $childId = 'doc_cat_child_'.uniqid();

        $store->setStatus($parentId, [
            'status' => 'done',
            'source_file' => 'parent.docx',
        ]);
        $store->setStatus($childId, [
            'status' => 'done',
            'source_file' => 'child.docx',
        ]);

        $store->writeReviewDocument($parentId, [
            'document_id' => $parentId,
            'source_file' => 'parent.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'law_meta' => ['title' => 'พ.ร.บ. แม่'],
            'pages' => [],
        ]);

        $store->writeReviewDocument($childId, [
            'document_id' => $childId,
            'source_file' => 'child.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'law_meta' => [
                'title' => 'กฎกระทรวงลูก',
                'parent_document_id' => $parentId,
            ],
            'pages' => [],
        ]);

        $response = $this->getJson('/api/documents');
        $response->assertOk();

        $documents = collect($response->json('documents'));
        $parent = $documents->firstWhere('document_id', $parentId);
        $child = $documents->firstWhere('document_id', $childId);

        $this->assertNotNull($parent);
        $this->assertSame('พ.ร.บ. แม่', $parent['title']);
        $this->assertNull($parent['parent_document_id']);

        $this->assertNotNull($child);
        $this->assertSame('กฎกระทรวงลูก', $child['title']);
        $this->assertSame($parentId, $child['parent_document_id']);
    }
}
