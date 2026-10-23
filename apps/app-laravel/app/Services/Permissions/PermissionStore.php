<?php

namespace App\Services\Permissions;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PermissionStore
{
    private const BLOB_KIND = 'data';

    private const GROUPS_ID = 'groups';

    private const DIRECTORY_ID = 'directory';

    public function __construct(private readonly MongoBlobStore $blob,
        private readonly \App\Services\MasterData\UnitStore $units,
        private readonly \App\Services\Auth\MongoUserStore $users) {}

    /**
     * @return array{units: array<int, array<string, string|null>>, users: array<int, array<string, string|null>>}
     */
    public function directory(): array
    {
        return [
            'units' => array_values(array_map(fn ($unit) => ['id' => $unit['code'], 'name' => $unit['name']], $this->units->all())),
            'users' => array_values(array_map(fn ($user) => ['id' => $user['username'], 'name' => $user['name'] ?? $user['username'], 'email' => $user['email'] ?? null], $this->users->all())),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listGroups(): array
    {
        $groups = $this->readGroups();
        usort($groups, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return array_map(fn (array $group): array => $this->hydrateGroup($group), $groups);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findGroup(string $groupId): ?array
    {
        foreach ($this->readGroups() as $group) {
            if (($group['id'] ?? null) === $groupId) {
                return $this->hydrateGroup($group);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createGroup(array $payload): array
    {
        $normalized = $this->normalizeGroupPayload($payload);
        $group = null;

        $this->blob->withLock(self::BLOB_KIND, self::GROUPS_ID, function (array &$groups) use ($normalized, &$group): void {
            $groups = array_values(array_filter($groups, 'is_array'));
            $this->assertUniqueName($groups, $normalized['name']);

            $timestamp = now()->toIso8601String();
            $group = array_merge($normalized, [
                'id' => sprintf('pg_%s_%s', now()->format('Ymd_His'), substr(bin2hex(random_bytes(3)), 0, 6)),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $groups[] = $group;
        });

        if ($group === null) {
            throw new RuntimeException('PermissionStore: createGroup failed to persist group.');
        }

        return $this->hydrateGroup($group);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function updateGroup(string $groupId, array $payload): ?array
    {
        $normalized = $this->normalizeGroupPayload($payload);
        $group = null;

        $this->blob->withLock(self::BLOB_KIND, self::GROUPS_ID, function (array &$groups) use ($groupId, $normalized, &$group): void {
            $groups = array_values(array_filter($groups, 'is_array'));
            $index = $this->groupIndex($groups, $groupId);
            if ($index === null) {
                return;
            }

            $this->assertUniqueName($groups, $normalized['name'], $groupId);

            $group = array_merge($groups[$index], $normalized, [
                'updated_at' => now()->toIso8601String(),
            ]);

            $groups[$index] = $group;
        });

        if ($group === null) {
            return null;
        }

        $this->reindexLinkedDocuments($groupId);
        return $this->hydrateGroup($group);
    }

    public function deleteGroup(string $groupId): bool
    {
        $deleted = false;

        $this->blob->withLock(self::BLOB_KIND, self::GROUPS_ID, function (array &$groups) use ($groupId, &$deleted): void {
            $groups = array_values(array_filter($groups, 'is_array'));
            $index = $this->groupIndex($groups, $groupId);
            if ($index === null) {
                return;
            }

            array_splice($groups, $index, 1);
            $deleted = true;
        });

        if ($deleted) $this->reindexLinkedDocuments($groupId);
        return $deleted;
    }

    private function reindexLinkedDocuments(string $groupId): void
    {
        foreach (app(\App\Services\ReviewStore::class)->listLawMeta() as $row) {
            if (in_array($groupId, $row['permission_group_ids'] ?? [], true)) {
                app(\App\Services\Search\LawIndexer::class)->index($row['document_id']);
            }
        }
    }

    /**
     * @param  array<int, string>  $groupIds
     * @return array<int, string>
     */
    public function validateGroupIds(array $groupIds): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $groupIds,
        ))));

        $existing = [];
        foreach ($this->readGroups() as $group) {
            $id = trim((string) ($group['id'] ?? ''));
            if ($id !== '') {
                $existing[$id] = true;
            }
        }

        $invalid = array_values(array_filter($normalized, static fn (string $id): bool => ! isset($existing[$id])));
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'law_meta.permission_group_ids' => ['พบกลุ่มสิทธิ์ที่ไม่อยู่ในระบบ'],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function assertUniqueName(array $groups, string $name, ?string $ignoreId = null): void
    {
        $needle = mb_strtolower($name);
        foreach ($groups as $group) {
            if ($ignoreId !== null && (string) ($group['id'] ?? '') === $ignoreId) {
                continue;
            }

            if (mb_strtolower(trim((string) ($group['name'] ?? ''))) === $needle) {
                throw ValidationException::withMessages([
                    'name' => ['ชื่อกลุ่มนี้มีอยู่แล้ว'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeGroupPayload(array $payload): array
    {
        $directory = $this->directory();

        $unitIds = $this->normalizeMemberIds($payload['unit_ids'] ?? [], $directory['units'], 'unit_ids');
        $userIds = $this->normalizeMemberIds($payload['user_ids'] ?? [], $directory['users'], 'user_ids');

        if ($unitIds === [] && $userIds === []) {
            throw ValidationException::withMessages([
                'unit_ids' => ['ต้องเลือกสมาชิกอย่างน้อย 1 รายการ'],
            ]);
        }

        return [
            'name' => trim((string) ($payload['name'] ?? '')),
            'description' => $this->nullableTrimmedString($payload['description'] ?? null),
            'unit_ids' => $unitIds,
            'user_ids' => $userIds,
        ];
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<int, array<string, string|null>>  $records
     * @return array<int, string>
     */
    private function normalizeMemberIds(mixed $ids, array $records, string $field): array
    {
        $normalized = is_array($ids)
            ? array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $ids,
            ))))
            : [];

        $availableIds = [];
        foreach ($records as $record) {
            $id = trim((string) ($record['id'] ?? ''));
            if ($id !== '') {
                $availableIds[$id] = true;
            }
        }

        $invalid = array_values(array_filter($normalized, static fn (string $id): bool => ! isset($availableIds[$id])));
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                $field => ['มีสมาชิกที่เลือกไม่ถูกต้อง'],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function groupIndex(array $groups, string $groupId): ?int
    {
        foreach ($groups as $index => $group) {
            if (($group['id'] ?? null) === $groupId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function hydrateGroup(array $group): array
    {
        $directory = $this->directory();
        $unitsById = $this->indexById($directory['units']);
        $usersById = $this->indexById($directory['users']);

        $unitIds = array_values(array_filter((array) ($group['unit_ids'] ?? []), 'is_string'));
        $userIds = array_values(array_filter((array) ($group['user_ids'] ?? []), 'is_string'));

        $units = array_values(array_filter(array_map(static fn (string $id): ?array => $unitsById[$id] ?? null, $unitIds)));
        $users = array_values(array_filter(array_map(static fn (string $id): ?array => $usersById[$id] ?? null, $userIds)));
        unset($group['position_ids']);

        return array_merge($group, [
            'units' => $units,
            'users' => $users,
            'counts' => [
                'units' => count($units),
                'users' => count($users),
                'total' => count($units) + count($users),
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, string|null>>  $records
     * @return array<string, array<string, string|null>>
     */
    private function indexById(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) {
            $id = trim((string) ($record['id'] ?? ''));
            if ($id !== '') {
                $indexed[$id] = $record;
            }
        }

        return $indexed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readGroups(): array
    {
        return array_values(array_filter($this->blob->read(self::BLOB_KIND, self::GROUPS_ID) ?? [], 'is_array'));
    }

}
