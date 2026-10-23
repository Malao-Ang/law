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

        if ($this->currentUser->role($user, $request) === MongoUserStore::ROLE_USER) {
            return response()->json(['message' => 'ไม่มีสิทธิ์เข้าถึง'], 403);
        }

        return $next($request);
    }
}
