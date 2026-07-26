<?php

namespace App\Support;

final class ThaiFuzzy
{
    public static function distance(string $a, string $b): int
    {
        $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($x);
        $m = count($y);
        if ($n === 0) {
            return $m;
        }
        if ($m === 0) {
            return $n;
        }

        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $curr = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $curr[$j] = min($prev[$j] + 1, $curr[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $curr;
        }

        return $prev[$m];
    }

    public static function isNearMatch(string $query, string $text): bool
    {
        $q = trim($query);
        if (mb_strlen($q) < 3) {
            return false;
        }
        $threshold = max(1, (int) ceil(mb_strlen($q) / 4));

        if (self::distance($q, $text) <= $threshold) {
            return true;
        }

        $qLen = mb_strlen($q);
        $tChars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tLen = count($tChars);
        for ($start = 0; $start + $qLen - $threshold <= $tLen; $start++) {
            $window = implode('', array_slice($tChars, $start, $qLen + $threshold));
            if ($window !== '' && self::distance($q, $window) <= $threshold) {
                return true;
            }
        }

        return false;
    }
}
