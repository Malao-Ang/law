<?php

namespace Tests\Feature;

use App\Services\Permissions\PermissionStore;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PermissionStoreMongoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Group members are real unit codes (DEP...) and real usernames.
        $users = app(\App\Services\Auth\MongoUserStore::class);
        foreach (['user_somchai', 'user_suda', 'user_kanya'] as $username) {
            $users->upsertFromLogin($username, ['name' => $username], null, false);
        }
    }

    public function test_directory_seeds_and_returns_units_positions_and_users(): void
    {
        $store = app(PermissionStore::class);

        $directory = $store->directory();

        $this->assertArrayHasKey('units', $directory);
        $this->assertArrayHasKey('positions', $directory);
        $this->assertArrayHasKey('users', $directory);
        $this->assertNotEmpty($directory['units']);
        $this->assertNotEmpty($directory['positions']);
        $this->assertNotEmpty($directory['users']);
        $this->assertSame($directory, $store->directory());
    }

    public function test_create_group_then_find_group_round_trips_data(): void
    {
        $store = app(PermissionStore::class);

        $created = $store->createGroup([
            'name' => 'กลุ่มสิทธิ์วิชาการ_'.uniqid(),
            'description' => 'ใช้กับเอกสารภายในคณะ',
            'unit_ids' => ['DEP105'],
            'position_ids' => ['position_dean'],
            'user_ids' => ['user_kanya'],
        ]);

        $found = $store->findGroup($created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame($created['name'], $found['name']);
        $this->assertSame(['DEP105'], $found['unit_ids']);
        $this->assertSame(['position_dean'], $found['position_ids']);
        $this->assertSame(['user_kanya'], $found['user_ids']);
        $this->assertSame(3, $found['counts']['total']);
    }

    public function test_duplicate_name_throws_validation_exception(): void
    {
        $store = app(PermissionStore::class);
        $name = 'กลุ่มสิทธิ์ซ้ำ_'.uniqid();

        $store->createGroup([
            'name' => $name,
            'description' => null,
            'unit_ids' => ['DEP101'],
            'position_ids' => [],
            'user_ids' => [],
        ]);

        $this->expectException(ValidationException::class);

        $store->createGroup([
            'name' => $name,
            'description' => 'duplicate',
            'unit_ids' => ['DEP103'],
            'position_ids' => [],
            'user_ids' => [],
        ]);
    }

    public function test_update_group_persists_into_list_groups(): void
    {
        $store = app(PermissionStore::class);

        $created = $store->createGroup([
            'name' => 'กลุ่มปรับปรุง_'.uniqid(),
            'description' => 'ก่อนแก้ไข',
            'unit_ids' => ['DEP101'],
            'position_ids' => [],
            'user_ids' => ['user_somchai'],
        ]);

        $updated = $store->updateGroup($created['id'], [
            'name' => 'กลุ่มปรับปรุงแล้ว',
            'description' => 'หลังแก้ไข',
            'unit_ids' => ['DEP101', 'DEP103'],
            'position_ids' => ['position_legal_officer'],
            'user_ids' => [],
        ]);

        $groups = $store->listGroups();
        $reloaded = collect($groups)->firstWhere('id', $created['id']);

        $this->assertNotNull($updated);
        $this->assertIsArray($reloaded);
        $this->assertSame('กลุ่มปรับปรุงแล้ว', $reloaded['name']);
        $this->assertSame(['DEP101', 'DEP103'], $reloaded['unit_ids']);
        $this->assertSame(['position_legal_officer'], $reloaded['position_ids']);
        $this->assertSame([], $reloaded['user_ids']);
    }

    public function test_delete_group_removes_it_from_list_groups(): void
    {
        $store = app(PermissionStore::class);

        $created = $store->createGroup([
            'name' => 'กลุ่มลบ_'.uniqid(),
            'description' => null,
            'unit_ids' => ['DEP104'],
            'position_ids' => [],
            'user_ids' => [],
        ]);

        $this->assertTrue($store->deleteGroup($created['id']));
        $this->assertNull($store->findGroup($created['id']));
        $this->assertFalse(collect($store->listGroups())->contains(fn (array $group): bool => $group['id'] === $created['id']));
    }

    public function test_validate_group_ids_accepts_valid_ids_and_rejects_unknown_ones(): void
    {
        $store = app(PermissionStore::class);

        $group = $store->createGroup([
            'name' => 'กลุ่มตรวจสอบ_'.uniqid(),
            'description' => null,
            'unit_ids' => ['DEP102'],
            'position_ids' => [],
            'user_ids' => [],
        ]);

        $this->assertSame([$group['id']], $store->validateGroupIds([$group['id'], $group['id']]));

        try {
            $store->validateGroupIds([$group['id'], 'pg_unknown']);
            $this->fail('Expected ValidationException for unknown permission group id.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('law_meta.permission_group_ids', $exception->errors());
        }
    }
}
