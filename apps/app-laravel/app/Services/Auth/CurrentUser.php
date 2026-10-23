<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use App\Services\MasterData\UnitStore;

/**
 * Resolves the logged-in user from the session (`auth_username`) via MongoUserStore.
 * The Eloquent users table is not used for identity (sqlite has no volume in production).
 */
class CurrentUser
{
    public const SESSION_KEY = 'auth_username';

    public const ACTIVE_UNIT_KEY = 'active_unit_code';

    public function __construct(private readonly MongoUserStore $users, private readonly UnitStore $units) {}

    /** @return array<string, mixed>|null */
    public function user(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $username = $request->session()->get(self::SESSION_KEY);
        if (! is_string($username) || $username === '') {
            return null;
        }

        $user = $this->users->find($username);
        if ($user === null || ($user['is_disabled'] ?? false) === true) {
            $request->session()->forget([self::SESSION_KEY, self::ACTIVE_UNIT_KEY]);

            return null;
        }

        $active = $request->session()->get(self::ACTIVE_UNIT_KEY);
        if ($active !== null) {
            $unit = is_string($active) ? $this->units->find($active) : null;
            if ($unit === null || ! $unit['is_active'] || (! ($user['is_super_admin'] ?? false)
                && ! in_array($active, array_column((array) ($user['memberships'] ?? []), 'unit_code'), true))) {
                $request->session()->forget(self::ACTIVE_UNIT_KEY);
            }
        }

        return $user;
    }

    public function login(Request $request, string $username): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, MongoUserStore::key($username));
        $request->session()->forget(self::ACTIVE_UNIT_KEY);
        $user = $this->users->find($username);
        $memberships = (array) ($user['memberships'] ?? []);
        if (! ($user['is_super_admin'] ?? false) && count($memberships) === 1) {
            $unit = $this->units->find($memberships[0]['unit_code']);
            if ($unit !== null && $unit['is_active']) {
                $request->session()->put(self::ACTIVE_UNIT_KEY, $unit['code']);
            }
        }
    }

    public function logout(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    public function payload(array $user, Request $request): array
    {
        $activeCode = $request->session()->get(self::ACTIVE_UNIT_KEY);
        $memberships = array_map(function (array $membership): array {
            $unit = $this->units->find($membership['unit_code']);
            $membership['unit_name'] = $unit['name'] ?? null;
            $membership['unit'] = $unit;
            $membership['actions'] = MongoUserStore::effectiveActions($membership);

            return $membership;
        }, (array) ($user['memberships'] ?? []));

        return [
            'user' => [
                'username' => $user['username'] ?? null,
                'name' => $user['name'] ?? null,
                'email' => $user['email'] ?? null,
                'position' => $user['position'] ?? null,
                'fac_id' => $user['fac_id'] ?? null,
                'fac_name' => $user['fac_name'] ?? null,
                'dep_id' => $user['dep_id'] ?? null,
                'dep_name' => $user['dep_name'] ?? null,
                'ums_fac_id' => $user['ums_fac_id'] ?? null,
                'ums_dep_id' => $user['ums_dep_id'] ?? null,
                'retired' => (bool) ($user['retired'] ?? false),
            ],
            'role' => $this->role($user, $request),
            'is_super_admin' => (bool) ($user['is_super_admin'] ?? false),
            'memberships' => array_values($memberships),
            'active_unit' => is_string($activeCode) ? $this->units->find($activeCode) : null,
            'needs_unit_selection' => ! ($user['is_super_admin'] ?? false) && count($memberships) > 0 && $activeCode === null,
            'auth_enforced' => (bool) config('auth_ldap.enforced'),
        ];
    }

    public function role(array $user, Request $request): string
    {
        if ($user['is_super_admin'] ?? false) {
            return MongoUserStore::ROLE_SUPER_ADMIN;
        }
        $active = $request->session()->get(self::ACTIVE_UNIT_KEY);
        foreach ((array) ($user['memberships'] ?? []) as $membership) {
            if ($membership['unit_code'] === $active) {
                return $membership['role'];
            }
        }

        return MongoUserStore::ROLE_USER;
    }
}
