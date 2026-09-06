<?php

namespace Tests\Unit;

use App\Services\Search\LawSearchQuery;
use PHPUnit\Framework\TestCase;

class LawSearchQueryTest extends TestCase
{
    public function test_ampersand_is_and_operator(): void
    {
        $query = LawSearchQuery::parse('ภาษี & ค่าเดินทาง');
        $this->assertTrue($query->isBoolean());
        $terms = $query->terms();
        $this->assertCount(2, $terms);
        $this->assertSame('AND', $terms[0]['operator']);
        $this->assertSame('ภาษี', $terms[0]['value']);
        $this->assertSame('AND', $terms[1]['operator']);
        $this->assertSame('ค่าเดินทาง', $terms[1]['value']);
    }

    public function test_pipe_is_or_operator(): void
    {
        $query = LawSearchQuery::parse('ภาษี | ค่าเดินทาง');
        $this->assertTrue($query->isBoolean());
        $groups = $query->orGroups();
        $this->assertCount(2, $groups);
        $this->assertSame('ภาษี', $groups[0][0]['value']);
        $this->assertSame('ค่าเดินทาง', $groups[1][0]['value']);
    }

    public function test_tilde_standalone_is_not_operator(): void
    {
        $query = LawSearchQuery::parse('ภาษี ~ ค่าเดินทาง');
        $this->assertTrue($query->isBoolean());
        $terms = $query->terms();
        $this->assertCount(2, $terms);
        $this->assertFalse($terms[0]['negated']);
        $this->assertTrue($terms[1]['negated']);
        $this->assertSame('ค่าเดินทาง', $terms[1]['value']);
    }

    public function test_tilde_prefix_still_works(): void
    {
        $query = LawSearchQuery::parse('~ค่าเดินทาง');
        $this->assertTrue($query->isBoolean());
        $terms = $query->terms();
        $this->assertCount(1, $terms);
        $this->assertTrue($terms[0]['negated']);
        $this->assertSame('ค่าเดินทาง', $terms[0]['value']);
    }

    public function test_matches_text_with_symbol_operators(): void
    {
        // & = AND: both must match
        $query = LawSearchQuery::parse('ภาษี & ที่ดิน');
        $this->assertTrue($query->matchesText('ภาษีที่ดินและสิ่งปลูกสร้าง'));
        $this->assertFalse($query->matchesText('ภาษีอากร'));

        // | = OR: either matches
        $query = LawSearchQuery::parse('ภาษี | ค่าเดินทาง');
        $this->assertTrue($query->matchesText('ค่าเดินทางราชการ'));

        // ~ = NOT: exclude
        $query = LawSearchQuery::parse('ภาษี ~ อากร');
        $this->assertTrue($query->matchesText('ภาษีที่ดิน'));
        $this->assertFalse($query->matchesText('ภาษีอากร'));
    }

    public function test_negated_terms_exclude_near_matches(): void
    {
        $query = LawSearchQuery::parse('~ระเบียบ');

        $this->assertTrue($query->matchesText('ประกาศมหาวิทยาลัย'));
        $this->assertFalse($query->matchesText('ระเบียบมหาวิทยาลัย'));
        $this->assertFalse($query->matchesText('ระเบียนเอกสารมหาวิทยาลัย'));
    }
}
