<?php

namespace Tests\Feature;

use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\ReviewStore;
use Tests\TestCase;

class LawTypeMasterDataSeedTest extends TestCase
{
    private const ANNOUNCEMENT = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}";
    private const ANNOUNCEMENT_UNIVERSITY = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const COMMAND = "\u{0E04}\u{0E33}\u{0E2A}\u{0E31}\u{0E48}\u{0E07}";
    private const ANNOUNCEMENT_COUNCIL = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E2A}\u{0E20}\u{0E32}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const RESOLUTION = "\u{0E21}\u{0E15}\u{0E34}";
    private const ACT = "\u{0E1E}\u{0E23}\u{0E30}\u{0E23}\u{0E32}\u{0E0A}\u{0E1A}\u{0E31}\u{0E0D}\u{0E0D}\u{0E31}\u{0E15}\u{0E34}";
    private const ACT_ABBR = "\u{0E1E}.\u{0E23}.\u{0E1A}.";
    private const ACT_ABBR_COMPACT = "\u{0E1E}\u{0E23}\u{0E1A}";
    private const EXTERNAL_FAMILY = "\u{0E01}\u{0E0E}\u{0E2B}\u{0E21}\u{0E32}\u{0E22}\u{0E20}\u{0E32}\u{0E22}\u{0E19}\u{0E2D}\u{0E01}";

    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_law_family_and_law_type_seeds_are_exact_and_idempotent(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);

        $families = $store->all(MasterDataKind::LawFamily);
        $types = $store->all(MasterDataKind::LawType);

        $this->assertCount(4, $families);
        $this->assertSame(['LFM01', 'LFM02', 'LFM03', 'LFM04'], array_column($families, 'code'));

        $this->assertCount(9, $types);
        $this->assertSame(['LTY01', 'LTY09', 'LTY02'], array_slice(array_column($types, 'code'), 0, 3));
        $this->assertSame(self::ANNOUNCEMENT_UNIVERSITY, $types[0]['name']);
        $this->assertSame([self::ANNOUNCEMENT_UNIVERSITY, self::COMMAND], $types[0]['aliases']);
        $this->assertSame(self::ANNOUNCEMENT_COUNCIL, $types[1]['name']);
        $this->assertSame([self::ANNOUNCEMENT_COUNCIL, self::RESOLUTION], $types[1]['aliases']);
        $this->assertSame([self::ACT, self::ACT_ABBR, self::ACT_ABBR_COMPACT], $types[5]['aliases']);
        $this->assertSame([self::EXTERNAL_FAMILY], $types[8]['aliases']);
        $this->assertSame(['family_code' => 'LFM03'], $types[0]['attrs']);
        $this->assertSame(['family_code' => 'LFM03'], $types[1]['attrs']);
        $this->assertFalse($types[0]['is_system']);
        foreach ($types as $type) {
            $this->assertArrayNotHasKey('requires_issuer', $type['attrs']);
        }

        $store->seedIfEmpty(MasterDataKind::LawFamily);
        $store->seedIfEmpty(MasterDataKind::LawType);

        $this->assertSame($families, $store->all(MasterDataKind::LawFamily));
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
            'attrs' => ['family_code' => 'LFM05'],
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'LTY10')
            ->assertJsonPath('attrs.family_code', 'LFM05')
            ->assertJsonPath('is_active', false);

        $this->postJson('/api/master-data/law_type', [
            'name' => 'Missing family',
            'attrs' => ['family_code' => 'LFM99'],
        ])->assertStatus(422);

        /** @var ReviewStore $reviewStore */
        $reviewStore = app(ReviewStore::class);
        $reviewStore->setStatus('law_lty01', ['document_id' => 'law_lty01', 'status' => 'ingested']);
        $reviewStore->patchLawMeta('law_lty01', ['law_type' => 'LTY01']);

        $this->putJson('/api/master-data/law_type/LTY01', [
            'name' => self::ANNOUNCEMENT_UNIVERSITY,
            'attrs' => ['family_code' => 'LFM04'],
        ])->assertStatus(409);

        $this->patchJson('/api/master-data/law_family/LFM01/active', ['is_active' => false])
            ->assertStatus(409);

        $this->deleteJson('/api/master-data/law_type/LTY01')
            ->assertStatus(409);
    }

    public function test_usage_counts_resolve_law_type_and_family_values(): void
    {
        /** @var ReviewStore $reviewStore */
        $reviewStore = app(ReviewStore::class);
        $reviewStore->setStatus('law_code', ['document_id' => 'law_code', 'status' => 'ingested']);
        $reviewStore->patchLawMeta('law_code', ['law_type' => 'LTY01']);
        $reviewStore->setStatus('law_legacy', ['document_id' => 'law_legacy', 'status' => 'ingested']);
        $reviewStore->patchLawMeta('law_legacy', ['law_type' => self::COMMAND]);
        $reviewStore->setStatus('law_bare', ['document_id' => 'law_bare', 'status' => 'ingested']);
        $reviewStore->patchLawMeta('law_bare', ['law_type' => self::ANNOUNCEMENT]);

        $this->getJson('/api/master-data/law_type/LTY01')
            ->assertOk()
            ->assertJsonPath('usage_count', 2);

        $this->getJson('/api/master-data/law_family/LFM03')
            ->assertOk()
            ->assertJsonPath('usage_count', 5);
    }

    public function test_existing_phase_two_seeded_store_is_upgraded(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);
        $store->all(MasterDataKind::LawType);

        app('mongo.blob.master')->write('data', 'law_type', [
            'items' => [
                [
                    'code' => 'LTY01',
                    'name' => self::ANNOUNCEMENT,
                    'description' => '',
                    'is_active' => true,
                    'is_system' => false,
                    'sort_order' => 1,
                    'aliases' => [self::ANNOUNCEMENT, self::ANNOUNCEMENT_UNIVERSITY, self::ANNOUNCEMENT_COUNCIL, self::COMMAND, self::RESOLUTION],
                    'attrs' => ['family_code' => 'LFM03', 'requires_issuer' => true],
                    'created_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ],
                [
                    'code' => 'LTY02',
                    'name' => 'legacy admin keeps name',
                    'description' => '',
                    'is_active' => true,
                    'is_system' => false,
                    'sort_order' => 2,
                    'aliases' => [],
                    'attrs' => ['family_code' => 'LFM02', 'requires_issuer' => false],
                    'created_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $types = $store->all(MasterDataKind::LawType);
        $byCode = array_column($types, null, 'code');

        $this->assertSame(self::ANNOUNCEMENT_UNIVERSITY, $byCode['LTY01']['name']);
        $this->assertSame([self::ANNOUNCEMENT_UNIVERSITY, self::COMMAND], $byCode['LTY01']['aliases']);
        $this->assertArrayNotHasKey('requires_issuer', $byCode['LTY01']['attrs']);
        $this->assertSame('LTY09', $types[1]['code']);
        $this->assertSame(self::ANNOUNCEMENT_COUNCIL, $byCode['LTY09']['name']);
        $this->assertSame('legacy admin keeps name', $byCode['LTY02']['name']);
        $this->assertArrayNotHasKey('requires_issuer', $byCode['LTY02']['attrs']);
    }
}
