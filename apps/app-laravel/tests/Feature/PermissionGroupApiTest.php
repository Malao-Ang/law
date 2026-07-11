<?php

namespace Tests\Feature;

use Tests\TestCase;

class PermissionGroupApiTest extends TestCase
{
    public function test_directory_endpoint_returns_units_positions_and_users(): void
    {
        $this->getJson('/api/permission-directory')
            ->assertOk()
            ->assertJsonStructure([
                'units' => [['id', 'name']],
                'positions' => [['id', 'name']],
                'users' => [['id', 'name']],
            ]);
    }

    public function test_permission_group_crud_flow_returns_hydrated_counts(): void
    {
        $name = 'เอกสารงบประมาณและการเงินภายใน_'.uniqid();
        $created = $this->postJson('/api/permission-groups', [
            'name' => $name,
            'description' => 'ใช้สำหรับเอกสารอนุมัติงบประมาณและรายงานการเงิน',
            'unit_ids' => ['unit_finance'],
            'position_ids' => ['position_finance_director'],
            'user_ids' => ['user_somchai'],
        ])
            ->assertCreated()
            ->assertJsonPath('counts.units', 1)
            ->assertJsonPath('counts.positions', 1)
            ->assertJsonPath('counts.users', 1)
            ->json();

        $groupId = $created['id'];

        $this->getJson("/api/permission-groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('id', $groupId)
            ->assertJsonPath('units.0.id', 'unit_finance');

        $this->putJson("/api/permission-groups/{$groupId}", [
            'name' => 'เอกสารการเงินปรับปรุง',
            'description' => 'ปรับสิทธิ์สำหรับเอกสารการเงิน',
            'unit_ids' => ['unit_finance', 'unit_procurement'],
            'position_ids' => [],
            'user_ids' => ['user_suda'],
        ])
            ->assertOk()
            ->assertJsonPath('name', 'เอกสารการเงินปรับปรุง')
            ->assertJsonPath('counts.units', 2)
            ->assertJsonPath('counts.users', 1);

        $this->getJson('/api/permission-groups')
            ->assertOk()
            ->assertJsonFragment(['id' => $groupId, 'name' => 'เอกสารการเงินปรับปรุง']);

        $this->deleteJson("/api/permission-groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('status', 'deleted');
    }

    public function test_duplicate_and_invalid_permission_group_payloads_return_422(): void
    {
        $name = 'กลุ่มเอกสารภายใน_'.uniqid();
        $this->postJson('/api/permission-groups', [
            'name' => $name,
            'description' => 'กลุ่มตั้งต้น',
            'unit_ids' => ['unit_legal'],
            'position_ids' => [],
            'user_ids' => [],
        ])->assertCreated();

        $this->postJson('/api/permission-groups', [
            'name' => $name,
            'description' => 'ซ้ำ',
            'unit_ids' => ['unit_science'],
            'position_ids' => [],
            'user_ids' => [],
        ])->assertStatus(422);

        $this->postJson('/api/permission-groups', [
            'name' => 'กลุ่มไม่มีสมาชิก',
            'description' => null,
            'unit_ids' => [],
            'position_ids' => [],
            'user_ids' => [],
        ])->assertStatus(422);

        $this->postJson('/api/permission-groups', [
            'name' => 'กลุ่ม id ผิด',
            'description' => null,
            'unit_ids' => ['unit_not_found'],
            'position_ids' => [],
            'user_ids' => [],
        ])->assertStatus(422);
    }
}
