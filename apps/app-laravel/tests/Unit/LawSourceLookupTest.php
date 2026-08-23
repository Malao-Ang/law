<?php

namespace Tests\Unit;

use Tests\TestCase;

class LawSourceLookupTest extends TestCase
{
    public function test_document_types_are_tagged_with_source(): void
    {
        $types = config('lookups.document_types');
        $byValue = collect($types)->keyBy('value');

        $this->assertSame('internal', $byValue['ประกาศ']['source']);
        $this->assertSame('internal', $byValue['ระเบียบ']['source']);
        $this->assertSame('internal', $byValue['ข้อบังคับ']['source']);
        $this->assertSame('external', $byValue['พระราชบัญญัติ']['source']);
        $this->assertSame('external', $byValue['พระราชกำหนด']['source']);
        $this->assertSame('external', $byValue['กฎกระทรวง']['source']);
        $this->assertSame('external', $byValue['ประกาศกระทรวง']['source']);
    }

    public function test_law_sources_lookup_has_internal_and_external(): void
    {
        $values = collect(config('lookups.law_sources'))->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['internal', 'external'], $values);
    }
}
