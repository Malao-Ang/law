<?php

namespace Tests\Feature;

use App\Exceptions\MasterDataConflict;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Tests\TestCase;

class ChangeStatusMasterDataSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_change_status_and_detail_seeds_are_system_items_and_idempotent(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);

        $statuses = $store->all(MasterDataKind::ChangeStatus);
        $details = $store->all(MasterDataKind::ChangeDetail);

        $this->assertSame(['CHG01', 'CHG02', 'CHG03', 'CHG04'], array_column($statuses, 'code'));
        $this->assertSame(['CHD01', 'CHD02', 'CHD03', 'CHD04'], array_column($details, 'code'));
        $this->assertSame(['new', 'whole', 'section', 'section'], array_map(
            static fn (array $item): string => (string) $item['attrs']['role'],
            $statuses,
        ));
        $this->assertSame(['repeals', 'repeals', 'amends', 'amends'], array_map(
            static fn (array $item): string => (string) $item['attrs']['role'],
            $details,
        ));
        $this->assertSame(['both', 'both', 'internal', 'external'], array_map(
            static fn (array $item): string => (string) $item['attrs']['source'],
            $statuses,
        ));
        $this->assertSame([false, false, true, true], array_map(
            static fn (array $item): bool => (bool) $item['attrs']['has_details'],
            $statuses,
        ));
        $this->assertContains('ยกเลิกทั้งฉบับ', $statuses[1]['aliases']);
        $this->assertContains('ยกเลิกรายมาตรา', $statuses[3]['aliases']);
        $this->assertContains('ยกเลิก', $details[0]['aliases']);
        $this->assertContains('เพิ่ม', $details[2]['aliases']);
        $this->assertContains('แก้ไข', $details[3]['aliases']);
        $this->assertNotContains(false, array_column($statuses, 'is_system'));
        $this->assertNotContains(false, array_column($details, 'is_system'));

        $store->seedIfEmpty(MasterDataKind::ChangeStatus);
        $store->seedIfEmpty(MasterDataKind::ChangeDetail);

        $this->assertSame($statuses, $store->all(MasterDataKind::ChangeStatus));
        $this->assertSame($details, $store->all(MasterDataKind::ChangeDetail));
    }

    public function test_system_items_cannot_be_deactivated_but_name_can_change_without_changing_attrs(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);
        $before = $store->find(MasterDataKind::ChangeStatus, 'CHG02');

        try {
            $store->setActive(MasterDataKind::ChangeStatus, 'CHG02', false);
            $this->fail('Expected system change status to reject deactivation.');
        } catch (MasterDataConflict) {
            $this->assertTrue(true);
        }

        $updated = $store->update(MasterDataKind::ChangeStatus, 'CHG02', [
            'name' => 'ปรับปรุงทั้งฉบับ (แก้ชื่อ)',
            'attrs' => ['source' => 'external', 'has_details' => true, 'role' => 'general'],
        ]);

        $this->assertSame('ปรับปรุงทั้งฉบับ (แก้ชื่อ)', $updated['name']);
        $this->assertSame($before['attrs'], $updated['attrs']);
    }

    public function test_admin_created_change_items_force_general_attrs_and_start_inactive(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);

        $status = $store->create(MasterDataKind::ChangeStatus, [
            'name' => 'สถานะทดสอบ',
            'attrs' => ['source' => 'internal', 'has_details' => true, 'role' => 'section'],
        ]);
        $detail = $store->create(MasterDataKind::ChangeDetail, [
            'name' => 'รายละเอียดทดสอบ',
            'attrs' => ['source' => 'external', 'has_details' => true, 'role' => 'repeals', 'color' => 'error', 'icon' => 'mdi-cancel'],
        ]);

        $this->assertSame('CHG05', $status['code']);
        $this->assertFalse($status['is_active']);
        $this->assertFalse($status['is_system']);
        $this->assertSame(['source' => 'both', 'has_details' => false, 'role' => 'general'], $status['attrs']);
        $this->assertSame('CHD05', $detail['code']);
        $this->assertFalse($detail['is_active']);
        $this->assertFalse($detail['is_system']);
        $this->assertSame([
            'source' => 'both',
            'has_details' => false,
            'role' => 'general',
            'color' => null,
            'icon' => null,
        ], $detail['attrs']);
    }
}
