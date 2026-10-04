<?php

namespace Tests\Feature;

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

    public function test_create_always_generates_running_codes_and_rejects_duplicate_names(): void
    {
        $this->postJson('/api/master-data/enforcement_status', [
            'name' => 'Alpha',
            'description' => 'First',
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'STA04')
            ->assertJsonPath('is_system', false);

        $this->postJson('/api/master-data/enforcement_status', [
            'name' => 'Beta',
            'description' => 'Second',
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'STA05');

        $this->postJson('/api/master-data/enforcement_status', [
            'code' => 'custom-1',
            'name' => 'Gamma',
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'STA06');

        $this->getJson('/api/master-data/enforcement_status')
            ->assertOk()
            ->assertJsonPath('next_code', 'STA07');

        $this->postJson('/api/master-data/enforcement_status', [
            'name' => '  alpha  ',
        ])->assertStatus(422);
    }

    public function test_list_filters_paginates_and_keeps_stats_over_all_items(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Active One'])->assertCreated();
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Inactive Two'])->assertCreated();
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Active Three'])->assertCreated();
        $this->patchJson('/api/master-data/enforcement_status/STA05/active', ['is_active' => false])->assertOk();

        $this->getJson('/api/master-data/enforcement_status?q=active&active=1&page=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('stats.total', 6)
            ->assertJsonPath('stats.active', 5)
            ->assertJsonPath('stats.inactive', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.code', 'STA04')
            ->assertJsonPath('items.0.usage_count', 0);

        $this->getJson('/api/master-data/enforcement_status?active=0')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.code', 'STA05');
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

        $this->putJson('/api/master-data/enforcement_status/STA04', [
            'code' => 'NEWCODE',
            'name' => 'After',
            'description' => 'Updated',
        ])
            ->assertOk()
            ->assertJsonPath('code', 'STA04')
            ->assertJsonPath('name', 'After')
            ->assertJsonPath('description', 'Updated');
    }

    public function test_set_active_toggles_and_system_item_cannot_be_deactivated(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Toggle'])->assertCreated();

        $this->patchJson('/api/master-data/enforcement_status/STA04/active', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->patchJson('/api/master-data/enforcement_status/STA04/active', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('is_active', true);

        $this->patchJson('/api/master-data/enforcement_status/STA01/active', ['is_active' => false])
            ->assertStatus(409);
    }

    public function test_delete_returns_conflict_when_kind_is_not_deletable(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'No Delete'])->assertCreated();

        $this->deleteJson('/api/master-data/enforcement_status/STA04')
            ->assertStatus(409);
    }

    public function test_reorder_changes_order(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'One'])->assertCreated();
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Two'])->assertCreated();
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'Three'])->assertCreated();

        $this->patchJson('/api/master-data/enforcement_status/reorder', [
            'codes' => ['STA06', 'STA04', 'STA05'],
        ])->assertOk();

        $this->getJson('/api/master-data/enforcement_status?per_page=10')
            ->assertOk()
            ->assertJsonPath('items.0.code', 'STA06')
            ->assertJsonPath('items.1.code', 'STA04')
            ->assertJsonPath('items.2.code', 'STA05');
    }
}
