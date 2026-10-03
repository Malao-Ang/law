<?php

namespace App\Services\MasterData;

use App\Exceptions\MasterDataConflict;
use App\Services\Storage\MongoBlobStore;
use Illuminate\Validation\ValidationException;

class MasterDataStore
{
    private const BLOB_KIND = 'data';

    public function __construct(private readonly MongoBlobStore $blob) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(MasterDataKind $kind): array
    {
        $this->seedIfEmpty($kind);

        return $this->sortItems($this->readItems($kind));
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, stats: array{total: int, active: int, inactive: int}}
     */
    public function list(MasterDataKind $kind, ?string $q, ?bool $active, int $page, int $perPage): array
    {
        $items = $this->all($kind);
        $stats = $this->stats($items);
        $query = mb_strtolower(trim((string) $q));

        $filtered = array_values(array_filter($items, static function (array $item) use ($query, $active): bool {
            if ($active !== null && (bool) ($item['is_active'] ?? false) !== $active) {
                return false;
            }

            if ($query === '') {
                return true;
            }

            return mb_stripos((string) ($item['code'] ?? ''), $query) !== false
                || mb_stripos((string) ($item['name'] ?? ''), $query) !== false;
        }));

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($filtered, $offset, $perPage),
            'total' => count($filtered),
            'stats' => $stats,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(MasterDataKind $kind, string $code): ?array
    {
        $normalized = $this->normalizeCode($code);
        foreach ($this->all($kind) as $item) {
            if ((string) ($item['code'] ?? '') === $normalized) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(MasterDataKind $kind, array $payload): array
    {
        $this->seedIfEmpty($kind);

        $created = null;

        $this->blob->withLock(self::BLOB_KIND, $kind->value, function (array &$data) use ($kind, $payload, &$created): void {
            $items = $this->sortItems($this->itemsFromData($data));
            $code = $this->normalizeCode((string) ($payload['code'] ?? ''));
            if ($code === '') {
                $code = $this->nextCode($kind, $items);
            }

            $this->assertUniqueCode($items, $code);
            $this->assertUniqueName($items, (string) ($payload['name'] ?? ''));

            $timestamp = now()->toIso8601String();
            $created = $this->normalizeItem($kind, $payload, $items, [
                'code' => $code,
                'is_system' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $items[] = $created;
            $data = ['items' => $this->sortItems($items)];
        });

        if ($created === null) {
            throw new \RuntimeException('MasterDataStore: create failed to persist item.');
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function update(MasterDataKind $kind, string $code, array $payload): ?array
    {
        $this->seedIfEmpty($kind);

        $updated = null;
        $normalizedCode = $this->normalizeCode($code);

        $this->blob->withLock(self::BLOB_KIND, $kind->value, function (array &$data) use ($kind, $normalizedCode, $payload, &$updated): void {
            $items = $this->itemsFromData($data);
            $index = $this->itemIndex($items, $normalizedCode);
            if ($index === null) {
                return;
            }

            $this->assertUniqueName($items, (string) ($payload['name'] ?? $items[$index]['name'] ?? ''), $normalizedCode);

            $existing = $items[$index];
            $attrs = array_key_exists('attrs', $payload) && is_array($payload['attrs'])
                ? $payload['attrs']
                : (array) ($existing['attrs'] ?? []);

            if (($existing['is_system'] ?? false) === true && isset($existing['attrs']['role'])) {
                $attrs['role'] = $existing['attrs']['role'];
            }

            $updated = $this->normalizeItem($kind, array_merge($payload, ['attrs' => $attrs]), $items, [
                'code' => $normalizedCode,
                'is_system' => (bool) ($existing['is_system'] ?? false),
                'created_at' => (string) ($existing['created_at'] ?? now()->toIso8601String()),
                'updated_at' => now()->toIso8601String(),
            ], $existing);

            $items[$index] = $updated;
            $data = ['items' => $this->sortItems($items)];
        });

        return $updated;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setActive(MasterDataKind $kind, string $code, bool $active): ?array
    {
        $this->seedIfEmpty($kind);

        $updated = null;
        $normalizedCode = $this->normalizeCode($code);

        $this->blob->withLock(self::BLOB_KIND, $kind->value, function (array &$data) use ($normalizedCode, $active, &$updated): void {
            $items = $this->itemsFromData($data);
            $index = $this->itemIndex($items, $normalizedCode);
            if ($index === null) {
                return;
            }

            if (($items[$index]['is_system'] ?? false) === true && ! $active) {
                throw new MasterDataConflict('system item cannot be deactivated');
            }

            $items[$index]['is_active'] = $active;
            $items[$index]['updated_at'] = now()->toIso8601String();
            $updated = $items[$index];
            $data = ['items' => $this->sortItems($items)];
        });

        return $updated;
    }

    public function delete(MasterDataKind $kind, string $code): bool
    {
        $this->seedIfEmpty($kind);

        if (! $kind->deletable()) {
            throw new MasterDataConflict('delete not allowed');
        }

        $deleted = false;
        $normalizedCode = $this->normalizeCode($code);

        $this->blob->withLock(self::BLOB_KIND, $kind->value, function (array &$data) use ($normalizedCode, &$deleted): void {
            $items = $this->itemsFromData($data);
            $index = $this->itemIndex($items, $normalizedCode);
            if ($index === null) {
                return;
            }

            if (($items[$index]['is_system'] ?? false) === true) {
                throw new MasterDataConflict('system item cannot be deleted');
            }

            array_splice($items, $index, 1);
            $deleted = true;
            $data = ['items' => $this->sortItems($items)];
        });

        return $deleted;
    }

    /**
     * @param  list<string>  $codes
     */
    public function reorder(MasterDataKind $kind, array $codes): void
    {
        $this->seedIfEmpty($kind);

        $orderedCodes = array_values(array_unique(array_map(fn (string $code): string => $this->normalizeCode($code), $codes)));

        $this->blob->withLock(self::BLOB_KIND, $kind->value, function (array &$data) use ($orderedCodes): void {
            $items = $this->itemsFromData($data);
            $byCode = [];
            foreach ($items as $item) {
                $byCode[(string) ($item['code'] ?? '')] = $item;
            }

            $next = [];
            foreach ($orderedCodes as $code) {
                if (isset($byCode[$code])) {
                    $next[] = $byCode[$code];
                    unset($byCode[$code]);
                }
            }

            $next = array_merge($next, $this->sortItems(array_values($byCode)));
            foreach ($next as $index => &$item) {
                $item['sort_order'] = $index + 1;
                $item['updated_at'] = now()->toIso8601String();
            }
            unset($item);

            $data = ['items' => $next];
        });
    }

    public function seedIfEmpty(MasterDataKind $kind): void
    {
        $this->blob->withLock(self::BLOB_KIND, $kind->value, function (array &$data) use ($kind): void {
            if (isset($data['items']) && is_array($data['items']) && $data['items'] !== []) {
                return;
            }

            $data = ['items' => $this->normalizeSeedItems($kind, $kind->seed())];
        });
    }

    public function reseed(MasterDataKind $kind, bool $force = false): int
    {
        $count = 0;

        $this->blob->withLock(self::BLOB_KIND, $kind->value, function (array &$data) use ($kind, $force, &$count): void {
            $items = $this->itemsFromData($data);
            $seeds = $this->normalizeSeedItems($kind, $kind->seed());

            foreach ($seeds as $seed) {
                $index = $this->itemIndex($items, (string) $seed['code']);
                if ($index === null) {
                    $items[] = $seed;
                    $count++;

                    continue;
                }

                if ($force && ($items[$index]['is_system'] ?? false) === true) {
                    $items[$index]['name'] = $seed['name'];
                    $items[$index]['description'] = $seed['description'];
                    $items[$index]['aliases'] = $seed['aliases'];
                    $items[$index]['attrs'] = $seed['attrs'];
                    $items[$index]['updated_at'] = now()->toIso8601String();
                    $count++;
                }
            }

            $data = ['items' => $this->sortItems($items)];
        });

        return $count;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(MasterDataKind $kind, string $codeOrAlias): ?array
    {
        $needle = trim($codeOrAlias);
        $normalizedCode = $this->normalizeCode($needle);
        $normalizedText = $this->normalizeName($needle);

        foreach ($this->all($kind) as $item) {
            if ((string) ($item['code'] ?? '') === $normalizedCode) {
                return $item;
            }
        }

        foreach ($this->all($kind) as $item) {
            foreach ((array) ($item['aliases'] ?? []) as $alias) {
                if ($this->normalizeName((string) $alias) === $normalizedText) {
                    return $item;
                }
            }

            if ($this->normalizeName((string) ($item['name'] ?? '')) === $normalizedText) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readItems(MasterDataKind $kind): array
    {
        return $this->itemsFromData($this->blob->read(self::BLOB_KIND, $kind->value) ?? []);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function itemsFromData(array $data): array
    {
        return array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortItems(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $order = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));

            return $order !== 0 ? $order : strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        });

        return array_values($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{total: int, active: int, inactive: int}
     */
    private function stats(array $items): array
    {
        $active = count(array_filter($items, static fn (array $item): bool => (bool) ($item['is_active'] ?? false)));

        return [
            'total' => count($items),
            'active' => $active,
            'inactive' => count($items) - $active,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function nextCode(MasterDataKind $kind, array $items): string
    {
        $max = 0;
        foreach ($items as $item) {
            $code = (string) ($item['code'] ?? '');
            if (preg_match('/^'.preg_quote($kind->prefix(), '/').'(\d+)$/', $code, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $kind->prefix().str_pad((string) ($max + 1), $kind->codePad(), '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function assertUniqueCode(array $items, string $code): void
    {
        foreach ($items as $item) {
            if ((string) ($item['code'] ?? '') === $code) {
                throw ValidationException::withMessages([
                    'code' => ['This code is already in use.'],
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function assertUniqueName(array $items, string $name, ?string $ignoreCode = null): void
    {
        $needle = $this->normalizeName($name);
        foreach ($items as $item) {
            if ($ignoreCode !== null && (string) ($item['code'] ?? '') === $ignoreCode) {
                continue;
            }

            if ($this->normalizeName((string) ($item['name'] ?? '')) === $needle) {
                throw ValidationException::withMessages([
                    'name' => ['This name is already in use.'],
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function normalizeItem(MasterDataKind $kind, array $payload, array $items, array $defaults, array $existing = []): array
    {
        return [
            'code' => $defaults['code'],
            'name' => trim((string) ($payload['name'] ?? $existing['name'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? $existing['description'] ?? '')),
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : (bool) ($existing['is_active'] ?? true),
            'is_system' => (bool) $defaults['is_system'],
            'sort_order' => array_key_exists('sort_order', $payload) && $payload['sort_order'] !== null
                ? (int) $payload['sort_order']
                : (int) ($existing['sort_order'] ?? $this->nextSortOrder($items)),
            'aliases' => $this->normalizeStringList($payload['aliases'] ?? $existing['aliases'] ?? []),
            'attrs' => $this->normalizeAttrs($kind, $payload, $existing, (bool) $defaults['is_system']),
            'created_at' => (string) $defaults['created_at'],
            'updated_at' => (string) $defaults['updated_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function normalizeAttrs(MasterDataKind $kind, array $payload, array $existing, bool $isSystem): array
    {
        $attrs = is_array($payload['attrs'] ?? null) ? $payload['attrs'] : (array) ($existing['attrs'] ?? []);

        return match ($kind) {
            MasterDataKind::EnforcementStatus => [
                'role' => $isSystem ? ($attrs['role'] ?? $existing['attrs']['role'] ?? null) : null,
                'color' => $attrs['color'] ?? $existing['attrs']['color'] ?? null,
            ],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function nextSortOrder(array $items): int
    {
        $max = 0;
        foreach ($items as $item) {
            $max = max($max, (int) ($item['sort_order'] ?? 0));
        }

        return $max + 1;
    }

    /**
     * @param  list<array<string, mixed>>  $seeds
     * @return list<array<string, mixed>>
     */
    private function normalizeSeedItems(MasterDataKind $kind, array $seeds): array
    {
        $items = [];
        foreach ($seeds as $seed) {
            $timestamp = now()->toIso8601String();
            $items[] = $this->normalizeItem($kind, $seed, $items, [
                'code' => $this->normalizeCode((string) ($seed['code'] ?? '')),
                'is_system' => (bool) ($seed['is_system'] ?? true),
                'created_at' => (string) ($seed['created_at'] ?? $timestamp),
                'updated_at' => (string) ($seed['updated_at'] ?? $timestamp),
            ]);
        }

        return $this->sortItems($items);
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        )));
    }

    private function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function itemIndex(array $items, string $code): ?int
    {
        foreach ($items as $index => $item) {
            if ((string) ($item['code'] ?? '') === $code) {
                return $index;
            }
        }

        return null;
    }
}
