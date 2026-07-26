<?php

namespace Tests\Unit;

use App\Support\ThaiFuzzy;
use PHPUnit\Framework\TestCase;

class ThaiFuzzyTest extends TestCase
{
    public function test_mb_levenshtein_counts_character_edits(): void
    {
        $this->assertSame(0, ThaiFuzzy::distance('มหาวิทยาลัย', 'มหาวิทยาลัย'));
        $this->assertSame(1, ThaiFuzzy::distance('มหาวิทยาลับ', 'มหาวิทยาลัย'));
    }

    public function test_matches_within_typo_threshold(): void
    {
        $this->assertTrue(ThaiFuzzy::isNearMatch('มหาวิทยาลับ', 'มหาวิทยาลัย'));
        $this->assertFalse(ThaiFuzzy::isNearMatch('แมว', 'มหาวิทยาลัย'));
    }

    public function test_near_match_scans_substrings_of_longer_text(): void
    {
        $this->assertTrue(ThaiFuzzy::isNearMatch('มหาวิทยาลับ', 'ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยการเงิน'));
    }
}
