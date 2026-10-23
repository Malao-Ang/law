<?php

namespace App\Services\Audit;

use Illuminate\Http\Request;
use Throwable;

final class AuditLogger
{
    public function __construct(private readonly AuditLogStore $store) {}

    /**
     * @param  array<int, string>  $changes
     */
    public function record(
        string $action,
        string $resource,
        string $resourceId,
        string $summary,
        array $changes = [],
        ?string $label = null,
        ?string $documentId = null,
    ): void {
        if (! isset(AuditLogStore::ACTIONS[$action], AuditLogStore::RESOURCES[$resource])) {
            return;
        }

        $request = request();
        try {
            $this->store->append([
                'action' => $action,
                'resource' => $resource,
                'resource_id' => $resourceId,
                'document_id' => $documentId,
                'label' => $label ?? '',
                'summary' => $summary,
                'changes' => $changes,
                'actor_name' => $this->actor($request, 'X-Actor-Name', 'ไม่ระบุตัวตน'),
                'actor_role' => $this->actor($request, 'X-Actor-Role', ''),
                'ip' => $request instanceof Request ? (string) $request->ip() : '',
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function actor(?Request $request, string $header, string $fallback): string
    {
        if (! $request instanceof Request) {
            return $fallback;
        }

        $raw = trim((string) $request->header($header, ''));
        if ($raw === '') {
            return $fallback;
        }

        $decoded = trim(rawurldecode($raw));

        return $decoded !== '' ? $decoded : $fallback;
    }
}
