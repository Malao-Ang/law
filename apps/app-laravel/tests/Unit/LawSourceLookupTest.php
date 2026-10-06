<?php

namespace Tests\Unit;

use App\Services\MasterData\LawTypes;
use Tests\TestCase;

class LawSourceLookupTest extends TestCase
{
    public function test_document_types_are_tagged_with_source(): void
    {
        /** @var LawTypes $lawTypes */
        $lawTypes = app(LawTypes::class);

        $this->assertSame('internal', $lawTypes->sourceOf("\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}"));
        $this->assertSame('internal', $lawTypes->sourceOf("\u{0E23}\u{0E30}\u{0E40}\u{0E1A}\u{0E35}\u{0E22}\u{0E1A}"));
        $this->assertSame('internal', $lawTypes->sourceOf("\u{0E02}\u{0E49}\u{0E2D}\u{0E1A}\u{0E31}\u{0E07}\u{0E04}\u{0E31}\u{0E1A}"));
        $this->assertSame('external', $lawTypes->sourceOf("\u{0E1E}\u{0E23}\u{0E30}\u{0E23}\u{0E32}\u{0E0A}\u{0E1A}\u{0E31}\u{0E0D}\u{0E0D}\u{0E31}\u{0E15}\u{0E34}"));
        $this->assertSame('external', $lawTypes->sourceOf("\u{0E1E}\u{0E23}\u{0E30}\u{0E23}\u{0E32}\u{0E0A}\u{0E01}\u{0E33}\u{0E2B}\u{0E19}\u{0E14}"));
        $this->assertSame('external', $lawTypes->sourceOf("\u{0E01}\u{0E0E}\u{0E01}\u{0E23}\u{0E30}\u{0E17}\u{0E23}\u{0E27}\u{0E07}"));
        $this->assertSame('external', $lawTypes->sourceOf("\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E01}\u{0E23}\u{0E30}\u{0E17}\u{0E23}\u{0E27}\u{0E07}"));
    }

    public function test_law_sources_lookup_has_internal_and_external(): void
    {
        $values = collect(config('lookups.law_sources'))->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['internal', 'external'], $values);
    }
}
