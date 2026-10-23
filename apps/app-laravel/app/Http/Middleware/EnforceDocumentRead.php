<?php

namespace App\Http\Middleware;

use App\Services\Auth\DocumentAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceDocumentRead
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth_ldap.enforced')) return $next($request);
        foreach (['documentId', 'targetDocumentId'] as $parameter) {
            $id = $request->route($parameter);
            if (! is_string($id)) continue;
            $document = $this->access->readDocument($id);
            abort_if($document === null, 404, 'ไม่พบเอกสาร');
            $this->access->authorizeRead($request, $document);
        }
        return $next($request);
    }
}
