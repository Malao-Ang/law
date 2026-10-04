<?php

namespace App\Services\MasterData;

class LegalStructures
{
    /** @var list<array<string, mixed>>|null */
    private ?array $items = null;

    public function __construct(private readonly MasterDataStore $store) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(mixed $codeOrKeyOrName): ?array
    {
        $needle = trim((string) $codeOrKeyOrName);
        if ($needle === '') {
            return null;
        }

        $normalizedCode = mb_strtoupper($needle);
        $normalizedText = $this->normalizeText($needle);

        foreach ($this->items() as $item) {
            if ((string) ($item['code'] ?? '') === $normalizedCode) {
                return $item;
            }
        }

        foreach ($this->items() as $item) {
            if ($this->normalizeText((string) ($item['attrs']['export_key'] ?? '')) === $normalizedText) {
                return $item;
            }

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

    public function isHead(mixed $codeOrKeyOrName): bool
    {
        $item = $this->resolve($codeOrKeyOrName);

        return (bool) ($item['attrs']['is_head'] ?? false);
    }

    public function countsAsSection(mixed $codeOrKeyOrName): bool
    {
        $item = $this->resolve($codeOrKeyOrName);

        return (bool) ($item['attrs']['counts_as_section'] ?? false);
    }

    public function isRequired(mixed $codeOrKeyOrName): bool
    {
        $item = $this->resolve($codeOrKeyOrName);

        return (bool) ($item['attrs']['is_required'] ?? false);
    }

    public function exportKey(mixed $codeOrKeyOrName): ?string
    {
        $item = $this->resolve($codeOrKeyOrName);
        if ($item === null) {
            return null;
        }

        $exportKey = trim((string) ($item['attrs']['export_key'] ?? ''));

        return $exportKey !== '' ? $exportKey : (string) ($item['code'] ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forDocument(?string $familyCode, ?string $fileType): array
    {
        $familyCode = mb_strtoupper(trim((string) $familyCode));
        $fileType = mb_strtolower(trim((string) $fileType));
        if ($familyCode === '' || $fileType === '') {
            return [];
        }

        return array_values(array_filter($this->items(), static function (array $item) use ($familyCode, $fileType): bool {
            return (bool) ($item['is_active'] ?? false)
                && in_array($familyCode, (array) ($item['attrs']['family_codes'] ?? []), true)
                && in_array($fileType, (array) ($item['attrs']['file_types'] ?? []), true);
        }));
    }

    public function fileTypeOf(mixed $sourceType): ?string
    {
        $sourceType = mb_strtolower(trim((string) $sourceType));

        return match ($sourceType) {
            'doc', 'docx' => 'word',
            'pdf', 'pdf_text', 'pdf_scan', 'pdf_mixed' => 'pdf',
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(): array
    {
        if ($this->items === null) {
            $this->items = $this->store->all(MasterDataKind::LegalStructure);
        }

        return $this->items;
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
