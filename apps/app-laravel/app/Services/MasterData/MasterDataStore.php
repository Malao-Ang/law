<?php

namespace App\Services\MasterData;

use App\Exceptions\MasterDataConflict;
use App\Services\ReviewStore;
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
     * @return array{items: list<array<string, mixed>>, total: int, stats: array{total: int, active: int, inactive: int}, next_code: string}
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
            'next_code' => $this->nextCode($kind, $items),
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
            $code = $this->nextCode($kind, $items);
            $this->assertUniqueName($items, (string) ($payload['name'] ?? ''));

            $timestamp = now()->toIso8601String();
            $payload['is_active'] = false;
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

            $this->assertAttrsMayChange($kind, $existing, $attrs);

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
                throw new MasterDataConflict('รายการของระบบไม่สามารถปิดใช้งานได้');
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
            throw new MasterDataConflict('ไม่อนุญาตให้ลบรายการ');
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
                throw new MasterDataConflict('รายการของระบบไม่สามารถลบได้');
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
        if ($this->missingSeeds($kind, $this->readItems($kind)) === []
            && ! $this->seedUpgradesNeeded($kind, $this->readItems($kind))
            && $this->blob->read(self::BLOB_KIND, $kind->value) !== null) {
            return;
        }

        $this->blob->withLock(self::BLOB_KIND, $kind->value, function (array &$data) use ($kind): void {
            $items = $this->itemsFromData($data);
            $needsUpgrade = $this->seedUpgradesNeeded($kind, $items);
            $items = [...$items, ...$this->missingSeeds($kind, $items)];
            if ($needsUpgrade) {
                $this->applySeedUpgrades($kind, $items);
            }
            $data = ['items' => $this->sortItems($items)];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function missingSeeds(MasterDataKind $kind, array $items): array
    {
        return array_values(array_filter(
            $this->normalizeSeedItems($kind, $kind->seed()),
            fn (array $seed): bool => $this->itemIndex($items, (string) $seed['code']) === null,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function seedUpgradesNeeded(MasterDataKind $kind, array $items): bool
    {
        if ($kind !== MasterDataKind::LawType) {
            return false;
        }

        // Phase-2 stores carry attrs.requires_issuer on every law type; the upgrade removes it
        // and nothing writes it again. Checking names/sort orders here would undo admin
        // renames and reorders on the next read.
        foreach ($items as $item) {
            if (array_key_exists('requires_issuer', (array) ($item['attrs'] ?? []))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function applySeedUpgrades(MasterDataKind $kind, array &$items): void
    {
        if ($kind !== MasterDataKind::LawType) {
            return;
        }

        foreach ($items as &$item) {
            $code = (string) ($item['code'] ?? '');
            if ($code === 'LTY01') {
                $item['name'] = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
                $item['aliases'] = ["\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}", "\u{0E04}\u{0E33}\u{0E2A}\u{0E31}\u{0E48}\u{0E07}"];
                $item['sort_order'] = 1;
                $item['updated_at'] = now()->toIso8601String();
            }

            $seededSortOrders = [
                'LTY02' => 3,
                'LTY03' => 4,
                'LTY04' => 5,
                'LTY05' => 6,
                'LTY06' => 7,
                'LTY07' => 8,
                'LTY08' => 9,
            ];
            if (isset($seededSortOrders[$code]) && (int) ($item['sort_order'] ?? 0) < $seededSortOrders[$code]) {
                $item['sort_order'] = $seededSortOrders[$code];
                $item['updated_at'] = now()->toIso8601String();
            }

            if (array_key_exists('requires_issuer', (array) ($item['attrs'] ?? []))) {
                unset($item['attrs']['requires_issuer']);
                $item['updated_at'] = now()->toIso8601String();
            }
        }
        unset($item);
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
    private function assertUniqueName(array $items, string $name, ?string $ignoreCode = null): void
    {
        $needle = $this->normalizeName($name);
        foreach ($items as $item) {
            if ($ignoreCode !== null && (string) ($item['code'] ?? '') === $ignoreCode) {
                continue;
            }

            if ($this->normalizeName((string) ($item['name'] ?? '')) === $needle) {
                throw ValidationException::withMessages([
                    'name' => ['ชื่อนี้ถูกใช้งานแล้ว'],
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
            MasterDataKind::LawFamily => [
                'source' => in_array(($attrs['source'] ?? null), ['internal', 'external'], true) ? $attrs['source'] : 'internal',
                'color' => $this->normalizeHexColor($attrs['color'] ?? $existing['attrs']['color'] ?? null),
            ],
            MasterDataKind::LawType => $this->normalizeLawTypeAttrs($attrs),
            MasterDataKind::LawCategory => [],
        };
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array{family_code: string}
     */
    private function normalizeLawTypeAttrs(array $attrs): array
    {
        $familyCode = $this->normalizeCode((string) ($attrs['family_code'] ?? ''));
        $family = $this->find(MasterDataKind::LawFamily, $familyCode);
        if ($family === null) {
            throw ValidationException::withMessages([
                'attrs.family_code' => ['ไม่พบกลุ่มประเภทที่เลือก'],
            ]);
        }

        return [
            'family_code' => $familyCode,
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $nextAttrs
     */
    private function assertAttrsMayChange(MasterDataKind $kind, array $existing, array $nextAttrs): void
    {
        $code = (string) ($existing['code'] ?? '');
        if ($code === '') {
            return;
        }

        if ($kind === MasterDataKind::LawType) {
            $existingFamily = (string) ($existing['attrs']['family_code'] ?? '');
            $nextFamily = $this->normalizeCode((string) ($nextAttrs['family_code'] ?? $existingFamily));
            if ($existingFamily !== $nextFamily && $this->countLawTypeUsage($code) > 0) {
                throw new MasterDataConflict('มีเอกสารใช้งานประเภทนี้อยู่ ไม่สามารถแก้ไขกลุ่มได้');
            }
        }

        if ($kind === MasterDataKind::LawFamily) {
            $existingSource = (string) ($existing['attrs']['source'] ?? '');
            $nextSource = (string) ($nextAttrs['source'] ?? $existingSource);
            if ($existingSource !== $nextSource && $this->countLawFamilyUsage($code) > 0) {
                throw new MasterDataConflict('มีประเภทเอกสารหรือเอกสารใช้งานกลุ่มนี้อยู่ ไม่สามารถแก้ไขที่มาได้');
            }
        }
    }

    private function countLawTypeUsage(string $code): int
    {
        $count = 0;
        foreach (app(ReviewStore::class)->listLawMeta() as $row) {
            $value = trim((string) ($row['law_type'] ?? ''));
            $item = $this->resolve(MasterDataKind::LawType, $value);
            if (($item['code'] ?? null) === $code) {
                $count++;
            }
        }

        return $count;
    }

    private function countLawFamilyUsage(string $code): int
    {
        $count = 0;
        foreach ($this->all(MasterDataKind::LawType) as $type) {
            if (($type['attrs']['family_code'] ?? null) === $code) {
                $count++;
            }
        }

        foreach (app(ReviewStore::class)->listLawMeta() as $row) {
            $item = $this->resolve(MasterDataKind::LawType, (string) ($row['law_type'] ?? ''));
            if (($item['attrs']['family_code'] ?? null) === $code) {
                $count++;
            }
        }

        return $count;
    }

    private function normalizeHexColor(mixed $value): ?string
    {
        $color = trim((string) $value);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? mb_strtoupper($color) : null;
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
