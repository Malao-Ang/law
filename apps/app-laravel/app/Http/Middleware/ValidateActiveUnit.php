<?php

namespace App\Http\Middleware;

use App\Services\Auth\CurrentUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateActiveUnit
{
    public function __construct(private readonly CurrentUser $currentUser) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Refresh membership validity even on API routes without the legacy auth guard.
        $actor = $this->currentUser->user($request);
        if ($request->is('api/master-data/*') && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            abort_if($actor === null, 401, 'กรุณาเข้าสู่ระบบ');
            abort_unless(($actor['is_super_admin'] ?? false)
                || in_array($this->currentUser->role($actor, $request), ['inner_admin', 'outer_admin', 'editor'], true),
                403, 'ไม่มีสิทธิ์จัดการข้อมูลหลัก');
        }

        return $next($request);
    }
}
