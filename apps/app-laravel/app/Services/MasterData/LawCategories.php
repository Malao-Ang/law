<?php

namespace App\Services\MasterData;

class LawCategories
{
    /** @var list<array<string, mixed>>|null */
    private ?array $categories = null;

    public function __construct(private readonly MasterDataStore $store) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(mixed $codeOrName): ?array
    {
        $needle = trim((string) $codeOrName);
        if ($needle === '') {
            return null;
        }

        $normalizedCode = mb_strtoupper($needle);
        $normalizedText = $this->normalizeText($needle);
        foreach ($this->categories() as $item) {
            if ((string) ($item['code'] ?? '') === $normalizedCode) {
                return $item;
            }
        }

        foreach ($this->categories() as $item) {
            if ($this->normalizeText((string) ($item['name'] ?? '')) === $normalizedText) {
                return $item;
            }

            foreach ((array) ($item['aliases'] ?? []) as $alias) {
                if ($this->normalizeText((string) $alias) === $normalizedText) {
                    return $item;
                }
            }
        }

        return null;
    }

    public function labelOf(mixed $codeOrName): string
    {
        $item = $this->resolve($codeOrName);

        return $item === null ? trim((string) $codeOrName) : (string) ($item['name'] ?? '');
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public function codesOf(array $values): array
    {
        $codes = [];
        foreach ($values as $value) {
            $item = $this->resolve($value);
            $code = (string) ($item['code'] ?? '');
            if ($code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public function labelsOf(array $values): array
    {
        $labels = [];
        foreach ($values as $value) {
            $label = $this->labelOf($value);
            if ($label !== '' && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        if ($this->categories === null) {
            $this->categories = $this->store->all(MasterDataKind::LawCategory);
        }

        return $this->categories;
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
