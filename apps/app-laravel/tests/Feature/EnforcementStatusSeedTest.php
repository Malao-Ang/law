<?php

namespace Tests\Feature;

use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Tests\TestCase;

class EnforcementStatusSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_seed_has_three_system_items_with_roles_colors_and_aliases(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);

        $items = $store->all(MasterDataKind::EnforcementStatus);

        $this->assertCount(3, $items);
        $this->assertSame([
            ['STA01', 'มีผลบังคับใช้', 'in_force', 'success', ['มีผลบังคับใช้']],
            ['STA02', 'ยกเลิกการใช้งาน', 'repealed', 'error', ['ยกเลิกการใช้งาน']],
            ['STA03', 'ร่าง', 'draft', 'grey', ['ร่าง', '']],
        ], array_map(static fn (array $item): array => [
            $item['code'],
            $item['name'],
            $item['attrs']['role'] ?? null,
            $item['attrs']['color'] ?? null,
            $item['aliases'],
        ], $items));

        foreach ($items as $item) {
            $this->assertTrue($item['is_system']);
            $this->assertSame(['role', 'color'], array_keys($item['attrs']));
        }
    }
}
