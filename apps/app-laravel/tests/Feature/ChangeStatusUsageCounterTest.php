<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class ChangeStatusUsageCounterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_usage_counts_resolve_codes_legacy_names_and_aliases(): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);
        $this->seedLaw($store, 'change_code', [
            'change_status' => 'CHG03',
            'change_details' => ['CHD01', 'CHD03', 'CHD01'],
        ]);
        $this->seedLaw($store, 'change_names', [
            'change_status' => 'ปรับปรุงรายข้อ',
            'change_details' => ['ยกเลิกข้อ', 'เพิ่มข้อความ'],
        ]);
        $this->seedLaw($store, 'change_aliases', [
            'change_status' => 'ยกเลิกรายมาตรา',
            'change_details' => ['ยกเลิก', 'เพิ่ม', 'แก้ไข'],
        ]);
        $this->seedLaw($store, 'change_unknown', [
            'change_status' => 'ไม่รู้จัก',
            'change_details' => ['ไม่รู้จัก'],
        ]);

        $this->getJson('/api/master-data/change_status/CHG03')
            ->assertOk()
            ->assertJsonPath('usage_count', 2);
        $this->getJson('/api/master-data/change_status/CHG04')
            ->assertOk()
            ->assertJsonPath('usage_count', 1);
        $this->getJson('/api/master-data/change_detail/CHD01')
            ->assertOk()
            ->assertJsonPath('usage_count', 3);
        $this->getJson('/api/master-data/change_detail/CHD03')
            ->assertOk()
            ->assertJsonPath('usage_count', 3);
        $this->getJson('/api/master-data/change_detail/CHD04')
            ->assertOk()
            ->assertJsonPath('usage_count', 1);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function seedLaw(ReviewStore $store, string $documentId, array $meta): void
    {
        $store->setStatus($documentId, ['document_id' => $documentId, 'status' => 'done']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => $documentId.'.pdf',
            'source_type' => 'pdf_text',
            'language' => 'th',
            'law_meta' => array_merge(['title' => $documentId], $meta),
            'pages' => [],
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
        ]);
    }
}
