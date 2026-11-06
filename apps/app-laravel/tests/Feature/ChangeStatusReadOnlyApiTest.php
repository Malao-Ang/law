<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChangeStatusReadOnlyApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_change_status_and_detail_can_be_listed(): void
    {
        $this->getJson('/api/master-data/change_status')
            ->assertOk()
            ->assertJsonPath('items.0.code', 'CHG01');

        $this->getJson('/api/master-data/change_detail')
            ->assertOk()
            ->assertJsonPath('items.0.code', 'CHD01');
    }

    public function test_system_change_items_cannot_be_edited_or_deleted(): void
    {
        foreach (['change_status' => 'CHG02', 'change_detail' => 'CHD01'] as $kind => $code) {
            $before = $this->getJson("/api/master-data/{$kind}/{$code}")->assertOk()->json();

            $this->putJson("/api/master-data/{$kind}/{$code}", [
                'name' => 'เปลี่ยนชื่อ',
                'attrs' => ['source' => 'external', 'role' => $kind === 'change_status' ? 'general' : 'amends'],
            ])->assertStatus(403);
            $this->patchJson("/api/master-data/{$kind}/reorder", ['codes' => [$code]])
                ->assertStatus(403);
            $this->deleteJson("/api/master-data/{$kind}/{$code}")
                ->assertStatus(409);

            $after = $this->getJson("/api/master-data/{$kind}/{$code}")->assertOk()->json();
            $this->assertSame($before['name'], $after['name']);
            $this->assertSame($before['attrs'], $after['attrs']);
            $this->assertSame($before['is_active'], $after['is_active']);
        }
    }

    public function test_custom_change_items_keep_role_and_can_be_activated(): void
    {
        $status = $this->postJson('/api/master-data/change_status', [
            'name' => 'สถานะทดสอบ',
            'attrs' => ['source' => 'internal', 'role' => 'section', 'has_details' => true],
        ])->assertCreated()->json();

        $this->assertSame('CHG05', $status['code']);
        $this->assertFalse($status['is_active']);
        $this->assertSame('section', $status['attrs']['role']);
        $this->assertTrue($status['attrs']['has_details']);
        $this->assertSame('internal', $status['attrs']['source']);

        $this->putJson('/api/master-data/change_status/CHG05', [
            'name' => 'สถานะทดสอบแก้',
            'attrs' => ['source' => 'external', 'role' => 'whole', 'has_details' => true],
        ])->assertOk()
            ->assertJsonPath('name', 'สถานะทดสอบแก้')
            ->assertJsonPath('attrs.role', 'whole')
            ->assertJsonPath('attrs.has_details', false)
            ->assertJsonPath('attrs.source', 'external');

        $detail = $this->postJson('/api/master-data/change_detail', [
            'name' => 'รายละเอียดทดสอบ',
            'attrs' => ['source' => 'external', 'role' => 'repeals', 'color' => 'error', 'icon' => 'mdi-cancel'],
        ])->assertCreated()->json();

        $this->assertSame('CHD05', $detail['code']);
        $this->assertFalse($detail['is_active']);
        $this->assertSame('repeals', $detail['attrs']['role']);
        $this->assertSame('error', $detail['attrs']['color']);
        $this->assertSame('mdi-cancel', $detail['attrs']['icon']);

        $this->patchJson('/api/master-data/change_detail/CHD05/active', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('is_active', true);
        $this->deleteJson('/api/master-data/change_detail/CHD05')->assertNoContent();
    }

    public function test_other_kinds_stay_writable(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'ทดสอบเขียนได้'])
            ->assertCreated();
    }
}
