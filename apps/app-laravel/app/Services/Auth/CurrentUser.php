<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;

/**
 * Resolves the logged-in user from the session (`auth_username`) via MongoUserStore.
 * The Eloquent users table is not used for identity (sqlite has no volume in production).
 */
class CurrentUser
{
    public const SESSION_KEY = 'auth_username';

    public function __construct(private readonly MongoUserStore $users) {}

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
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $user;
    }

    public function login(Request $request, string $username): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, MongoUserStore::key($username));
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
    public function payload(array $user): array
    {
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
                'retired' => (bool) ($user['retired'] ?? false),
            ],
            'role' => ($user['is_super_admin'] ?? false) ? MongoUserStore::ROLE_SUPER_ADMIN : MongoUserStore::ROLE_USER,
            'is_super_admin' => (bool) ($user['is_super_admin'] ?? false),
            'memberships' => array_values((array) ($user['memberships'] ?? [])),
            'auth_enforced' => (bool) config('auth_ldap.enforced'),
        ];
    }
}
