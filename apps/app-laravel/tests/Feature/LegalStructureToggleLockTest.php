<?php

namespace Tests\Feature;

use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\ReviewStore;
use Tests\TestCase;

class LegalStructureToggleLockTest extends TestCase
{
    private const IN_USE_MESSAGE = 'มีเอกสารใช้งานโครงสร้างนี้อยู่ จึงไม่สามารถปิดใช้งานได้';

    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
        $this->seedDocumentWithChunkType('doc-lst-lock-001', 'LST004');
    }

    public function test_deactivating_in_use_structure_via_active_endpoint_is_rejected(): void
    {
        $this->getJson('/api/master-data/legal_structure/LST004')
            ->assertOk()
            ->assertJsonPath('is_system', false)
            ->assertJsonPath('usage_count', 1);

        $this->patchJson('/api/master-data/legal_structure/LST004/active', ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('message', self::IN_USE_MESSAGE);

        $this->getJson('/api/master-data/legal_structure/LST004')
            ->assertOk()
            ->assertJsonPath('is_active', true);
    }

    public function test_deactivating_in_use_structure_via_update_is_rejected(): void
    {
        $this->putJson('/api/master-data/legal_structure/LST004', ['name' => 'ข้อ', 'is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('message', self::IN_USE_MESSAGE);

        $this->putJson('/api/master-data/legal_structure/LST004', ['name' => 'ข้อ (แก้ไข)', 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('is_active', true);
    }

    public function test_unused_structure_can_be_toggled_and_reactivated(): void
    {
        $this->patchJson('/api/master-data/legal_structure/LST002/active', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->patchJson('/api/master-data/legal_structure/LST002/active', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('is_active', true);
    }

    public function test_in_use_structure_that_is_already_inactive_can_be_reactivated(): void
    {
        $this->seedDocumentWithChunkType('doc-lst-lock-002', 'LST003');
        $store = app(MasterDataStore::class);
        $store->all(MasterDataKind::LegalStructure);
        $items = app('mongo.blob.master')->read('data', 'legal_structure')['items'];
        foreach ($items as &$item) {
            if ($item['code'] === 'LST003') {
                $item['is_active'] = false;
            }
        }
        unset($item);
        app('mongo.blob.master')->write('data', 'legal_structure', ['items' => $items]);

        $this->patchJson('/api/master-data/legal_structure/LST003/active', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('is_active', true);
    }

    public function test_existing_system_flagged_seed_rows_are_upgraded_on_read(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);
        $store->all(MasterDataKind::LegalStructure);

        $blob = app('mongo.blob.master');
        $items = $blob->read('data', 'legal_structure')['items'];
        foreach ($items as &$item) {
            $item['is_system'] = true;
        }
        unset($item);
        $items[] = [
            'code' => 'LST900',
            'name' => 'รายการเพิ่มเอง',
            'description' => '',
            'is_active' => true,
            'is_system' => true,
            'sort_order' => 99,
            'aliases' => [],
            'attrs' => [],
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
        $blob->write('data', 'legal_structure', ['items' => $items]);

        $byCode = array_column($store->all(MasterDataKind::LegalStructure), null, 'code');

        $this->assertCount(13, $byCode);
        foreach (range(1, 12) as $number) {
            $this->assertFalse($byCode[sprintf('LST%03d', $number)]['is_system']);
        }
        $this->assertTrue($byCode['LST900']['is_system']);
    }

    private function seedDocumentWithChunkType(string $documentId, string $chunkType): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);
        $store->setStatus($documentId, ['document_id' => $documentId, 'status' => 'done']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => $documentId.'.pdf',
            'source_type' => 'pdf_text',
            'language' => 'th',
            'pages' => [[
                'page_no' => 1,
                'image_path' => null,
                'blocks' => [[
                    'block_id' => 'b1', 'type' => 'paragraph', 'bbox' => null, 'reading_order' => 1,
                    'raw_text' => 'x', 'normalized_text' => 'x', 'ai_suggested_text' => '',
                    'approved_text' => 'x', 'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                    'meta' => ['chunk_type' => $chunkType],
                ]],
            ]],
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
        ]);
    }
}
