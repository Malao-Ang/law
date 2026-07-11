<?php

namespace App\Services\Permissions;

use Illuminate\Validation\ValidationException;

class PermissionStore
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? storage_path('app/poc/permissions');
        $this->ensureDirectories();
    }

    /**
     * @return array{units: array<int, array<string, string|null>>, positions: array<int, array<string, string|null>>, users: array<int, array<string, string|null>>}
     */
    public function directory(): array
    {
        $path = $this->directoryPath();
        if (! is_file($path)) {
            $this->writeJson($path, $this->defaultDirectory());
        }

        $directory = $this->readJson($path);

        return [
            'units' => array_values(array_filter((array) ($directory['units'] ?? []), 'is_array')),
            'positions' => array_values(array_filter((array) ($directory['positions'] ?? []), 'is_array')),
            'users' => array_values(array_filter((array) ($directory['users'] ?? []), 'is_array')),
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
        $groups = $this->readGroups();
        $normalized = $this->normalizeGroupPayload($payload);
        $this->assertUniqueName($groups, $normalized['name']);

        $group = array_merge($normalized, [
            'id' => sprintf('pg_%s_%s', now()->format('Ymd_His'), substr(bin2hex(random_bytes(3)), 0, 6)),
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $groups[] = $group;
        $this->writeGroups($groups);

        return $this->hydrateGroup($group);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function updateGroup(string $groupId, array $payload): ?array
    {
        $groups = $this->readGroups();
        $index = $this->groupIndex($groups, $groupId);
        if ($index === null) {
            return null;
        }

        $normalized = $this->normalizeGroupPayload($payload);
        $this->assertUniqueName($groups, $normalized['name'], $groupId);

        $group = array_merge($groups[$index], $normalized, [
            'updated_at' => now()->toIso8601String(),
        ]);

        $groups[$index] = $group;
        $this->writeGroups($groups);

        return $this->hydrateGroup($group);
    }

    public function deleteGroup(string $groupId): bool
    {
        $groups = $this->readGroups();
        $index = $this->groupIndex($groups, $groupId);
        if ($index === null) {
            return false;
        }

        array_splice($groups, $index, 1);
        $this->writeGroups($groups);

        return true;
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
        $positionIds = $this->normalizeMemberIds($payload['position_ids'] ?? [], $directory['positions'], 'position_ids');
        $userIds = $this->normalizeMemberIds($payload['user_ids'] ?? [], $directory['users'], 'user_ids');

        if ($unitIds === [] && $positionIds === [] && $userIds === []) {
            throw ValidationException::withMessages([
                'unit_ids' => ['ต้องเลือกสมาชิกอย่างน้อย 1 รายการ'],
            ]);
        }

        return [
            'name' => trim((string) ($payload['name'] ?? '')),
            'description' => $this->nullableTrimmedString($payload['description'] ?? null),
            'unit_ids' => $unitIds,
            'position_ids' => $positionIds,
            'user_ids' => $userIds,
        ];
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    /**
     * @param  mixed  $ids
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
        $positionsById = $this->indexById($directory['positions']);
        $usersById = $this->indexById($directory['users']);

        $unitIds = array_values(array_filter((array) ($group['unit_ids'] ?? []), 'is_string'));
        $positionIds = array_values(array_filter((array) ($group['position_ids'] ?? []), 'is_string'));
        $userIds = array_values(array_filter((array) ($group['user_ids'] ?? []), 'is_string'));

        $units = array_values(array_filter(array_map(static fn (string $id): ?array => $unitsById[$id] ?? null, $unitIds)));
        $positions = array_values(array_filter(array_map(static fn (string $id): ?array => $positionsById[$id] ?? null, $positionIds)));
        $users = array_values(array_filter(array_map(static fn (string $id): ?array => $usersById[$id] ?? null, $userIds)));

        return array_merge($group, [
            'units' => $units,
            'positions' => $positions,
            'users' => $users,
            'counts' => [
                'units' => count($units),
                'positions' => count($positions),
                'users' => count($users),
                'total' => count($units) + count($positions) + count($users),
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
        $path = $this->groupsPath();
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter($this->readJson($path), 'is_array'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function writeGroups(array $groups): void
    {
        $this->writeJson($this->groupsPath(), array_values($groups));
    }

    /**
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    private function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>  $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->ensureDirectories();
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function ensureDirectories(): void
    {
        if (! is_dir($this->basePath)) {
            mkdir($this->basePath, 0777, true);
        }
    }

    private function groupsPath(): string
    {
        return $this->basePath.'/groups.json';
    }

    private function directoryPath(): string
    {
        return $this->basePath.'/directory.json';
    }

    /**
     * @return array{units: array<int, array<string, string|null>>, positions: array<int, array<string, string|null>>, users: array<int, array<string, string|null>>}
     */
    private function defaultDirectory(): array
    {
        return [
            'units' => [
                ['id' => 'unit_legal', 'name' => 'คณะกองกฎหมาย'],
                ['id' => 'unit_science', 'name' => 'คณะวิทยาศาสตร์'],
                ['id' => 'unit_president_office', 'name' => 'สำนักงานอธิการบดี'],
                ['id' => 'unit_finance', 'name' => 'กองคลัง'],
                ['id' => 'unit_procurement', 'name' => 'กองพัสดุ'],
                ['id' => 'unit_academic', 'name' => 'สำนักวิชาการ'],
            ],
            'positions' => [
                ['id' => 'position_president', 'name' => 'อธิการบดี'],
                ['id' => 'position_vice_president', 'name' => 'รองอธิการบดี'],
                ['id' => 'position_finance_director', 'name' => 'ผู้อำนวยการกองคลัง'],
                ['id' => 'position_legal_officer', 'name' => 'นิติกร'],
                ['id' => 'position_procurement_officer', 'name' => 'เจ้าหน้าที่พัสดุ'],
                ['id' => 'position_dean', 'name' => 'คณบดี'],
            ],
            'users' => [
                ['id' => 'user_somchai', 'name' => 'สมชาย ใจดี', 'email' => 'somchai@example.local'],
                ['id' => 'user_suda', 'name' => 'สุดา แสงทอง', 'email' => 'suda@example.local'],
                ['id' => 'user_anan', 'name' => 'อนันต์ วัฒน์กุล', 'email' => 'anan@example.local'],
                ['id' => 'user_kanya', 'name' => 'กัญญา บุญช่วย', 'email' => 'kanya@example.local'],
                ['id' => 'user_narin', 'name' => 'นรินทร์ ศรีสุข', 'email' => 'narin@example.local'],
                ['id' => 'user_pimchanok', 'name' => 'พิมพ์ชนก รุ่งเรือง', 'email' => 'pimchanok@example.local'],
            ],
        ];
    }
}
