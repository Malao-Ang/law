<?php

namespace Tests\Feature;

use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\ReviewStore;
use Tests\TestCase;

class LawCategoryMasterDataSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_law_category_seeds_are_exact_and_idempotent(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);

        $categories = $store->all(MasterDataKind::LawCategory);

        $this->assertCount(12, $categories);
        $this->assertSame([
            ['DCT001', 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร'],
            ['DCT002', 'ด้านกิจการนิสิต'],
            ['DCT003', 'ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์'],
            ['DCT004', 'ด้านบริการวิชาการ'],
            ['DCT005', 'ด้านการทะนุบำรุงศิลปวัฒนธรรม'],
            ['DCT006', 'ด้านโครงสร้างองค์กรและระบบการบริหาร'],
            ['DCT007', 'ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ'],
            ['DCT008', 'ด้านการเงินและทรัพย์สิน พัสดุ การตรวจสอบ และการบริหารความเสี่ยง'],
            ['DCT009', 'ด้านการพัฒนารายได้'],
            ['DCT010', 'ด้านการรักษาพยาบาล'],
            ['DCT011', 'ด้านการบริการเฉพาะด้าน เช่น ทันตกรรม'],
            ['DCT012', 'ด้านอื่น ๆ'],
        ], array_map(static fn (array $item): array => [
            $item['code'],
            $item['name'],
        ], $categories));

        $this->assertSame(['ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร', 'academic'], $categories[0]['aliases']);
        $this->assertSame(['ด้านกิจการนิสิต', 'student-affairs'], $categories[1]['aliases']);
        $this->assertSame(['ด้านอื่น ๆ', 'other'], $categories[11]['aliases']);
        $this->assertSame([], $categories[0]['attrs']);
        $this->assertFalse($categories[0]['is_system']);
        $this->assertNotContains(true, array_column($categories, 'is_system'));

        $store->seedIfEmpty(MasterDataKind::LawCategory);

        $this->assertSame($categories, $store->all(MasterDataKind::LawCategory));
    }

    public function test_law_category_next_code_uses_three_digit_dct_prefix_and_new_items_are_inactive(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);
        $store->seedIfEmpty(MasterDataKind::LawCategory);

        $created = $store->create(MasterDataKind::LawCategory, ['name' => 'หมวดทดสอบ']);

        $this->assertSame('DCT013', $created['code']);
        $this->assertFalse($created['is_active']);
        $this->assertFalse($created['is_system']);
        $this->assertSame([], $created['attrs']);
    }

    public function test_usage_counts_resolve_codes_legacy_names_and_legacy_single_group(): void
    {
        /** @var ReviewStore $reviewStore */
        $reviewStore = app(ReviewStore::class);
        $reviewStore->setStatus('law_codes', ['document_id' => 'law_codes', 'status' => 'ingested']);
        $reviewStore->patchLawMeta('law_codes', ['law_groups' => ['DCT002', 'DCT007', 'DCT002']]);
        $reviewStore->setStatus('law_legacy', ['document_id' => 'law_legacy', 'status' => 'ingested']);
        $reviewStore->patchLawMeta('law_legacy', ['law_groups' => ['ด้านกิจการนิสิต']]);
        $reviewStore->setStatus('law_single', ['document_id' => 'law_single', 'status' => 'ingested']);
        $reviewStore->patchLawMeta('law_single', ['law_group' => 'hr-discipline']);

        $this->getJson('/api/master-data/law_category/DCT002')
            ->assertOk()
            ->assertJsonPath('usage_count', 2);

        $this->getJson('/api/master-data/law_category/DCT007')
            ->assertOk()
            ->assertJsonPath('usage_count', 2);
    }
}
