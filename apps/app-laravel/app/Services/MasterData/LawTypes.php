<?php

namespace App\Services\MasterData;

class LawTypes
{
    /** @var list<array<string, mixed>>|null */
    private ?array $types = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $families = null;

    public function __construct(private readonly MasterDataStore $store) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(mixed $codeOrName): ?array
    {
        return $this->resolveFrom($this->types(), $codeOrName);
    }

    public function labelOf(mixed $codeOrName): string
    {
        $item = $this->resolve($codeOrName);

        return $item === null ? trim((string) $codeOrName) : (string) ($item['name'] ?? '');
    }

    public function familyOf(mixed $codeOrName): ?string
    {
        $item = $this->resolve($codeOrName);
        if ($item === null) {
            $family = $this->family($codeOrName);

            return $family === null ? null : (string) ($family['code'] ?? '');
        }

        return $item === null ? null : (string) ($item['attrs']['family_code'] ?? '');
    }

    public function sourceOf(mixed $codeOrName): string
    {
        $family = $this->family($this->familyOf($codeOrName));

        return ($family['attrs']['source'] ?? null) === 'external' ? 'external' : 'internal';
    }

    public function unitWordOf(mixed $codeOrName): string
    {
        return $this->sourceOf($codeOrName) === 'external'
            ? "\u{0E21}\u{0E32}\u{0E15}\u{0E23}\u{0E32}"
            : "\u{0E02}\u{0E49}\u{0E2D}";
    }

    /**
     * @return list<string>
     */
    public function codesOfFamily(mixed $familyCodeOrName): array
    {
        $family = $this->family($familyCodeOrName);
        $familyCode = (string) ($family['code'] ?? '');
        if ($familyCode === '') {
            return [];
        }

        return array_values(array_map(
            static fn (array $item): string => (string) $item['code'],
            array_filter($this->types(), static fn (array $item): bool => ($item['attrs']['family_code'] ?? null) === $familyCode),
        ));
    }

    public function familyColor(mixed $codeOrFamilyCode): ?string
    {
        $family = $this->family($codeOrFamilyCode);
        if ($family === null) {
            $type = $this->resolve($codeOrFamilyCode);
            $family = $this->family($type['attrs']['family_code'] ?? null);
        }

        return $family['attrs']['color'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function family(mixed $codeOrName): ?array
    {
        return $this->resolveFrom($this->families(), $codeOrName);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function types(): array
    {
        if ($this->types === null) {
            $this->types = $this->store->all(MasterDataKind::LawType);
        }

        return $this->types;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function families(): array
    {
        if ($this->families === null) {
            $this->families = $this->store->all(MasterDataKind::LawFamily);
        }

        return $this->families;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function resolveFrom(array $items, mixed $codeOrName): ?array
    {
        $needle = trim((string) $codeOrName);
        if ($needle === '') {
            return null;
        }

        $normalizedCode = mb_strtoupper($needle);
        $normalizedText = $this->normalizeText($needle);
        foreach ($items as $item) {
            if ((string) ($item['code'] ?? '') === $normalizedCode) {
                return $item;
            }
        }

        foreach ($items as $item) {
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

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
