<?php

namespace App\Services\Auth;

use App\Services\Storage\MongoBlobStore;
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

    public function __construct(private readonly MongoBlobStore $blobs) {}

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
     * Profile fields come from LDAP/UMS; role and is_disabled are never overwritten from them.
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

        $this->blobs->withLock(self::KIND, $key, function (array &$data) use ($key, $ldap, $ums, $superAdmin, $now, &$saved): void {
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
                $data['role'] = self::ROLE_USER;
                $data['is_disabled'] = false;
                $data['created_at'] = $now;
            }
            if ($superAdmin) {
                $data['role'] = self::ROLE_SUPER_ADMIN;
            }

            $data['updated_at'] = $now;
            $data['last_login_at'] = $now;
            $saved = $data;
        });

        return $saved;
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
