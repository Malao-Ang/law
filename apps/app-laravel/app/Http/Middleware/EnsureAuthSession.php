<?php

namespace App\Http\Middleware;

use App\Services\Auth\CurrentUser;
use App\Services\Auth\MongoUserStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards admin/write API routes. A no-op while AUTH_ENFORCED=false.
 * Role-by-route rules arrive later; for now role `user` is kept out of guarded routes.
 */
class EnsureAuthSession
{
    public function __construct(private readonly CurrentUser $currentUser) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth_ldap.enforced')) {
            return $next($request);
        }

        $user = $this->currentUser->user($request);
        if ($user === null) {
            return response()->json(['message' => 'กรุณาเข้าสู่ระบบ'], 401);
        }

        $activeCode = $request->session()->get('active_unit_code');
        $memberships = (array) ($user['memberships'] ?? []);
        $membership = count($memberships) === 1 ? $memberships[0] : null;
        foreach ($memberships as $candidate) {
            if ($candidate['unit_code'] === $activeCode) {
                $membership = $candidate;
                break;
            }
        }
        if (! ($user['is_super_admin'] ?? false) && ($membership['role'] ?? MongoUserStore::ROLE_USER) === MongoUserStore::ROLE_USER) {
            return response()->json(['message' => 'ไม่มีสิทธิ์เข้าถึง'], 403);
        }

        return $next($request);
    }
}
