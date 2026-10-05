<?php

namespace Tests\Unit;

use App\Services\MasterData\ChangeStatuses;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Tests\TestCase;

class ChangeStatusesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_resolves_status_code_name_and_alias_with_roles(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);
        $created = $store->create(MasterDataKind::ChangeStatus, ['name' => 'สถานะเพิ่มเติม']);
        $store->setActive(MasterDataKind::ChangeStatus, $created['code'], false);

        /** @var ChangeStatuses $statuses */
        $statuses = app(ChangeStatuses::class);

        $this->assertSame('CHG02', $statuses->resolve('CHG02')['code']);
        $this->assertSame('CHG02', $statuses->resolve('ปรับปรุงทั้งฉบับ')['code']);
        $this->assertSame('CHG02', $statuses->resolve('ยกเลิกทั้งฉบับ')['code']);
        $this->assertFalse($statuses->resolve('สถานะเพิ่มเติม')['is_active']);
        $this->assertSame('whole', $statuses->role('ปรับปรุงทั้งฉบับ'));
        $this->assertTrue($statuses->isWhole('CHG02'));
        $this->assertTrue($statuses->isSection('ยกเลิกรายมาตรา'));
        $this->assertTrue($statuses->isNew('กฎหมายใหม่'));
        $this->assertNull($statuses->role('ไม่พบสถานะ'));
    }

    public function test_resolves_detail_code_name_and_alias_with_roles(): void
    {
        /** @var ChangeStatuses $statuses */
        $statuses = app(ChangeStatuses::class);

        $this->assertSame('CHD01', $statuses->resolveDetail('CHD01')['code']);
        $this->assertSame('CHD01', $statuses->resolveDetail('ยกเลิกข้อ')['code']);
        $this->assertSame('CHD01', $statuses->resolveDetail('ยกเลิก')['code']);
        $this->assertSame('CHD03', $statuses->resolveDetail('เพิ่ม')['code']);
        $this->assertSame('CHD04', $statuses->resolveDetail('แก้ไข')['code']);
        $this->assertSame('repeals', $statuses->detailRole('ยกเลิก'));
        $this->assertSame('amends', $statuses->detailRole('CHD04'));
        $this->assertNull($statuses->detailRole('ไม่พบรายละเอียด'));
    }
}
