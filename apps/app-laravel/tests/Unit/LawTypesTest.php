<?php

namespace Tests\Unit;

use App\Services\MasterData\LawTypes;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Tests\TestCase;

class LawTypesTest extends TestCase
{
    public function test_resolves_codes_names_aliases_and_inactive_items(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);
        /** @var LawTypes $lawTypes */
        $lawTypes = app(LawTypes::class);

        $this->assertSame('LTY01', $lawTypes->resolve('LTY01')['code']);
        $this->assertSame('LTY01', $lawTypes->resolve('ประกาศที่ออกโดยมหาวิทยาลัย')['code']);
        $this->assertSame('LTY01', $lawTypes->resolve('ประกาศที่ออกโดยสภามหาวิทยาลัย')['code']);
        $this->assertSame('LTY01', $lawTypes->resolve('คำสั่ง')['code']);
        $this->assertSame('LTY01', $lawTypes->resolve('มติ')['code']);
        $this->assertSame('LTY05', $lawTypes->resolve('พ.ร.บ.')['code']);
        $this->assertSame('LTY05', $lawTypes->resolve('พรบ')['code']);

        $store->setActive(MasterDataKind::LawType, 'LTY05', false);

        /** @var LawTypes $freshLawTypes */
        $freshLawTypes = app(LawTypes::class);
        $this->assertSame('LTY05', $freshLawTypes->resolve('พระราชบัญญัติ')['code']);
    }

    public function test_reports_family_source_unit_issuer_and_family_helpers(): void
    {
        /** @var LawTypes $lawTypes */
        $lawTypes = app(LawTypes::class);

        $this->assertSame('ประกาศ', $lawTypes->labelOf('LTY01'));
        $this->assertSame('LFM03', $lawTypes->familyOf('ประกาศที่ออกโดยมหาวิทยาลัย'));
        $this->assertSame('internal', $lawTypes->sourceOf('LTY01'));
        $this->assertSame('ข้อ', $lawTypes->unitWordOf('LTY01'));
        $this->assertTrue($lawTypes->requiresIssuer('LTY01'));

        $this->assertSame('external', $lawTypes->sourceOf('LTY05'));
        $this->assertSame('มาตรา', $lawTypes->unitWordOf('LTY05'));
        $this->assertFalse($lawTypes->requiresIssuer('LTY05'));
        $this->assertSame(['LTY04', 'LTY05', 'LTY06', 'LTY07', 'LTY08'], $lawTypes->codesOfFamily('กฎหมายภายนอก'));
        $this->assertSame('#854D0E', $lawTypes->familyColor('LTY05'));

        $this->assertSame('ISS01', $lawTypes->issuerResolve('มหาวิทยาลัย')['code']);
        $this->assertSame('สภามหาวิทยาลัย', $lawTypes->issuerLabel('ISS02'));
    }
}
