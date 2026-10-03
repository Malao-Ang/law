<?php

namespace Tests\Feature;

use App\Services\MasterData\MasterDataKind;
use App\Services\Storage\MongoBlobStore;
use Tests\TestCase;

class MasterDataApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_unknown_kind_returns_404(): void
    {
        $this->getJson('/api/master-data/not_real')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Unknown master data kind');
    }

    public function test_create_generates_codes_uppercases_explicit_code_and_rejects_duplicates(): void
    {
        $this->postJson('/api/master-data/enforcement_status', [
            'name' => 'Alpha',
            'description' => 'First',
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'STA01')
            ->assertJsonPath('is_system', false);

        $this->postJson('/api/master-data/enforcement_status', [
            'name' => 'Beta',
            'description' => 'Second',
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'STA02');

        $this->postJson('/api/master-data/enforcement_status', [
            'code' => 'custom-1',
            'name' => 'Gamma',
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'CUSTOM-1');

        $this->postJson('/api/master-data/enforcement_status', [
            'code' => 'custom-1',
            'name' => 'Delta',
        ])->assertStatus(422);

        $this->postJson('/api/master-data/enforcement_status', [
            'name' => '  alpha  ',
        ])->assertStatus(422);
    }

    public function test_list_filters_paginates_and_keeps_stats_over_all_items(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Active One'])->assertCreated();
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Inactive Two'])->assertCreated();
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Active Three'])->assertCreated();
        $this->patchJson('/api/master-data/enforcement_status/STA02/active', ['is_active' => false])->assertOk();

        $this->getJson('/api/master-data/enforcement_status?q=active&active=1&page=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('stats.total', 3)
            ->assertJsonPath('stats.active', 2)
            ->assertJsonPath('stats.inactive', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.code', 'STA01')
            ->assertJsonPath('items.0.usage_count', 0);

        $this->getJson('/api/master-data/enforcement_status?active=0')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.code', 'STA02');
    }

    public function test_show_returns_usage_count_and_missing_returns_404(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Visible'])->assertCreated();

        $this->getJson('/api/master-data/enforcement_status/sta01')
            ->assertOk()
            ->assertJsonPath('code', 'STA01')
            ->assertJsonPath('usage_count', 0);

        $this->getJson('/api/master-data/enforcement_status/STA99')
            ->assertStatus(404);
    }

    public function test_update_changes_name_and_ignores_code_in_body(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Before'])->assertCreated();

        $this->putJson('/api/master-data/enforcement_status/STA01', [
            'code' => 'NEWCODE',
            'name' => 'After',
            'description' => 'Updated',
        ])
            ->assertOk()
            ->assertJsonPath('code', 'STA01')
            ->assertJsonPath('name', 'After')
            ->assertJsonPath('description', 'Updated');
    }

    public function test_set_active_toggles_and_system_item_cannot_be_deactivated(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Toggle'])->assertCreated();

        $this->patchJson('/api/master-data/enforcement_status/STA01/active', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->patchJson('/api/master-data/enforcement_status/STA01/active', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('is_active', true);

        $this->insertSystemItem();

        $this->patchJson('/api/master-data/enforcement_status/SYS01/active', ['is_active' => false])
            ->assertStatus(409);
    }

    public function test_delete_returns_conflict_when_kind_is_not_deletable(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'No Delete'])->assertCreated();

        $this->deleteJson('/api/master-data/enforcement_status/STA01')
            ->assertStatus(409);
    }

    public function test_reorder_changes_order(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'One'])->assertCreated();
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Two'])->assertCreated();
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Three'])->assertCreated();

        $this->patchJson('/api/master-data/enforcement_status/reorder', [
            'codes' => ['STA03', 'STA01', 'STA02'],
        ])->assertOk();

        $this->getJson('/api/master-data/enforcement_status?per_page=10')
            ->assertOk()
            ->assertJsonPath('items.0.code', 'STA03')
            ->assertJsonPath('items.1.code', 'STA01')
            ->assertJsonPath('items.2.code', 'STA02');
    }

    private function insertSystemItem(): void
    {
        /** @var MongoBlobStore $blob */
        $blob = app('mongo.blob.master');
        $timestamp = now()->toIso8601String();

        $blob->withLock('data', MasterDataKind::EnforcementStatus->value, function (array &$data) use ($timestamp): void {
            $data = [
                'items' => [[
                    'code' => 'SYS01',
                    'name' => 'System',
                    'description' => '',
                    'is_active' => true,
                    'is_system' => true,
                    'sort_order' => 1,
                    'aliases' => [],
                    'attrs' => ['role' => 'system'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]],
            ];
        });
    }
}
