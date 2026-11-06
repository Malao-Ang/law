<?php

namespace App\Services\Auth;

use App\Services\Storage\MongoBlobStore;
use App\Services\MasterData\UnitStore;
use Illuminate\Support\Carbon;

/**
 * Users keyed by lowercase LDAP username (Mongo collection `users`, blob kind `user`).
 */
class MongoUserStore
{
    public const KIND = 'user';

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_INNER_ADMIN = 'inner_admin';

    public const ROLE_OUTER_ADMIN = 'outer_admin';

    public const ROLE_EDITOR = 'editor';

    public const ROLE_USER = 'user';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_INNER_ADMIN,
        self::ROLE_OUTER_ADMIN,
        self::ROLE_EDITOR,
        self::ROLE_USER,
    ];

    private const PROFILE_FIELDS = ['usid', 'psn_id', 'fac_id', 'fac_name', 'dep_id', 'dep_name', 'position', 'retired'];

    public const ACTIONS = ['upload', 'edit', 'delete', 'esign', 'publish'];

    public function __construct(
        private readonly MongoBlobStore $blobs,
        private readonly UnitStore $units,
        private readonly MembershipAuditLog $audit,
    ) {}

    public function all(): array
    {
        return $this->blobs->all(self::KIND);
    }

    public static function effectiveActions(array $membership): array
    {
        return match ($membership['role'] ?? self::ROLE_USER) {
            self::ROLE_INNER_ADMIN, self::ROLE_OUTER_ADMIN => self::ACTIONS,
            self::ROLE_EDITOR => array_values(array_intersect(self::ACTIONS, (array) ($membership['actions'] ?? []))),
            default => [],
        };
    }

    public static function unitRole(array $user, string $unitCode): ?string
    {
        foreach ((array) ($user['memberships'] ?? []) as $membership) {
            if ($membership['unit_code'] === $unitCode) {
                return $membership['role'];
            }
        }

        return null;
    }

    /** Check target permissions inside the optimistic lock so retries recheck the current membership. */
    public function changeMembership(array $actor, string $unitCode, string $username, ?array $change, ?array $person = null): ?array
    {
        $super = (bool) ($actor['is_super_admin'] ?? false);
        $actorRole = self::unitRole($actor, $unitCode);
        abort_unless($super || $actorRole === self::ROLE_INNER_ADMIN, 403, 'ไม่มีสิทธิ์แก้ไขสมาชิกหน่วยงานนี้');
        abort_if(! $super && ($change === null || ! in_array($change['role'], [self::ROLE_USER, self::ROLE_EDITOR], true)), 403, 'ไม่มีสิทธิ์เพิ่ม ลบสมาชิก หรือกำหนดบทบาทผู้ดูแล');
        $key = self::key($username);
        $before = $after = null;
        $this->blobs->withLock(self::KIND, $key, function (array &$data) use ($actor, $super, $unitCode, $key, $change, $person, &$before, &$after): void {
            $memberships = (array) ($data['memberships'] ?? []);
            $index = array_search($unitCode, array_column($memberships, 'unit_code'), true);
            $before = $index === false ? null : $memberships[$index];
            abort_if(! $super && ($before === null || ! in_array($before['role'], [self::ROLE_USER, self::ROLE_EDITOR], true)), 403, 'แก้ไขได้เฉพาะสมาชิกเดิมที่เป็นผู้ใช้หรือผู้แก้ไขในหน่วยงานของตนเอง');
            abort_if($data === [] && ($person === null || $change === null), 404, 'ไม่พบผู้ใช้ กรุณาค้นหาผู้ใช้จาก UMS อีกครั้ง');
            if ($data === []) {
                $data = ['username' => $key, 'name' => $person['name'], 'email' => $person['email'],
                    'is_super_admin' => false, 'is_disabled' => false, 'created_at' => now()->toIso8601String(),
                    'ums_fac_id' => $person['fac_id'], 'ums_dep_id' => $person['dep_id']];
                foreach (self::PROFILE_FIELDS as $field) {
                    $data[$field] = $person[$field] ?? null;
                }
            }
            if ($change === null) {
                abort_if($index === false, 404, 'ไม่พบสมาชิกในหน่วยงานนี้');
                unset($memberships[$index]);
                $after = null;
            } else {
                $after = self::membership($unitCode, $change['role'], $change['actions'], $before['source'] ?? 'manual', $actor['username']);
                if ($index === false) {
                    $memberships[] = $after;
                } else {
                    $memberships[$index] = $after;
                }
            }
            $data['memberships'] = array_values($memberships);
            $data['updated_at'] = now()->toIso8601String();
        });
        $this->audit->record($actor['username'], $change === null ? 'membership.remove' : ($before === null ? 'membership.add' : 'membership.update'), $key, $unitCode, $before, $after);

        return $after;
    }

    public static function membership(string $unitCode, string $role = self::ROLE_USER, array $actions = [], string $source = 'ums', ?string $by = null): array
    {
        $membership = ['unit_code' => $unitCode, 'role' => $role, 'actions' => $actions, 'source' => $source, 'granted_by' => $by, 'granted_at' => now()->toIso8601String()];
        $membership['actions'] = self::effectiveActions($membership);

        return $membership;
    }

    public static function key(string $username): string
    {
        return strtolower(trim($username));
    }

    /** @return array<string, mixed>|null */
    public function find(string $username): ?array
    {
        $user = $this->blobs->read(self::KIND, self::key($username));

        return $user === null ? null : (array) $user;
    }

    /**
     * Create or refresh a user after a successful LDAP login.
     * Profile fields come from LDAP/UMS; memberships and is_disabled are retained.
     *
     * @param  array{name?: string, email?: string}  $ldap
     * @param  array<string, mixed>|null  $ums  normalized UMS person, null when not found
     * @return array<string, mixed>
     */
    public function upsertFromLogin(string $username, array $ldap, ?array $ums, bool $superAdmin): array
    {
        $key = self::key($username);
        $now = Carbon::now()->toIso8601String();
        $saved = [];
        $before = [];

        $this->blobs->withLock(self::KIND, $key, function (array &$data) use ($key, $ldap, $ums, $superAdmin, $now, &$saved, &$before): void {
            $before = $data;
            $isNew = $data === [];

            $data['username'] = $key;
            $data['name'] = $this->firstFilled($ldap['name'] ?? null, $ums['name'] ?? null, $data['name'] ?? null) ?? $key;
            $data['email'] = $this->firstFilled($ldap['email'] ?? null, $ums['email'] ?? null, $data['email'] ?? null);

            if ($ums !== null) {
                foreach (self::PROFILE_FIELDS as $field) {
                    $data[$field] = $ums[$field] ?? null;
                }
            } else {
                foreach (self::PROFILE_FIELDS as $field) {
                    $data[$field] = $data[$field] ?? null;
                }
            }

            if ($isNew) {
                $data['is_disabled'] = false;
                $data['created_at'] = $now;
            }
            $data['ums_fac_id'] = $ums !== null ? ($data['fac_id'] ?? null) : ($data['ums_fac_id'] ?? $data['fac_id'] ?? null);
            $data['ums_dep_id'] = $ums !== null ? ($data['dep_id'] ?? null) : ($data['ums_dep_id'] ?? $data['dep_id'] ?? null);
            $unit = $this->units->forAffiliation($data);
            $memberships = (array) ($data['memberships'] ?? []);
            if ($unit !== null && ! in_array($unit['code'], array_column($memberships, 'unit_code'), true)) {
                $role = in_array($data['role'] ?? '', [self::ROLE_INNER_ADMIN, self::ROLE_OUTER_ADMIN, self::ROLE_EDITOR], true)
                    ? $data['role'] : self::ROLE_USER;
                $memberships[] = self::membership($unit['code'], $role);
            }
            $data['memberships'] = $memberships;
            $data['is_super_admin'] = $superAdmin;
            // Keep an unresolved legacy unit role for a later affiliation lookup/migration.
            if ($unit !== null || ($data['role'] ?? '') === self::ROLE_SUPER_ADMIN) {
                unset($data['role']);
            }

            $data['updated_at'] = $now;
            $data['last_login_at'] = $now;
            $saved = $data;
        });
        $this->auditChanges('system:login', $key, $before, $saved);

        return $saved;
    }

    /** Idempotent conversion; dry-run callers must not call this method. */
    public function migrateMemberships(string $username): void
    {
        $before = $after = [];
        $key = self::key($username);
        $this->blobs->withLock(self::KIND, $key, function (array &$data) use (&$before, &$after): void {
            $before = $data;
            if ($data === [] || ! array_key_exists('role', $data)) {
                $after = $data;

                return;
            }
            $data['ums_fac_id'] = $data['ums_fac_id'] ?? $data['fac_id'] ?? null;
            $data['ums_dep_id'] = $data['ums_dep_id'] ?? $data['dep_id'] ?? null;
            $data['is_super_admin'] = (bool) ($data['is_super_admin'] ?? false) || $data['role'] === self::ROLE_SUPER_ADMIN;
            $data['is_disabled'] = (bool) ($data['is_disabled'] ?? false);
            $data['memberships'] = (array) ($data['memberships'] ?? []);
            $unit = $this->units->forAffiliation($data);
            abort_if($unit === null && $data['role'] !== self::ROLE_SUPER_ADMIN, 422, 'ไม่พบสังกัด UMS ของผู้ใช้ จึงยังไม่ย้ายบทบาทเดิม');
            if ($unit !== null && ! in_array($unit['code'], array_column($data['memberships'], 'unit_code'), true)) {
                $role = in_array($data['role'], [self::ROLE_INNER_ADMIN, self::ROLE_OUTER_ADMIN, self::ROLE_EDITOR], true) ? $data['role'] : self::ROLE_USER;
                $data['memberships'][] = self::membership($unit['code'], $role);
            }
            unset($data['role']);
            $data['updated_at'] = now()->toIso8601String();
            $after = $data;
        });
        $this->auditChanges('system:migration', $key, $before, $after);
    }

    private function auditChanges(string $actor, string $username, array $before, array $after): void
    {
        $old = array_column((array) ($before['memberships'] ?? []), null, 'unit_code');
        foreach ((array) ($after['memberships'] ?? []) as $membership) {
            if (($old[$membership['unit_code']] ?? null) !== $membership) {
                $this->audit->record($actor, 'membership.upsert', $username, $membership['unit_code'], $old[$membership['unit_code']] ?? null, $membership);
            }
        }
        if ((bool) ($before['is_super_admin'] ?? (($before['role'] ?? '') === self::ROLE_SUPER_ADMIN)) !== (bool) ($after['is_super_admin'] ?? false)) {
            $this->audit->record($actor, 'super_admin.change', $username, null, (bool) ($before['is_super_admin'] ?? (($before['role'] ?? '') === self::ROLE_SUPER_ADMIN)), (bool) $after['is_super_admin']);
        }
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
