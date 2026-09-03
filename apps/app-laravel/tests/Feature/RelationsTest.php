<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RelationsTest extends TestCase
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
                    'block_id' => 'p1-b0001', 'type' => 'section_header', 'reading_order' => 1,
                    'raw_text' => 'มาตรา ๓', 'normalized_text' => 'มาตรา ๓',
                    'ai_suggested_text' => 'มาตรา ๓', 'approved_text' => 'มาตรา ๓',
                    'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                    'meta' => ['layout' => ['tabs' => []], 'formatting' => []],
                ]],
            ]],
        ]);
    }

    public function test_relations_default_to_empty_array(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_rel_'.uniqid();
        $this->seedDocument($store, $id);

        $doc = $store->getReviewDocument($id);
        $this->assertArrayHasKey('relations', $doc);
        $this->assertSame([], $doc['relations']);
    }

    public function test_relations_persist_and_normalize(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_rel_'.uniqid();
        $this->seedDocument($store, $id);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'relations' => [
                [
                    'id' => 'r1', 'scope' => 'section', 'block_id' => 'p1-b0001',
                    'type' => 'repeals', 'target_document_id' => null,
                    'target_title' => 'พ.ร.บ. เก่า', 'target_section' => 'มาตรา ๕',
                    'note' => '', 'url' => '',
                ],
                ['id' => 'r2', 'scope' => 'document', 'type' => 'related', 'target_title' => ''],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('relations.0.id', 'r1');
        $response->assertJsonPath('relations.0.type', 'repeals');
        $response->assertJsonPath('relations.0.target_section', 'มาตรา ๕');
        $response->assertJsonCount(1, 'relations');
    }

    public function test_relations_support_extended_types_and_target_block_id(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_rel_'.uniqid();
        $this->seedDocument($store, $id);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'relations' => [
                [
                    'id' => 'r-amend',
                    'scope' => 'section',
                    'block_id' => 'p1-b0001',
                    'type' => 'amends',
                    'target_document_id' => 'doc_target',
                    'target_title' => 'พ.ร.บ. ฉบับแก้ไข',
                    'target_section' => 'มาตรา ๒',
                    'target_block_id' => 'p1-b0042',
                    'note' => null,
                    'url' => null,
                    'change_detail' => 'แก้ไขข้อความ',
                ],
                [
                    'id' => 'r-supersedes',
                    'scope' => 'document',
                    'type' => 'supersedes',
                    'target_title' => 'พ.ร.บ. ฉบับเก่า',
                    'target_block_id' => 'p2-b0001',
                ],
                [
                    'id' => 'r-issued',
                    'scope' => 'document',
                    'type' => 'issued_under',
                    'target_title' => 'พ.ร.บ. แม่บท',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('relations.0.type', 'amends');
        $response->assertJsonPath('relations.0.target_block_id', 'p1-b0042');
        $response->assertJsonPath('relations.0.change_detail', 'แก้ไขข้อความ');
        $response->assertJsonPath('relations.1.type', 'supersedes');
        $response->assertJsonPath('relations.1.target_block_id', 'p2-b0001');
        $response->assertJsonPath('relations.2.type', 'issued_under');
        $response->assertJsonCount(3, 'relations');
    }

    public function test_whole_edition_replacement_revokes_same_level_chain(): void
    {
        $store = app(ReviewStore::class);
        $suffix = uniqid();
        $v1 = "doc_chain_{$suffix}_1";
        $v2 = "doc_chain_{$suffix}_2";
        $v4 = "doc_chain_{$suffix}_4";

        foreach ([$v1 => 'กฎหมายใหม่', $v2 => 'ปรับปรุงรายข้อ'] as $id => $change) {
            $store->setStatus($id, ['status' => 'done', 'source_file' => "{$id}.pdf"]);
            $store->writeReviewDocument($id, [
                'document_id' => $id,
                'source_file' => "{$id}.pdf",
                'source_type' => 'pdf',
                'language' => 'th',
                'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
                'law_meta' => [
                    'title' => 'ระเบียบว่าด้วยเอกสาร พ.ศ. '.($id === $v1 ? '๒๕๖๖' : '๒๕๖๗'),
                    'law_type' => 'ระเบียบ',
                    'status' => 'มีผลบังคับใช้',
                    'change_status' => $change,
                ],
                'pages' => [['page_no' => 1, 'blocks' => []]],
            ]);
        }

        $store->setStatus($v4, ['status' => 'done', 'source_file' => "{$v4}.pdf"]);
        $store->writeReviewDocument($v4, [
            'document_id' => $v4,
            'source_file' => "{$v4}.pdf",
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => [
                'title' => 'ระเบียบว่าด้วยเอกสาร พ.ศ. ๒๕๖๘',
                'law_type' => 'ระเบียบ',
                'status' => 'ร่าง',
                'change_status' => 'ปรับปรุงทั้งฉบับ',
            ],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
        Cache::forget('law-meta-list');

        $this->putJson("/api/documents/{$v4}/document-review", [
            'relations' => [[
                'id' => 'r-whole',
                'scope' => 'document',
                'type' => 'amends',
                'target_document_id' => $v2,
                'target_title' => 'ระเบียบว่าด้วยเอกสาร พ.ศ. ๒๕๖๗',
            ]],
        ])->assertOk();

        $this->assertSame('ยกเลิกการใช้งาน', $store->getReviewDocument($v1)['law_meta']['status']);
        $this->assertSame('ยกเลิกการใช้งาน', $store->getReviewDocument($v2)['law_meta']['status']);
        $this->assertSame('มีผลบังคับใช้', $store->getReviewDocument($v4)['law_meta']['status']);
    }
}
