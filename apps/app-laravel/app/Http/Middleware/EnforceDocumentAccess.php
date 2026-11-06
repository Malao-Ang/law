<?php

namespace App\Http\Middleware;

use App\Services\Auth\DocumentAccess;
use App\Services\Auth\DocumentOwnership;
use App\Services\ReviewStore;
use App\Services\Storage\MongoBlobStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceDocumentAccess
{
    public function __construct(private readonly DocumentAccess $access, private readonly DocumentOwnership $ownership,
        private readonly ReviewStore $documents, private readonly MongoBlobStore $blobs) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('api/documents')) {
            $this->access->authorize($request, 'upload');
        }
        $id = $request->route('documentId');
        if (! is_string($id) || ! $request->is('api/documents/*') || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $document = $this->documents->getStatus($id);
        if ($document === null) {
            abort_if(config('auth_ldap.enforced'), 404, 'ไม่พบเอกสาร');
            return $next($request);
        }
        $suffix = implode('/', array_slice($request->segments(), 3));
        if (config('auth_ldap.enforced')) {
            abort_if(app(\App\Services\Auth\CurrentUser::class)->user($request) === null, 401, 'กรุณาเข้าสู่ระบบ');
        }
        $actions = $this->access->actions($request, $id, $suffix);
        foreach ($actions as $action) {
            $this->access->authorize($request, $action, $document);
        }
        $audit = $suffix !== 'transfer' && $this->access->actsOnBehalf($request, $document);
        $before = $audit ? ['status' => $document, 'review' => $this->blobs->read('review', $id), 'responsible' => $document['responsible'] ?? null] : [];
        $response = $next($request);
        if ($audit && $response->getStatusCode() < 400) {
            $this->ownership->record($request, $id, implode('+', $actions).':'.$suffix, $before,
                ['status' => $this->documents->getStatus($id), 'review' => $this->blobs->read('review', $id)]);
        }
        if (config('auth_ldap.enforced') && $response->getStatusCode() < 400
            && in_array($suffix, ['transfer', 'workflow-progress', 'export-pdf', 'export-word', 'esign/cancel'], true)) {
            try {
                app(\App\Services\Search\LawIndexer::class)->index($id);
            } catch (\Throwable $exception) {
                \Illuminate\Support\Facades\Log::warning('Law indexing failed after access metadata update', ['document_id' => $id, 'error' => $exception->getMessage()]);
            }
        }
        return $response;
    }
}
