<?php

namespace Tests\Feature;

use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\ReviewStore;
use Tests\TestCase;

class LegalStructureMasterDataSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_legal_structure_seeds_are_exact_and_idempotent(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);

        $first = $store->all(MasterDataKind::LegalStructure);
        $store->seedIfEmpty(MasterDataKind::LegalStructure);
        $second = $store->all(MasterDataKind::LegalStructure);

        $this->assertCount(12, $first);
        $this->assertSame([
            'LST001', 'LST002', 'LST003', 'LST004', 'LST005', 'LST006',
            'LST007', 'LST008', 'LST009', 'LST010', 'LST011', 'LST012',
        ], array_column($first, 'code'));

        $byCode = array_column($first, null, 'code');
        $this->assertSame('ระบุชื่อของเอกสารกฎหมาย', $byCode['LST001']['description']);
        $this->assertSame(['LFM01', 'LFM02', 'LFM03'], $byCode['LST004']['attrs']['family_codes']);
        $this->assertSame(['LFM04'], $byCode['LST011']['attrs']['family_codes']);
        $this->assertSame(['word', 'pdf'], $byCode['LST004']['attrs']['file_types']);
        $this->assertSame(['word', 'pdf'], $byCode['LST011']['attrs']['file_types']);
        $this->assertSame(['CLAUSE', 'ARTICLE', 'PARAGRAPH', 'ITEM'], $byCode['LST004']['aliases']);
        $this->assertSame(['CHAPTER', 'BOOK', 'PART'], $byCode['LST012']['aliases']);
        $this->assertTrue($byCode['LST004']['attrs']['counts_as_section']);
        $this->assertTrue($byCode['LST011']['attrs']['counts_as_section']);
        $this->assertFalse($byCode['LST012']['attrs']['counts_as_section']);
        foreach ($first as $item) {
            $this->assertTrue($item['is_system']);
            $this->assertFalse($item['attrs']['is_required']);
        }
        $this->assertSame($first, $second);
    }

    public function test_new_legal_structure_gets_next_code_and_defaults(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);

        $created = $store->create(MasterDataKind::LegalStructure, [
            'name' => 'ภาคผนวก',
            'attrs' => [
                'family_codes' => ['LFM01'],
                'file_types' => ['word'],
            ],
        ]);

        $this->assertSame('LST013', $created['code']);
        $this->assertFalse($created['is_active']);
        $this->assertSame('blue-grey', $created['attrs']['color']);
        $this->assertSame('LST013', $created['attrs']['export_key']);
        $this->assertTrue($created['attrs']['is_head']);
        $this->assertFalse($created['attrs']['counts_as_section']);
        $this->assertFalse($created['attrs']['is_required']);
        $this->assertSame(['LST013'], $created['aliases']);
    }

    public function test_api_validates_legal_structure_attrs_with_thai_messages(): void
    {
        $response = $this->postJson('/api/master-data/legal_structure', [
            'name' => 'ไม่มีกลุ่ม',
            'attrs' => ['family_codes' => [], 'file_types' => ['word']],
        ])->assertStatus(422)->assertJsonValidationErrors(['attrs.family_codes']);
        $this->assertSame('เลือกประเภทเอกสารที่รองรับอย่างน้อย 1 กลุ่ม', $response->json('errors')['attrs.family_codes'][0] ?? null);

        $response = $this->postJson('/api/master-data/legal_structure', [
            'name' => 'กลุ่มหาย',
            'attrs' => ['family_codes' => ['LFM99'], 'file_types' => ['word']],
        ])->assertStatus(422)->assertJsonValidationErrors(['attrs.family_codes']);
        $this->assertSame('ไม่พบกลุ่มประเภทเอกสารที่เลือก', $response->json('errors')['attrs.family_codes'][0] ?? null);

        $response = $this->postJson('/api/master-data/legal_structure', [
            'name' => 'ไฟล์ผิด',
            'attrs' => ['family_codes' => ['LFM01'], 'file_types' => ['excel']],
        ])->assertStatus(422)->assertJsonValidationErrors(['attrs.file_types']);
        $this->assertSame('เลือกชนิดไฟล์ที่รองรับอย่างน้อย 1 ชนิด', $response->json('errors')['attrs.file_types'][0] ?? null);

        $response = $this->postJson('/api/master-data/legal_structure', [
            'name' => 'รหัสส่งออกซ้ำ',
            'attrs' => ['family_codes' => ['LFM01'], 'file_types' => ['word'], 'export_key' => 'TITLE'],
        ])->assertStatus(422)->assertJsonValidationErrors(['attrs.export_key']);
        $this->assertSame('รหัสส่งออกซ้ำกับรายการอื่น', $response->json('errors')['attrs.export_key'][0] ?? null);
    }

    public function test_system_export_key_is_immutable_but_family_and_file_removal_do_not_409(): void
    {
        /** @var ReviewStore $reviewStore */
        $reviewStore = app(ReviewStore::class);
        $reviewStore->setStatus('law_lst004', ['document_id' => 'law_lst004', 'status' => 'done']);
        $reviewStore->writeReviewDocument('law_lst004', [
            'document_id' => 'law_lst004',
            'source_file' => 'law.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'law_meta' => ['title' => 'law'],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'approved_text' => 'ข้อ 1',
                    'meta' => ['chunk_type' => 'LST004'],
                ]],
            ]],
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
        ]);

        $this->putJson('/api/master-data/legal_structure/LST004', [
            'name' => 'ข้อ',
            'attrs' => [
                'family_codes' => ['LFM01', 'LFM02', 'LFM03'],
                'file_types' => ['word', 'pdf'],
                'export_key' => 'OTHER',
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['attrs.export_key']);

        $this->putJson('/api/master-data/legal_structure/LST004', [
            'name' => 'ข้อ',
            'attrs' => [
                'family_codes' => ['LFM01'],
                'file_types' => ['word'],
                'export_key' => 'CLAUSE',
            ],
        ])->assertOk()
            ->assertJsonPath('attrs.family_codes', ['LFM01'])
            ->assertJsonPath('attrs.file_types', ['word']);
    }
}
