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
        $this->currentUser->user($request);

        return $next($request);
    }
}
