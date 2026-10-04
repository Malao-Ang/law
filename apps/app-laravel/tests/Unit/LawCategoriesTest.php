<?php

namespace Tests\Unit;

use App\Services\MasterData\LawCategories;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Tests\TestCase;

class LawCategoriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_resolves_code_name_and_slug_alias_including_inactive_items(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);
        $store->setActive(MasterDataKind::LawCategory, 'DCT002', false);

        /** @var LawCategories $categories */
        $categories = app(LawCategories::class);

        $this->assertSame('DCT002', $categories->resolve('DCT002')['code']);
        $this->assertSame('DCT002', $categories->resolve('ด้านกิจการนิสิต')['code']);
        $this->assertSame('DCT002', $categories->resolve('student-affairs')['code']);
        $this->assertFalse($categories->resolve('student-affairs')['is_active']);
    }

    public function test_codes_and_labels_dedupe_and_preserve_order(): void
    {
        /** @var LawCategories $categories */
        $categories = app(LawCategories::class);

        $this->assertSame(['DCT002', 'DCT007'], $categories->codesOf([
            'ด้านกิจการนิสิต',
            'DCT002',
            'hr-discipline',
        ]));
        $this->assertSame(['ด้านกิจการนิสิต', 'ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ'], $categories->labelsOf([
            'DCT002',
            'ด้านกิจการนิสิต',
            'DCT007',
        ]));
    }
}
