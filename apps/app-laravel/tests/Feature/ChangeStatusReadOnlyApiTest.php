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

    public function test_change_status_and_detail_cannot_be_written(): void
    {
        foreach (['change_status' => 'CHG02', 'change_detail' => 'CHD01'] as $kind => $code) {
            $before = $this->getJson("/api/master-data/{$kind}/{$code}")->assertOk()->json();

            $this->postJson("/api/master-data/{$kind}", ['name' => 'สถานะใหม่'])
                ->assertStatus(403)
                ->assertJsonPath('message', 'รายการนี้เป็นข้อมูลของระบบ ดูได้อย่างเดียว');
            $this->putJson("/api/master-data/{$kind}/{$code}", ['name' => 'เปลี่ยนชื่อ'])
                ->assertStatus(403);
            $this->patchJson("/api/master-data/{$kind}/{$code}/active", ['is_active' => false])
                ->assertStatus(403);
            $this->patchJson("/api/master-data/{$kind}/reorder", ['codes' => [$code]])
                ->assertStatus(403);
            $this->deleteJson("/api/master-data/{$kind}/{$code}")
                ->assertStatus(403);

            $after = $this->getJson("/api/master-data/{$kind}/{$code}")->assertOk()->json();
            $this->assertSame($before['name'], $after['name']);
            $this->assertSame($before['is_active'], $after['is_active']);
            $this->assertSame($before['sort_order'], $after['sort_order']);
        }

        $this->assertCount(4, $this->getJson('/api/master-data/change_status')->json('items'));
    }

    public function test_other_kinds_stay_writable(): void
    {
        $this->postJson('/api/master-data/enforcement_status', ['name' => 'ทดสอบเขียนได้'])
            ->assertCreated();
    }
}
