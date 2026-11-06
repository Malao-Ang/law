<?php

namespace Tests\Unit;

use App\Services\MasterData\LawTypes;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Tests\TestCase;

class LawTypesTest extends TestCase
{
    private const ANNOUNCEMENT = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}";
    private const ANNOUNCEMENT_UNIVERSITY = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const COMMAND = "\u{0E04}\u{0E33}\u{0E2A}\u{0E31}\u{0E48}\u{0E07}";
    private const ANNOUNCEMENT_COUNCIL = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E2A}\u{0E20}\u{0E32}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const RESOLUTION = "\u{0E21}\u{0E15}\u{0E34}";
    private const ACT_ABBR = "\u{0E1E}.\u{0E23}.\u{0E1A}.";
    private const ACT_ABBR_COMPACT = "\u{0E1E}\u{0E23}\u{0E1A}";
    private const ACT = "\u{0E1E}\u{0E23}\u{0E30}\u{0E23}\u{0E32}\u{0E0A}\u{0E1A}\u{0E31}\u{0E0D}\u{0E0D}\u{0E31}\u{0E15}\u{0E34}";
    private const EXTERNAL_FAMILY = "\u{0E01}\u{0E0E}\u{0E2B}\u{0E21}\u{0E32}\u{0E22}\u{0E20}\u{0E32}\u{0E22}\u{0E19}\u{0E2D}\u{0E01}";
    private const UNIT_SECTION = "\u{0E02}\u{0E49}\u{0E2D}";
    private const UNIT_ARTICLE = "\u{0E21}\u{0E32}\u{0E15}\u{0E23}\u{0E32}";

    public function test_resolves_codes_names_aliases_and_inactive_items(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);
        /** @var LawTypes $lawTypes */
        $lawTypes = app(LawTypes::class);

        $this->assertSame('LTY01', $lawTypes->resolve('LTY01')['code']);
        $this->assertSame('LTY01', $lawTypes->resolve(self::ANNOUNCEMENT_UNIVERSITY)['code']);
        $this->assertSame('LTY01', $lawTypes->resolve(self::COMMAND)['code']);
        $this->assertSame('LTY09', $lawTypes->resolve(self::ANNOUNCEMENT_COUNCIL)['code']);
        $this->assertSame('LTY09', $lawTypes->resolve(self::RESOLUTION)['code']);
        $this->assertNull($lawTypes->resolve(self::ANNOUNCEMENT));
        $this->assertSame('LTY05', $lawTypes->resolve(self::ACT_ABBR)['code']);
        $this->assertSame('LTY05', $lawTypes->resolve(self::ACT_ABBR_COMPACT)['code']);

        $store->setActive(MasterDataKind::LawType, 'LTY05', false);

        /** @var LawTypes $freshLawTypes */
        $freshLawTypes = app(LawTypes::class);
        $this->assertSame('LTY05', $freshLawTypes->resolve(self::ACT)['code']);
    }

    public function test_reports_family_source_unit_and_family_helpers(): void
    {
        /** @var LawTypes $lawTypes */
        $lawTypes = app(LawTypes::class);

        $this->assertSame(self::ANNOUNCEMENT_UNIVERSITY, $lawTypes->labelOf('LTY01'));
        $this->assertSame('LFM03', $lawTypes->familyOf(self::ANNOUNCEMENT_UNIVERSITY));
        $this->assertSame('LFM03', $lawTypes->familyOf(self::ANNOUNCEMENT));
        $this->assertSame('internal', $lawTypes->sourceOf('LTY01'));
        $this->assertSame('internal', $lawTypes->sourceOf(self::ANNOUNCEMENT));
        $this->assertSame(self::UNIT_SECTION, $lawTypes->unitWordOf('LTY01'));
        $this->assertSame(self::UNIT_SECTION, $lawTypes->unitWordOf(self::ANNOUNCEMENT));

        $this->assertSame('external', $lawTypes->sourceOf('LTY05'));
        $this->assertSame(self::UNIT_ARTICLE, $lawTypes->unitWordOf('LTY05'));
        $this->assertSame(['LTY04', 'LTY05', 'LTY06', 'LTY07', 'LTY08'], $lawTypes->codesOfFamily(self::EXTERNAL_FAMILY));
        $this->assertSame('#854D0E', $lawTypes->familyColor('LTY05'));
    }
}
