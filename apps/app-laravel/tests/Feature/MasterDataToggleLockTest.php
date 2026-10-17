<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class MasterDataToggleLockTest extends TestCase
{
    private const IN_USE_MESSAGE = 'มีเอกสารใช้งานรายการนี้อยู่ จึงไม่สามารถปิดใช้งานได้';

    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_in_use_law_category_cannot_be_deactivated_but_unused_one_can(): void
    {
        $this->seedLawMeta('doc-lock-dct', ['law_groups' => ['DCT002']]);

        $this->patchJson('/api/master-data/law_category/DCT002/active', ['is_active' => true])->assertOk();
        $this->getJson('/api/master-data/law_category/DCT002')->assertOk()->assertJsonPath('usage_count', 1);

        $this->patchJson('/api/master-data/law_category/DCT002/active', ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('message', self::IN_USE_MESSAGE);
        $this->putJson('/api/master-data/law_category/DCT002', ['name' => 'ด้านกิจการนิสิต', 'is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('message', self::IN_USE_MESSAGE);
        $this->getJson('/api/master-data/law_category/DCT002')->assertJsonPath('is_active', true);

        $this->patchJson('/api/master-data/law_category/DCT003/active', ['is_active' => true])->assertOk();
        $this->patchJson('/api/master-data/law_category/DCT003/active', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_in_use_law_type_cannot_be_deactivated_but_unused_one_can(): void
    {
        $this->seedLawMeta('doc-lock-lty', ['law_type' => 'LTY01']);

        $this->getJson('/api/master-data/law_type/LTY01')
            ->assertOk()
            ->assertJsonPath('is_system', false)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('usage_count', 1);

        $this->patchJson('/api/master-data/law_type/LTY01/active', ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('message', self::IN_USE_MESSAGE);
        $this->getJson('/api/master-data/law_type/LTY01')->assertJsonPath('is_active', true);

        $this->patchJson('/api/master-data/law_type/LTY02/active', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
        $this->patchJson('/api/master-data/law_type/LTY02/active', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('is_active', true);
    }

    public function test_in_use_law_family_cannot_be_deactivated_but_unused_one_can(): void
    {
        $withType = $this->postJson('/api/master-data/law_family', [
            'name' => 'กลุ่มทดสอบมีประเภท',
            'attrs' => ['source' => 'internal', 'color' => '#6B7280'],
        ])->assertCreated()->json('code');
        $unused = $this->postJson('/api/master-data/law_family', [
            'name' => 'กลุ่มทดสอบว่าง',
            'attrs' => ['source' => 'internal', 'color' => '#6B7280'],
        ])->assertCreated()->json('code');
        $this->postJson('/api/master-data/law_type', [
            'name' => 'ประเภททดสอบ',
            'attrs' => ['family_code' => $withType],
        ])->assertCreated();

        $this->patchJson("/api/master-data/law_family/{$withType}/active", ['is_active' => true])->assertOk();
        $this->patchJson("/api/master-data/law_family/{$unused}/active", ['is_active' => true])->assertOk();
        $this->getJson("/api/master-data/law_family/{$withType}")->assertOk()->assertJsonPath('usage_count', 1);

        $this->patchJson("/api/master-data/law_family/{$withType}/active", ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('message', self::IN_USE_MESSAGE);
        $this->getJson("/api/master-data/law_family/{$withType}")->assertJsonPath('is_active', true);

        $this->patchJson("/api/master-data/law_family/{$unused}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_system_rows_keep_their_own_deactivation_rules(): void
    {
        $this->patchJson('/api/master-data/law_family/LFM01/active', ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('message', 'รายการของระบบไม่สามารถปิดใช้งานได้');
        $this->patchJson('/api/master-data/enforcement_status/STA01/active', ['is_active' => false])
            ->assertStatus(409);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function seedLawMeta(string $documentId, array $meta): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);
        $store->setStatus($documentId, ['document_id' => $documentId, 'status' => 'ingested']);
        $store->patchLawMeta($documentId, $meta);
    }
}
