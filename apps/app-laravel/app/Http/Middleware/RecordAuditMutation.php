<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditLogStore;
use App\Services\Auth\DocumentAccess;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\MasterData\UnitStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RecordAuditMutation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $controller = class_basename($request->route()?->getControllerClass() ?? '');
        $method = $request->route()?->getActionMethod();
        $resource = match ($controller) {
            'MasterDataController' => 'master_data', 'UnitController' => 'unit',
            'EsignController', 'PdfExportController', 'WordExportController', 'OriginalPdfExportController', 'ExportController' => 'document',
            default => null,
        };
        if ($resource === null || ($controller === 'UnitController' && in_array($method, ['updateMember', 'removeMember'], true))) {
            return $next($request);
        }
        $id = (string) ($request->route('documentId') ?? $request->route('code') ?? '');
        $kind = MasterDataKind::tryFrom((string) $request->route('kind'));
        $snapshot = fn () => match ($resource) {
            'master_data' => $kind && $id ? app(MasterDataStore::class)->find($kind, $id) : null,
            'unit' => $id ? app(UnitStore::class)->find($id) : null,
            default => $id ? app(DocumentAccess::class)->readDocument($id) : null,
        };
        try {
            $before = $snapshot();
        } catch (\Throwable $exception) {
            report($exception);
            $before = null;
        }
        if ($resource === 'document') {
            $request->attributes->set('audit_deferred_document', $id);
        }
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $this->failure($request, $controller, $method, $resource, $id, $before, $status);
            throw $exception;
        }
        if ($response->getStatusCode() >= 400) {
            $this->failure($request, $controller, $method, $resource, $id, $before, $response->getStatusCode());

            return $response;
        }
        if ($controller === 'MasterDataController' && $method === 'destroy') {
            return $response;
        }
        try {
            $body = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
            $after = $snapshot() ?? ($body['item'] ?? $body);
            $id = $id ?: (string) ($after['code'] ?? $after['id'] ?? $request->route('kind') ?? '');
            $action = match (true) {
                $controller === 'EsignController' => match ($method) {
                    'send' => 'esign_send', 'cancel' => 'esign_cancel', default => 'upload'
                },
                str_contains($controller, 'ExportController') => 'export',
                $method === 'reorder' => 'reorder', $method === 'setActive' => 'toggle',
                $method === 'destroy' => 'delete', $method === 'store' => 'create', default => 'update',
            };
            app(AuditLogger::class)->record($action, $resource, $id, AuditLogStoreLabel::summary($action, $resource), [],
                (string) ($after['name'] ?? $before['name'] ?? $id), $resource === 'document' ? $id : null,
                ['before' => (array) $before, 'after' => (array) $after, 'kind' => $request->route('kind'),
                    'unit_code' => $resource === 'unit' ? $id : ($before['owner_unit_code'] ?? $after['owner_unit_code'] ?? null)]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $response;
    }

    private function failure(Request $request, string $controller, string $method, string $resource, string $id, mixed $before, int $status): void
    {
        $action = $controller === 'EsignController' ? match ($method) {
            'send' => 'esign_send', 'cancel' => 'esign_cancel', default => 'upload'
        }
        : (str_contains($controller, 'ExportController') ? 'export' : 'update');
        app(AuditLogger::class)->record($action, $resource, $id, 'ดำเนินการไม่สำเร็จ', [], $id,
            $resource === 'document' ? $id : null, ['result' => in_array($status, [401, 403], true) ? 'denied' : 'failed',
                'unit_code' => $resource === 'unit' ? $id : ($before['owner_unit_code'] ?? null), 'kind' => $request->route('kind')]);
    }
}

final class AuditLogStoreLabel
{
    public static function summary(string $action, string $resource): string
    {
        return AuditLogStore::ACTIONS[$action].' '.AuditLogStore::RESOURCES[$resource];
    }
}
