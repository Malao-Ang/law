<?php

namespace App\Http\Middleware;

use App\Services\Auth\DocumentAccess;
use App\Services\Auth\CurrentUser;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnforceDocumentRead
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $private = [];
        foreach (['documentId', 'targetDocumentId'] as $parameter) {
            $id = $request->route($parameter);
            if (! is_string($id)) continue;
            $document = $this->access->readDocument($id);
            if ($document === null) {
                abort_if(config('auth_ldap.enforced'), 404, 'ไม่พบเอกสาร');
                continue;
            }
            try {
                if (config('auth_ldap.enforced')) $this->access->authorizeRead($request, $document);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                app(AuditLogger::class)->record('view', 'document', $id, 'ปฏิเสธการเปิดดูเอกสาร', [], $id, $id,
                    ['unit_code' => $document['owner_unit_code'] ?? null, 'result' => 'denied']);
                throw $exception;
            }
            if (! $this->access->isPublished($document) || ($document['access_scope'] ?? 'public') !== 'public') $private[$id] = $document;
        }
        $response = $next($request);
        if ($response->getStatusCode() < 400) foreach ($private as $id => $document) {
            try {
                $username = app(CurrentUser::class)->user($request)['username'] ?? 'anonymous';
                if (Cache::add('audit:view:'.hash('sha256', $username.':'.$id), true, 1800)) {
                    app(AuditLogger::class)->record('view', 'document', $id, 'เปิดดูเอกสาร', [], $id, $id,
                        ['unit_code' => $document['owner_unit_code'] ?? null]);
                }
            } catch (\Throwable $exception) { report($exception); }
        }
        return $response;
    }
}
