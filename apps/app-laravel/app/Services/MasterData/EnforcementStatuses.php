<?php

namespace App\Services\MasterData;

use RuntimeException;

class EnforcementStatuses
{
    /** @var list<array<string, mixed>>|null */
    private ?array $items = null;

    public function __construct(private readonly MasterDataStore $store) {}

    public function codeForRole(string $role): string
    {
        foreach ($this->items() as $item) {
            if (($item['attrs']['role'] ?? null) === $role) {
                return (string) $item['code'];
            }
        }

        throw new RuntimeException("Missing enforcement status for role [{$role}].");
    }

    public function roleOf(mixed $value): ?string
    {
        $item = $this->resolve($value);

        return $item === null ? null : ($item['attrs']['role'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(mixed $codeOrAliasOrName): ?array
    {
        $needle = trim((string) $codeOrAliasOrName);
        $normalizedCode = mb_strtoupper($needle);
        $normalizedText = $this->normalizeText($needle);

        foreach ($this->items() as $item) {
            if ((string) ($item['code'] ?? '') === $normalizedCode) {
                return $item;
            }
        }

        foreach ($this->items() as $item) {
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

    public function isDraft(mixed $value): bool
    {
        return $this->roleOf($value) === 'draft';
    }

    public function isInForce(mixed $value): bool
    {
        return $this->roleOf($value) === 'in_force';
    }

    public function isRepealed(mixed $value): bool
    {
        return $this->roleOf($value) === 'repealed';
    }

    public function labelOf(mixed $value): string
    {
        $item = $this->resolve($value);

        return $item === null ? trim((string) $value) : (string) ($item['name'] ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(): array
    {
        if ($this->items === null) {
            $this->items = $this->store->all(MasterDataKind::EnforcementStatus);
        }

        return $this->items;
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
