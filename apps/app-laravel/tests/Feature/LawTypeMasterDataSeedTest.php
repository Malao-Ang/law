<?php

namespace Tests\Feature;

use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\ReviewStore;
use Tests\TestCase;

class LawTypeMasterDataSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_law_family_issuer_and_law_type_seeds_are_exact_and_idempotent(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);

        $families = $store->all(MasterDataKind::LawFamily);
        $issuers = $store->all(MasterDataKind::Issuer);
        $types = $store->all(MasterDataKind::LawType);

        $this->assertCount(4, $families);
        $this->assertSame([
            ['LFM01', 'ข้อบังคับ', 'internal', '#10B981', true],
            ['LFM02', 'ระเบียบ', 'internal', '#3B82F6', true],
            ['LFM03', 'ประกาศ', 'internal', '#FB923C', true],
            ['LFM04', 'กฎหมายภายนอก', 'external', '#854D0E', true],
        ], array_map(static fn (array $item): array => [
            $item['code'],
            $item['name'],
            $item['attrs']['source'] ?? null,
            $item['attrs']['color'] ?? null,
            $item['is_system'],
        ], $families));

        $this->assertSame([
            ['ISS01', 'มหาวิทยาลัย', ['มหาวิทยาลัย']],
            ['ISS02', 'สภามหาวิทยาลัย', ['สภามหาวิทยาลัย']],
        ], array_map(static fn (array $item): array => [
            $item['code'],
            $item['name'],
            $item['aliases'],
        ], $issuers));

        $this->assertCount(8, $types);
        $this->assertSame(['ประกาศ', 'ประกาศที่ออกโดยมหาวิทยาลัย', 'ประกาศที่ออกโดยสภามหาวิทยาลัย', 'คำสั่ง', 'มติ'], $types[0]['aliases']);
        $this->assertSame(['พระราชบัญญัติ', 'พ.ร.บ.', 'พรบ'], $types[4]['aliases']);
        $this->assertSame(['กฎหมายภายนอก'], $types[7]['aliases']);
        $this->assertSame(['family_code' => 'LFM03', 'requires_issuer' => true], $types[0]['attrs']);
        $this->assertFalse($types[0]['is_system']);

        $store->seedIfEmpty(MasterDataKind::LawFamily);
        $store->seedIfEmpty(MasterDataKind::Issuer);
        $store->seedIfEmpty(MasterDataKind::LawType);

        $this->assertSame($families, $store->all(MasterDataKind::LawFamily));
        $this->assertSame($issuers, $store->all(MasterDataKind::Issuer));
        $this->assertSame($types, $store->all(MasterDataKind::LawType));
    }

    public function test_api_law_type_validation_and_in_use_guards(): void
    {
        $this->postJson('/api/master-data/law_family', [
            'name' => 'คำสั่งมหาวิทยาลัย',
            'attrs' => ['source' => 'internal', 'color' => '#111827'],
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'LFM05')
            ->assertJsonPath('is_active', false);

        $this->postJson('/api/master-data/law_type', [
            'name' => 'คำสั่งใหม่',
            'attrs' => ['family_code' => 'LFM05', 'requires_issuer' => true],
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'LTY09')
            ->assertJsonPath('attrs.family_code', 'LFM05')
            ->assertJsonPath('attrs.requires_issuer', true)
            ->assertJsonPath('is_active', false);

        $this->postJson('/api/master-data/law_type', [
            'name' => 'External issuer',
            'attrs' => ['family_code' => 'LFM04', 'requires_issuer' => true],
        ])->assertStatus(422);

        $this->postJson('/api/master-data/law_type', [
            'name' => 'Missing family',
            'attrs' => ['family_code' => 'LFM99', 'requires_issuer' => false],
        ])->assertStatus(422);

        /** @var ReviewStore $reviewStore */
        $reviewStore = app(ReviewStore::class);
        $reviewStore->setStatus('law_lty01', ['document_id' => 'law_lty01', 'status' => 'ingested']);
        $reviewStore->patchLawMeta('law_lty01', ['law_type' => 'LTY01']);

        $this->putJson('/api/master-data/law_type/LTY01', [
            'name' => 'ประกาศ',
            'attrs' => ['family_code' => 'LFM04', 'requires_issuer' => false],
        ])->assertStatus(409);

        $this->patchJson('/api/master-data/law_family/LFM01/active', ['is_active' => false])
            ->assertStatus(409);

        $this->deleteJson('/api/master-data/law_type/LTY01')
            ->assertStatus(409);
    }
}
