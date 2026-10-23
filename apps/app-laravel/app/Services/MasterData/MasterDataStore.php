<?php

namespace App\Services\MasterData;

use App\Exceptions\MasterDataConflict;
use App\Services\ReviewStore;
use App\Services\Storage\MongoBlobStore;
use Illuminate\Validation\ValidationException;

class MasterDataStore
{
    private const BLOB_KIND = 'data';

    private bool $initializingLawCatalog = false;

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

        $this->withMasterLock($kind, function (array &$data) use ($kind, $payload, &$created): void {
            $items = $this->sortItems($this->itemsFromData($data));
            $code = $this->nextCode($kind, $items);
            $this->assertUniqueName($items, (string) ($payload['name'] ?? ''));

            $timestamp = now()->toIso8601String();
            $payload['is_active'] = false;
            if ($kind === MasterDataKind::LawFamily) {
                $payload['attrs']['has_subtypes'] ??= true;
            }
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

        return $this->isLawKind($kind) ? ($this->find($kind, $created['code']) ?? $created) : $created;
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

        $this->withMasterLock($kind, function (array &$data) use ($kind, $normalizedCode, $payload, &$updated): void {
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

            if (($existing['is_system'] ?? false) === true) {
                if (in_array($kind, [MasterDataKind::ChangeStatus, MasterDataKind::ChangeDetail], true)) {
                    $attrs = (array) ($existing['attrs'] ?? []);
                } elseif (isset($existing['attrs']['role'])) {
                    $attrs['role'] = $existing['attrs']['role'];
                }
            }

            if ($kind === MasterDataKind::LawFamily
                && isset($attrs['has_subtypes'])
                && (bool) $attrs['has_subtypes'] !== (bool) $existing['attrs']['has_subtypes']) {
                throw new MasterDataConflict('กรุณาเปลี่ยนการมีประเภทย่อยผ่านการตั้งค่าประเภทย่อย');
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

        $this->withMasterLock($kind, function (array &$data) use ($normalizedCode, $active, &$updated): void {
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

        $this->withMasterLock($kind, function (array &$data) use ($normalizedCode, &$deleted): void {
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

        $this->withMasterLock($kind, function (array &$data) use ($orderedCodes): void {
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
        if ($this->isLawKind($kind) && ! $this->initializingLawCatalog) {
            $this->initializingLawCatalog = true;
            try {
                $this->seedIfEmpty(MasterDataKind::LawFamily);
                $this->seedIfEmpty(MasterDataKind::LawType);
                $families = $this->readItems(MasterDataKind::LawFamily);
                $types = $this->readItems(MasterDataKind::LawType);
                $before = [$families, $types];
                $this->normalizeLawCatalog($families, $types);
                if ($before !== [$families, $types]) {
                    $this->blob->withLock(self::BLOB_KIND, MasterDataKind::LawFamily->value, function (array &$data): void {
                        $families = $this->itemsFromData($data);
                        $types = array_values((array) ($data['law_types'] ?? []));
                        $this->normalizeLawCatalog($families, $types);
                        $data = ['items' => $families, 'law_types' => $types];
                    });
                }
            } finally {
                $this->initializingLawCatalog = false;
            }
            return;
        }

        if ($this->missingSeeds($kind, $this->readItems($kind)) === []
            && ! $this->seedUpgradesNeeded($kind, $this->readItems($kind))
            && ($kind === MasterDataKind::LawType
                ? array_key_exists('law_types', $this->blob->read(self::BLOB_KIND, MasterDataKind::LawFamily->value) ?? [])
                : $this->blob->read(self::BLOB_KIND, $kind->value) !== null)) {
            return;
        }

        $this->withMasterLock($kind, function (array &$data) use ($kind): void {
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
     * @return list<string>
     */
    private function seedCodes(MasterDataKind $kind): array
    {
        return array_map(
            fn (array $seed): string => $this->normalizeCode((string) ($seed['code'] ?? '')),
            $kind->seed(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function seedUpgradesNeeded(MasterDataKind $kind, array $items): bool
    {
        if ($kind === MasterDataKind::LegalStructure) {
            $seedCodes = $this->seedCodes($kind);
            foreach ($items as $item) {
                if (in_array((string) ($item['code'] ?? ''), $seedCodes, true) && ($item['is_system'] ?? false) === true) {
                    return true;
                }
            }

            return false;
        }

        if (! in_array($kind, [MasterDataKind::LawFamily, MasterDataKind::LawType], true)) {
            return false;
        }

        $seedAliases = [];
        foreach ($this->normalizeSeedItems($kind, $kind->seed()) as $seed) {
            $seedAliases[(string) ($seed['code'] ?? '')] = (array) ($seed['aliases'] ?? []);
        }

        foreach ($items as $item) {
            $code = (string) ($item['code'] ?? '');
            $aliases = (array) ($item['aliases'] ?? []);
            foreach ($seedAliases[$code] ?? [] as $alias) {
                if (! in_array($alias, $aliases, true)) {
                    return true;
                }
            }

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
        if ($kind === MasterDataKind::LegalStructure) {
            $seedCodes = $this->seedCodes($kind);
            foreach ($items as &$item) {
                if (in_array((string) ($item['code'] ?? ''), $seedCodes, true) && ($item['is_system'] ?? false) === true) {
                    $item['is_system'] = false;
                    $item['updated_at'] = now()->toIso8601String();
                }
            }
            unset($item);

            return;
        }

        if (! in_array($kind, [MasterDataKind::LawFamily, MasterDataKind::LawType], true)) {
            return;
        }

        $seedAliases = [];
        foreach ($this->normalizeSeedItems($kind, $kind->seed()) as $seed) {
            $seedAliases[(string) ($seed['code'] ?? '')] = (array) ($seed['aliases'] ?? []);
        }

        foreach ($items as &$item) {
            $code = (string) ($item['code'] ?? '');
            // Phase-2 stores (still carrying attrs.requires_issuer) had LTY01 = plain ประกาศ.
            if ($kind === MasterDataKind::LawType && $code === 'LTY01'
                && array_key_exists('requires_issuer', (array) ($item['attrs'] ?? []))) {
                $item['name'] = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
                $item['aliases'] = ["\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}", "\u{0E04}\u{0E33}\u{0E2A}\u{0E31}\u{0E48}\u{0E07}"];
                $item['sort_order'] = 1;
                $item['updated_at'] = now()->toIso8601String();
            }

            $aliases = (array) ($item['aliases'] ?? []);
            foreach ($seedAliases[$code] ?? [] as $alias) {
                if (! in_array($alias, $aliases, true)) {
                    $aliases[] = $alias;
                    $item['updated_at'] = now()->toIso8601String();
                }
            }
            $item['aliases'] = array_values($aliases);

            if ($kind !== MasterDataKind::LawType) {
                continue;
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

        $this->withMasterLock($kind, function (array &$data) use ($kind, $force, &$count): void {
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
        if ($kind === MasterDataKind::LawType) {
            $catalog = $this->blob->read(self::BLOB_KIND, MasterDataKind::LawFamily->value) ?? [];
            if (array_key_exists('law_types', $catalog)) {
                return array_values((array) $catalog['law_types']);
            }
        }

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
            'aliases' => $this->normalizeAliases($kind, $payload, $existing, (string) $defaults['code'], $items),
            'attrs' => $this->normalizeAttrs($kind, $payload, $existing, (bool) $defaults['is_system'], $items, (string) $defaults['code']),
            'created_at' => (string) $defaults['created_at'],
            'updated_at' => (string) $defaults['updated_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function normalizeAttrs(MasterDataKind $kind, array $payload, array $existing, bool $isSystem, array $items = [], string $code = ''): array
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
                'has_subtypes' => (bool) ($attrs['has_subtypes'] ?? $existing['attrs']['has_subtypes'] ?? ! in_array($code, ['LFM01', 'LFM02'], true)),
            ],
            MasterDataKind::LawType => $this->normalizeLawTypeAttrs(array_merge($attrs, ['is_primary' => $existing['attrs']['is_primary'] ?? false])),
            MasterDataKind::LawCategory => [],
            MasterDataKind::ChangeStatus => $this->normalizeChangeStatusAttrs($attrs, $existing, $isSystem),
            MasterDataKind::ChangeDetail => $this->normalizeChangeDetailAttrs($attrs, $existing, $isSystem),
            MasterDataKind::LegalStructure => $this->normalizeLegalStructureAttrs($attrs, $existing, $items, $code, $isSystem),
        };
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $existing
     * @return array{source: string, has_details: bool, role: string}
     */
    private function normalizeChangeStatusAttrs(array $attrs, array $existing, bool $isSystem): array
    {
        if (! $isSystem) {
            return [
                'source' => 'both',
                'has_details' => false,
                'role' => 'general',
            ];
        }

        $attrs = $attrs !== [] ? $attrs : (array) ($existing['attrs'] ?? []);

        return [
            'source' => in_array(($attrs['source'] ?? null), ['internal', 'external', 'both'], true) ? $attrs['source'] : 'both',
            'has_details' => (bool) ($attrs['has_details'] ?? false),
            'role' => in_array(($attrs['role'] ?? null), ['new', 'whole', 'section', 'general'], true) ? $attrs['role'] : 'general',
        ];
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $existing
     * @return array{source: string, has_details: bool, role: string, color: ?string, icon: ?string}
     */
    private function normalizeChangeDetailAttrs(array $attrs, array $existing, bool $isSystem): array
    {
        if (! $isSystem) {
            return [
                'source' => 'both',
                'has_details' => false,
                'role' => 'general',
                'color' => null,
                'icon' => null,
            ];
        }

        $attrs = $attrs !== [] ? $attrs : (array) ($existing['attrs'] ?? []);

        return [
            'source' => in_array(($attrs['source'] ?? null), ['internal', 'external', 'both'], true) ? $attrs['source'] : 'both',
            'has_details' => (bool) ($attrs['has_details'] ?? false),
            'role' => in_array(($attrs['role'] ?? null), ['repeals', 'amends', 'general'], true) ? $attrs['role'] : 'general',
            'color' => isset($attrs['color']) ? trim((string) $attrs['color']) : null,
            'icon' => isset($attrs['icon']) ? trim((string) $attrs['icon']) : null,
        ];
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
            'is_primary' => (bool) ($attrs['is_primary'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $existing
     * @param  list<array<string, mixed>>  $items
     * @return array{family_codes: list<string>, file_types: list<string>, is_head: bool, counts_as_section: bool, is_required: bool, color: string, export_key: string}
     */
    private function normalizeLegalStructureAttrs(array $attrs, array $existing, array $items, string $code, bool $isSystem): array
    {
        $familyCodes = [];
        foreach ((array) ($attrs['family_codes'] ?? []) as $familyCode) {
            $familyCode = $this->normalizeCode((string) $familyCode);
            if ($familyCode !== '' && ! in_array($familyCode, $familyCodes, true)) {
                $familyCodes[] = $familyCode;
            }
        }
        if ($familyCodes === []) {
            throw ValidationException::withMessages([
                'attrs.family_codes' => ['เลือกประเภทเอกสารที่รองรับอย่างน้อย 1 กลุ่ม'],
            ]);
        }

        foreach ($familyCodes as $familyCode) {
            if ($this->find(MasterDataKind::LawFamily, $familyCode) === null) {
                throw ValidationException::withMessages([
                    'attrs.family_codes' => ['ไม่พบกลุ่มประเภทเอกสารที่เลือก'],
                ]);
            }
        }

        $fileTypes = [];
        foreach ((array) ($attrs['file_types'] ?? []) as $fileType) {
            $fileType = mb_strtolower(trim((string) $fileType));
            if (in_array($fileType, ['word', 'pdf'], true) && ! in_array($fileType, $fileTypes, true)) {
                $fileTypes[] = $fileType;
            } elseif ($fileType !== '') {
                throw ValidationException::withMessages([
                    'attrs.file_types' => ['เลือกชนิดไฟล์ที่รองรับอย่างน้อย 1 ชนิด'],
                ]);
            }
        }
        if ($fileTypes === []) {
            throw ValidationException::withMessages([
                'attrs.file_types' => ['เลือกชนิดไฟล์ที่รองรับอย่างน้อย 1 ชนิด'],
            ]);
        }

        $existingExportKey = trim((string) ($existing['attrs']['export_key'] ?? ''));
        $exportKey = $this->normalizeCode((string) ($attrs['export_key'] ?? $existingExportKey ?: $code));
        $lockedExportKey = $isSystem || in_array($code, $this->seedCodes(MasterDataKind::LegalStructure), true);
        if ($lockedExportKey && $existingExportKey !== '' && $exportKey !== $existingExportKey) {
            throw ValidationException::withMessages([
                'attrs.export_key' => ['รหัสส่งออกของรายการระบบไม่สามารถแก้ไขได้'],
            ]);
        }
        $this->assertUniqueLegalStructureExportKey($items, $exportKey, $code);

        return [
            'family_codes' => $familyCodes,
            'file_types' => $fileTypes,
            'is_head' => array_key_exists('is_head', $attrs) ? (bool) $attrs['is_head'] : (bool) ($existing['attrs']['is_head'] ?? true),
            'counts_as_section' => array_key_exists('counts_as_section', $attrs) ? (bool) $attrs['counts_as_section'] : (bool) ($existing['attrs']['counts_as_section'] ?? false),
            'is_required' => array_key_exists('is_required', $attrs) ? (bool) $attrs['is_required'] : (bool) ($existing['attrs']['is_required'] ?? false),
            'color' => trim((string) ($attrs['color'] ?? $existing['attrs']['color'] ?? 'blue-grey')) ?: 'blue-grey',
            'export_key' => $exportKey,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function assertUniqueLegalStructureExportKey(array $items, string $exportKey, string $ignoreCode): void
    {
        foreach ($items as $item) {
            if ((string) ($item['code'] ?? '') === $ignoreCode) {
                continue;
            }

            if ($this->normalizeCode((string) ($item['attrs']['export_key'] ?? '')) === $exportKey) {
                throw ValidationException::withMessages([
                    'attrs.export_key' => ['รหัสส่งออกซ้ำกับรายการอื่น'],
                ]);
            }
        }
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function normalizeAliases(MasterDataKind $kind, array $payload, array $existing, string $code, array $items): array
    {
        $aliases = $this->normalizeStringList($payload['aliases'] ?? $existing['aliases'] ?? []);
        if ($kind !== MasterDataKind::LegalStructure) {
            return $aliases;
        }

        $attrs = $this->normalizeAttrs(
            $kind,
            $payload,
            $existing,
            (bool) ($existing['is_system'] ?? $payload['is_system'] ?? false),
            $items,
            $code,
        );
        $exportKey = (string) ($attrs['export_key'] ?? '');
        if ($exportKey !== '' && ! in_array($exportKey, $aliases, true)) {
            array_unshift($aliases, $exportKey);
        }

        return array_values(array_unique($aliases));
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

    private function isLawKind(MasterDataKind $kind): bool
    {
        return in_array($kind, [MasterDataKind::LawFamily, MasterDataKind::LawType], true);
    }

    /** Families and types share one CAS write, including imports from the legacy type blob. */
    private function withMasterLock(MasterDataKind $kind, callable $callback): void
    {
        if (! $this->isLawKind($kind)) {
            $this->blob->withLock(self::BLOB_KIND, $kind->value, $callback);
            return;
        }

        $this->blob->withLock(self::BLOB_KIND, MasterDataKind::LawFamily->value, function (array &$catalog) use ($kind, $callback): void {
            $families = $this->itemsFromData($catalog);
            $types = array_key_exists('law_types', $catalog)
                ? array_values((array) $catalog['law_types'])
                : $this->itemsFromData($this->blob->read(self::BLOB_KIND, MasterDataKind::LawType->value) ?? []);
            $data = ['items' => $kind === MasterDataKind::LawFamily ? $families : $types];
            $callback($data);
            if ($kind === MasterDataKind::LawType && ! $this->initializingLawCatalog) {
                foreach ($data['items'] as $type) {
                    $family = $families[$this->itemIndex($families, $type['attrs']['family_code'])] ?? null;
                    if ($family === null) {
                        continue;
                    }
                    $oldIndex = $this->itemIndex($types, $type['code']);
                    $old = $oldIndex === null ? null : $types[$oldIndex];
                    if (! $family['attrs']['has_subtypes'] && ($old === null
                        || $old['name'] !== $type['name']
                        || $old['attrs']['family_code'] !== $type['attrs']['family_code'])) {
                        throw new MasterDataConflict('กรุณาแก้ไขประเภทหลักผ่านกลุ่ม หรือเปิดการมีประเภทย่อยก่อน');
                    }
                    if ($old !== null && $old['attrs']['family_code'] !== $type['attrs']['family_code']) {
                        $oldFamily = $families[$this->itemIndex($families, $old['attrs']['family_code'])] ?? null;
                        if (($oldFamily['attrs']['has_subtypes'] ?? true) === false) {
                            throw new MasterDataConflict('ไม่สามารถย้ายประเภทหลักออกจากกลุ่มที่ไม่มีประเภทย่อยได้');
                        }
                    }
                    if ($family['attrs']['has_subtypes'] && $this->normalizeName($type['name']) === $this->normalizeName($family['name'])
                        && ($old === null || $old['name'] !== $type['name'] || $old['attrs']['family_code'] !== $type['attrs']['family_code'])) {
                        throw ValidationException::withMessages(['name' => ['ชื่อประเภทต้องแตกต่างจากชื่อกลุ่ม']]);
                    }
                }
            }
            if ($kind === MasterDataKind::LawFamily) {
                $families = $data['items'];
            } else {
                $types = $data['items'];
            }
            if (! $this->initializingLawCatalog) {
                $this->normalizeLawCatalog($families, $types, $kind === MasterDataKind::LawFamily);
            }
            $catalog = ['items' => $this->sortItems($families), 'law_types' => $this->sortItems($types)];
        });
    }

    private function normalizeLawCatalog(array &$families, array &$types, bool $syncStandalone = false): void
    {
        foreach ($families as &$family) {
            $children = $this->sortItems(array_values(array_filter($types,
                static fn (array $type): bool => ($type['attrs']['family_code'] ?? '') === $family['code'])));
            $needsNormalization = ! array_key_exists('has_subtypes', $family['attrs']);
            $family['attrs']['has_subtypes'] ??= ! (count($children) === 1
                && $this->normalizeName($children[0]['name']) === $this->normalizeName($family['name']));
            if ($children === [] && ! $family['attrs']['has_subtypes']) {
                $this->assertUniqueName($types, $family['name']);
                $timestamp = now()->toIso8601String();
                $types[] = [
                    'code' => $this->nextCode(MasterDataKind::LawType, $types),
                    'name' => $family['name'], 'description' => '', 'aliases' => [],
                    'is_active' => $family['is_active'], 'is_system' => false,
                    'sort_order' => $this->nextSortOrder($types),
                    'attrs' => ['family_code' => $family['code'], 'is_primary' => true],
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ];
                $children = [end($types)];
            }
            $primary = null;
            foreach ($children as $child) {
                if ($child['attrs']['is_primary'] ?? false) {
                    $primary = $child['code'];
                    break;
                }
            }
            $primary ??= $children[0]['code'] ?? null;
            foreach ($types as &$type) {
                if (($type['attrs']['family_code'] ?? '') !== $family['code']) {
                    continue;
                }
                $type['attrs']['is_primary'] = $type['code'] === $primary;
                if (! $family['attrs']['has_subtypes'] && $type['attrs']['is_primary'] && ($syncStandalone || $needsNormalization)) {
                    $this->assertUniqueName($types, $family['name'], $type['code']);
                    $type['name'] = $family['name'];
                    $type['is_active'] = $family['is_active'];
                    $type['updated_at'] = $family['updated_at'];
                }
            }
            unset($type);
        }
        unset($family);
    }

    public function setSubtypes(string $code, bool $hasSubtypes, ?string $primaryName): ?array
    {
        $this->seedIfEmpty(MasterDataKind::LawFamily);
        $updated = null;
        $code = $this->normalizeCode($code);
        $this->blob->withLock(self::BLOB_KIND, MasterDataKind::LawFamily->value, function (array &$catalog) use ($code, $hasSubtypes, $primaryName, &$updated): void {
            $families = $this->itemsFromData($catalog);
            $types = array_values((array) ($catalog['law_types'] ?? []));
            $index = $this->itemIndex($families, $code);
            if ($index === null) {
                return;
            }
            $family = &$families[$index];
            if ((bool) $family['attrs']['has_subtypes'] === $hasSubtypes) {
                $updated = $family;
                return;
            }
            $children = array_values(array_filter($types, static fn (array $type): bool => $type['attrs']['family_code'] === $code));
            if ($hasSubtypes) {
                $name = trim((string) $primaryName);
                if ($name === '' || $this->normalizeName($name) === $this->normalizeName($family['name'])) {
                    throw ValidationException::withMessages(['primary_name' => ['กรุณาตั้งชื่อประเภทหลักให้แตกต่างจากชื่อกลุ่ม']]);
                }
                $primary = array_values(array_filter($children, static fn (array $type): bool => $type['attrs']['is_primary']))[0] ?? null;
            } else {
                $active = array_values(array_filter($children, static fn (array $type): bool => (bool) $type['is_active']));
                if (count($active) !== 1) {
                    throw new MasterDataConflict('ต้องเหลือประเภทย่อยเพียงประเภทเดียวก่อน');
                }
                $primary = $active[0];
                $name = $family['name'];
            }
            if ($primary === null) {
                throw new MasterDataConflict('ไม่พบประเภทหลักของกลุ่ม');
            }
            $this->assertUniqueName($types, $name, $primary['code']);
            $family['attrs']['has_subtypes'] = $hasSubtypes;
            $family['updated_at'] = now()->toIso8601String();
            foreach ($types as &$type) {
                if ($type['attrs']['family_code'] === $code) {
                    $type['attrs']['is_primary'] = $type['code'] === $primary['code'];
                    if ($type['attrs']['is_primary']) {
                        $type['name'] = $name;
                        $type['updated_at'] = $family['updated_at'];
                    }
                }
            }
            unset($type);
            $this->normalizeLawCatalog($families, $types, true);
            $updated = $family;
            $catalog = ['items' => $families, 'law_types' => $types];
        });
        return $updated;
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
