<?php

namespace App\Services\Search;

class LawSearchQuery
{
    /** @var array<int, array{operator:string, negated:bool, value:string, quoted:bool, variants:array<int,string>}> */
    private array $terms = [];

    private bool $hasBooleanSyntax = false;
    private bool $hasNumeralVariants = false;

    private function __construct(private readonly string $raw) {}

    public static function parse(string $raw): self
    {
        $query = new self(trim($raw));
        $query->parseTerms();

        return $query;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function isEmpty(): bool
    {
        return trim($this->raw) === '';
    }

    public function requiresExpandedDsl(): bool
    {
        return $this->hasBooleanSyntax || $this->hasNumeralVariants;
    }

    public function isBoolean(): bool
    {
        return $this->hasBooleanSyntax;
    }

    public function isNegatedOnly(): bool
    {
        if (! $this->hasBooleanSyntax || $this->terms === []) {
            return false;
        }
        foreach ($this->terms as $term) {
            if (! $term['negated']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array{operator:string, negated:bool, value:string, quoted:bool, variants:array<int,string>}>
     */
    public function terms(): array
    {
        return $this->terms;
    }

    /**
     * @return array<int, array<int, array{operator:string, negated:bool, value:string, quoted:bool, variants:array<int,string>}>>
     */
    public function orGroups(): array
    {
        $groups = [[]];
        foreach ($this->terms as $term) {
            if ($term['operator'] === 'OR' && $groups[count($groups) - 1] !== []) {
                $groups[] = [];
            }
            $groups[count($groups) - 1][] = $term;
        }

        return array_values(array_filter($groups, static fn (array $group): bool => $group !== []));
    }

    /**
     * @return array<int, string>
     */
    public function rawVariants(): array
    {
        return $this->variantsFor($this->raw);
    }

    /**
     * @return array<int, string>
     */
    public function positiveVariants(): array
    {
        $variants = [];
        foreach ($this->terms as $term) {
            if ($term['negated']) {
                continue;
            }
            array_push($variants, ...$term['variants']);
        }

        return array_values(array_unique(array_filter($variants, static fn (string $value): bool => trim($value) !== '')));
    }

    public function matchesText(string $text): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        if (! $this->isBoolean()) {
            return $this->textContainsAny($text, $this->rawVariants());
        }

        foreach ($this->orGroups() as $group) {
            if ($this->groupMatchesText($group, $text)) {
                return true;
            }
        }

        return false;
    }

    public function textContainsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '๐' => '0',
            '๑' => '1',
            '๒' => '2',
            '๓' => '3',
            '๔' => '4',
            '๕' => '5',
            '๖' => '6',
            '๗' => '7',
            '๘' => '8',
            '๙' => '9',
        ]);
    }

    public static function toThaiDigits(string $value): string
    {
        return strtr($value, [
            '0' => '๐',
            '1' => '๑',
            '2' => '๒',
            '3' => '๓',
            '4' => '๔',
            '5' => '๕',
            '6' => '๖',
            '7' => '๗',
            '8' => '๘',
            '9' => '๙',
        ]);
    }

    private function parseTerms(): void
    {
        if ($this->raw === '') {
            return;
        }

        preg_match_all('/"([^"]+)"|(\S+)/u', $this->raw, $matches, PREG_SET_ORDER);
        $operator = 'AND';
        $negateNext = false;

        foreach ($matches as $match) {
            $quoted = array_key_exists(1, $match) && $match[1] !== '';
            $token = $quoted ? $match[1] : ($match[2] ?? '');
            $upper = mb_strtoupper($token);

            if (! $quoted && in_array($upper, ['AND', 'และ', '&'], true)) {
                $this->hasBooleanSyntax = true;
                $operator = 'AND';
                continue;
            }
            if (! $quoted && in_array($upper, ['OR', 'หรือ', '|'], true)) {
                $this->hasBooleanSyntax = true;
                $operator = 'OR';
                continue;
            }
            if (! $quoted && in_array($upper, ['NOT', 'ไม่', '~'], true)) {
                $this->hasBooleanSyntax = true;
                $negateNext = true;
                continue;
            }

            $negated = $negateNext;
            $negateNext = false;
            if (! $quoted && (str_starts_with($token, '-') || str_starts_with($token, '~')) && mb_strlen($token) > 1) {
                $this->hasBooleanSyntax = true;
                $negated = true;
                $token = mb_substr($token, 1);
            }

            $token = trim($token);
            if ($token === '') {
                continue;
            }

            // Handle pipe-separated OR alternatives embedded in a token (e.g. ระเบียน|ข้อบังคับ).
            // This runs after negation-prefix stripping so ~ระเบียน|ข้อบังคับ applies negation to all parts.
            if (! $quoted && str_contains($token, '|')) {
                $this->hasBooleanSyntax = true;
                $parts = array_values(array_filter(array_map('trim', explode('|', $token))));
                foreach ($parts as $i => $part) {
                    if ($part === '') {
                        continue;
                    }
                    $partVariants = $this->variantsFor($part);
                    if (count($partVariants) > 1) {
                        $this->hasNumeralVariants = true;
                    }
                    $this->terms[] = [
                        'operator' => $i === 0 ? $operator : 'OR',
                        'negated' => $negated,
                        'value' => $part,
                        'quoted' => false,
                        'variants' => $partVariants,
                    ];
                }
                $operator = 'AND';
                $negateNext = false;
                continue;
            }

            $variants = $this->variantsFor($token);
            if (count($variants) > 1) {
                $this->hasNumeralVariants = true;
            }

            $this->terms[] = [
                'operator' => $operator,
                'negated' => $negated,
                'value' => $token,
                'quoted' => $quoted,
                'variants' => $variants,
            ];
            $operator = 'AND';
        }
    }

    /**
     * @param  array<int, array{operator:string, negated:bool, value:string, quoted:bool, variants:array<int,string>}>  $group
     */
    private function groupMatchesText(array $group, string $text): bool
    {
        $hasPositive = false;
        foreach ($group as $term) {
            $contains = $this->textContainsAny($text, $term['variants']);
            if ($term['negated']) {
                if ($contains) {
                    return false;
                }
                continue;
            }

            $hasPositive = true;
            if (! $contains) {
                return false;
            }
        }

        return $hasPositive || $group !== [];
    }

    /**
     * @return array<int, string>
     */
    private function variantsFor(string $value): array
    {
        $variants = [$value];
        $arabic = self::normalizeDigits($value);
        $thai = self::toThaiDigits($arabic);

        $variants[] = $arabic;
        $variants[] = $thai;

        return array_values(array_unique(array_filter($variants, static fn (string $variant): bool => trim($variant) !== '')));
    }
}
