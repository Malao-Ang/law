<?php

namespace App\Services\MasterData;

class ChangeStatuses
{
    /** @var list<array<string, mixed>>|null */
    private ?array $statuses = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $details = null;

    public function __construct(private readonly MasterDataStore $store) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(mixed $codeOrNameOrAlias): ?array
    {
        return $this->resolveFrom($this->statuses(), $codeOrNameOrAlias);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveDetail(mixed $codeOrNameOrAlias): ?array
    {
        return $this->resolveFrom($this->details(), $codeOrNameOrAlias);
    }

    public function role(mixed $value): ?string
    {
        $role = $this->resolve($value)['attrs']['role'] ?? null;

        return is_string($role) && $role !== '' ? $role : null;
    }

    public function isWhole(mixed $value): bool
    {
        return $this->role($value) === 'whole';
    }

    public function isSection(mixed $value): bool
    {
        return $this->role($value) === 'section';
    }

    public function isNew(mixed $value): bool
    {
        return $this->role($value) === 'new';
    }

    public function detailRole(mixed $value): ?string
    {
        $role = $this->resolveDetail($value)['attrs']['role'] ?? null;

        return is_string($role) && $role !== '' ? $role : null;
    }

    public function labelOf(mixed $value): string
    {
        $item = $this->resolve($value);

        return $item === null ? trim((string) $value) : (string) ($item['name'] ?? '');
    }

    public function detailLabelOf(mixed $value): string
    {
        $item = $this->resolveDetail($value);

        return $item === null ? trim((string) $value) : (string) ($item['name'] ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function resolveFrom(array $items, mixed $codeOrNameOrAlias): ?array
    {
        $needle = trim((string) $codeOrNameOrAlias);
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

    /**
     * @return list<array<string, mixed>>
     */
    private function statuses(): array
    {
        if ($this->statuses === null) {
            $this->statuses = $this->store->all(MasterDataKind::ChangeStatus);
        }

        return $this->statuses;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function details(): array
    {
        if ($this->details === null) {
            $this->details = $this->store->all(MasterDataKind::ChangeDetail);
        }

        return $this->details;
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
