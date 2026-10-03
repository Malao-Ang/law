<?php

namespace Tests\Unit;

use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\Storage\MongoBlobStore;
use Tests\TestCase;

class MasterDataStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_next_code_uses_prefix_padding_and_max_numeric_suffix(): void
    {
        $store = app(MasterDataStore::class);

        $this->assertSame('STA01', $store->create(MasterDataKind::EnforcementStatus, ['name' => 'One'])['code']);
        $this->assertSame('STA02', $store->create(MasterDataKind::EnforcementStatus, ['name' => 'Two'])['code']);
        $store->create(MasterDataKind::EnforcementStatus, ['code' => 'STA09', 'name' => 'Nine']);

        $this->assertSame('STA10', $store->create(MasterDataKind::EnforcementStatus, ['name' => 'Ten'])['code']);
    }

    public function test_resolve_matches_code_alias_and_name_including_inactive_items(): void
    {
        $store = app(MasterDataStore::class);
        $created = $store->create(MasterDataKind::EnforcementStatus, [
            'name' => 'Published',
            'aliases' => ['Live', 'Effective'],
        ]);

        $store->setActive(MasterDataKind::EnforcementStatus, $created['code'], false);

        $this->assertSame($created['code'], $store->resolve(MasterDataKind::EnforcementStatus, 'sta01')['code']);
        $this->assertSame($created['code'], $store->resolve(MasterDataKind::EnforcementStatus, ' live ')['code']);
        $this->assertSame($created['code'], $store->resolve(MasterDataKind::EnforcementStatus, 'published')['code']);
    }

    public function test_seed_if_empty_is_idempotent(): void
    {
        $store = app(MasterDataStore::class);

        $store->seedIfEmpty(MasterDataKind::EnforcementStatus);
        $first = $store->all(MasterDataKind::EnforcementStatus);
        $store->seedIfEmpty(MasterDataKind::EnforcementStatus);
        $second = $store->all(MasterDataKind::EnforcementStatus);

        $this->assertSame([], $first);
        $this->assertSame($first, $second);
    }

    public function test_seed_if_empty_does_not_replace_existing_items(): void
    {
        $store = app(MasterDataStore::class);
        /** @var MongoBlobStore $blob */
        $blob = app('mongo.blob.master');
        $timestamp = now()->toIso8601String();

        $blob->withLock('data', MasterDataKind::EnforcementStatus->value, function (array &$data) use ($timestamp): void {
            $data = [
                'items' => [[
                    'code' => 'STA99',
                    'name' => 'Existing',
                    'description' => '',
                    'is_active' => true,
                    'is_system' => false,
                    'sort_order' => 1,
                    'aliases' => [],
                    'attrs' => [],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]],
            ];
        });

        $store->seedIfEmpty(MasterDataKind::EnforcementStatus);

        $this->assertSame('STA99', $store->all(MasterDataKind::EnforcementStatus)[0]['code']);
    }
}
