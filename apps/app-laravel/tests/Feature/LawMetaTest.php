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
        $this->assertSame('public', $doc['law_meta']['access_scope']);
        $this->assertSame([], $doc['law_meta']['permission_group_ids']);
        $this->assertSame([], $doc['law_meta']['keywords']);
        $this->assertSame([], $doc['law_meta']['repealed_laws']);
    }

    public function test_update_document_review_persists_law_meta(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_upd_'.uniqid();
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'x.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 2, 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    [
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
                    ],
                    [
                        'block_id' => 'p1-b0002',
                        'type' => 'paragraph',
                        'reading_order' => 2,
                        'raw_text' => 'มาตรา 1',
                        'normalized_text' => 'มาตรา 1',
                        'ai_suggested_text' => 'มาตรา 1',
                        'approved_text' => 'มาตรา 1',
                        'confidence' => 1.0,
                        'needs_review' => false,
                        'flags' => [],
                        'meta' => ['chunk_type' => 'ARTICLE', 'layout' => ['tabs' => []], 'formatting' => []],
                    ],
                ],
            ]],
        ]);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'status' => 'มีผลใช้บังคับ',
                'law_type' => 'พระราชบัญญัติ',
                'law_group' => 'ด้านวิชาการ',
                'agency' => 'มหาวิทยาลัยบูรพา',
                'section_count' => 25,
                'keywords' => ['ข้อมูลส่วนบุคคล', ' PDPA ', 'ข้อมูลส่วนบุคคล'],
                'promulgation_date' => '9 มกราคม 2551',
                'repealed_laws' => ['พ.ร.บ. เก่า ๒๕๓๓', ''],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('law_meta.status', 'มีผลใช้บังคับ');
        $response->assertJsonPath('law_meta.agency', 'มหาวิทยาลัยบูรพา');
        $response->assertJsonPath('law_meta.section_count', 1);
        $response->assertJsonPath('law_meta.keywords', ['ข้อมูลส่วนบุคคล', 'PDPA']);
        $response->assertJsonPath('law_meta.repealed_laws', ['พ.ร.บ. เก่า ๒๕๓๓']);

        $doc = $store->getReviewDocument($id);
        $this->assertSame('พระราชบัญญัติ', $doc['law_meta']['law_type']);
        $this->assertSame(1, $doc['law_meta']['section_count']);
        $this->assertSame(['ข้อมูลส่วนบุคคล', 'PDPA'], $doc['law_meta']['keywords']);
    }

    public function test_update_document_review_accepts_thai_change_status(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_change_'.uniqid();
        $this->seedDocument($store, $id);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'change_status' => 'กฎหมายล่าสุด',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('law_meta.change_status', 'กฎหมายล่าสุด');

        $doc = $store->getReviewDocument($id);
        $this->assertSame('กฎหมายล่าสุด', $doc['law_meta']['change_status']);
    }

    public function test_public_access_scope_clears_permission_groups(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_public_'.uniqid();
        $this->seedDocument($store, $id);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'access_scope' => 'public',
                'permission_group_ids' => ['pg_missing'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('law_meta.access_scope', 'public');
        $response->assertJsonPath('law_meta.permission_group_ids', []);
    }

    public function test_private_access_scope_requires_valid_permission_group_ids(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_private_'.uniqid();
        $this->seedDocument($store, $id);

        $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'access_scope' => 'private',
                'permission_group_ids' => [],
            ],
        ])->assertStatus(422);

        $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'access_scope' => 'private',
                'permission_group_ids' => ['pg_unknown'],
            ],
        ])->assertStatus(422);
    }

    public function test_private_access_scope_persists_with_existing_permission_groups(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_private_ok_'.uniqid();
        $this->seedDocument($store, $id);

        $group = $this->postJson('/api/permission-groups', [
            'name' => 'กลุ่มเอกสารส่วนบุคคล_'.uniqid(),
            'description' => 'ใช้กับเอกสารที่จำกัดการเข้าถึง',
            'unit_ids' => ['unit_legal'],
            'position_ids' => [],
            'user_ids' => ['user_somchai'],
        ])->assertCreated()->json();

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'access_scope' => 'private',
                'permission_group_ids' => [$group['id']],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('law_meta.access_scope', 'private');
        $response->assertJsonPath('law_meta.permission_group_ids', [$group['id']]);
    }

    public function test_section_count_is_derived_from_article_and_clause_blocks_on_save(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_section_'.uniqid();

        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'x.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 3, 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    [
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
                        'meta' => ['chunk_type' => 'TITLE', 'layout' => ['tabs' => []], 'formatting' => []],
                    ],
                    [
                        'block_id' => 'p1-b0002',
                        'type' => 'paragraph',
                        'reading_order' => 2,
                        'raw_text' => 'มาตรา 1',
                        'normalized_text' => 'มาตรา 1',
                        'ai_suggested_text' => 'มาตรา 1',
                        'approved_text' => 'มาตรา 1',
                        'confidence' => 1.0,
                        'needs_review' => false,
                        'flags' => [],
                        'meta' => ['chunk_type' => 'ARTICLE', 'layout' => ['tabs' => []], 'formatting' => []],
                    ],
                    [
                        'block_id' => 'p1-b0003',
                        'type' => 'paragraph',
                        'reading_order' => 3,
                        'raw_text' => 'ข้อ 2',
                        'normalized_text' => 'ข้อ 2',
                        'ai_suggested_text' => 'ข้อ 2',
                        'approved_text' => 'ข้อ 2',
                        'confidence' => 1.0,
                        'needs_review' => false,
                        'flags' => [],
                        'meta' => ['chunk_type' => 'CLAUSE', 'layout' => ['tabs' => []], 'formatting' => []],
                    ],
                ],
            ]],
        ]);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'section_count' => 999,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('law_meta.section_count', 2);

        $doc = $store->getReviewDocument($id);
        $this->assertSame(2, $doc['law_meta']['section_count']);
    }

    public function test_keywords_longer_than_limit_are_rejected(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_kw_'.uniqid();
        $this->seedDocument($store, $id);

        $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'keywords' => [str_repeat('k', 81)],
            ],
        ])->assertStatus(422);
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

    public function test_update_document_review_normalizes_multi_value_law_meta_and_legacy_fields(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_multi_'.uniqid();
        $this->seedDocument($store, $id);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'law_meta' => [
                'law_groups' => ['ด้านวิชาการ', 'ด้านกฎหมายและนิติการ'],
                'agencies' => ['มหาวิทยาลัยบูรพา', 'สำนักงานอธิการบดี'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('law_meta.law_group', 'ด้านวิชาการ');
        $response->assertJsonPath('law_meta.law_groups', ['ด้านวิชาการ', 'ด้านกฎหมายและนิติการ']);
        $response->assertJsonPath('law_meta.agency', 'มหาวิทยาลัยบูรพา');
        $response->assertJsonPath('law_meta.agencies', ['มหาวิทยาลัยบูรพา', 'สำนักงานอธิการบดี']);

        $doc = $store->getReviewDocument($id);
        $this->assertSame('ด้านวิชาการ', $doc['law_meta']['law_group']);
        $this->assertSame(['ด้านวิชาการ', 'ด้านกฎหมายและนิติการ'], $doc['law_meta']['law_groups']);
        $this->assertSame('มหาวิทยาลัยบูรพา', $doc['law_meta']['agency']);
        $this->assertSame(['มหาวิทยาลัยบูรพา', 'สำนักงานอธิการบดี'], $doc['law_meta']['agencies']);
    }

    public function test_legacy_law_meta_values_are_promoted_to_multi_value_arrays(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_lawmeta_legacy_'.uniqid();
        $this->seedDocument($store, $id);

        $store->updateDocumentReview($id, [
            'law_meta' => [
                'law_group' => 'ด้านวิชาการ',
                'agency' => 'มหาวิทยาลัยบูรพา',
            ],
        ]);

        $doc = $store->getReviewDocument($id);
        $this->assertSame(['ด้านวิชาการ'], $doc['law_meta']['law_groups']);
        $this->assertSame(['มหาวิทยาลัยบูรพา'], $doc['law_meta']['agencies']);
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
