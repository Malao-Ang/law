<?php

namespace App\Services\Audit;

use App\Services\Auth\CurrentUser;
use App\Services\ReviewStore;
use Throwable;

final class AuditLogger
{
    public function __construct(private readonly AuditLogStore $store) {}

    public function record(string $action, string $resource, string $resourceId, string $summary,
        array $changes = [], ?string $label = null, ?string $documentId = null,
        array $context = []): void
    {
        try {
            if (! isset(AuditLogStore::ACTIONS[$action], AuditLogStore::RESOURCES[$resource])) return;
            $request = request();
            $current = app(CurrentUser::class);
            $user = $current->user($request);
            $active = $request->hasSession() ? $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY) : null;
            $document = $documentId ? app(ReviewStore::class)->getStatus($documentId) : null;
            [$before, $after] = self::diff((array) ($context['before'] ?? []), (array) ($context['after'] ?? []));
            $this->store->append([
                'action' => $action, 'resource' => $resource, 'resource_id' => $resourceId,
                'document_id' => $documentId, 'label' => $label ?? '', 'summary' => $summary,
                'changes' => $changes, 'before' => $before, 'after' => $after,
                'actor_username' => $user['username'] ?? '',
                'actor_name' => $user['name'] ?? 'ไม่ระบุตัวตน',
                'actor_role' => $user ? $current->role($user, $request) : '',
                'active_unit_code' => $active,
                'unit_code' => $context['unit_code'] ?? $document['owner_unit_code'] ?? $active,
                'on_behalf_of' => $context['on_behalf_of'] ?? ($request->attributes->get('audit_on_behalf_of')),
                'kind' => $context['kind'] ?? null,
                'result' => $context['result'] ?? 'success',
                'ip' => (string) $request->ip(), 'user_agent' => (string) $request->userAgent(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Only changed leaf fields; omit secrets and large document payloads. */
    public static function diff(array $before, array $after, string $prefix = ''): array
    {
        $old = $new = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (preg_match('/password|token|secret|base64|blob|citizen/i', (string) $key)) continue;
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if ($a === $b) continue;
            $field = $prefix.$key;
            if (is_array($a) || is_array($b)) {
                [$x, $y] = self::diff((array) $a, (array) $b, $field.'.');
                $old += $x; $new += $y;
            } else {
                $old[$field] = is_string($a) ? mb_substr($a, 0, 240) : $a;
                $new[$field] = is_string($b) ? mb_substr($b, 0, 240) : $b;
            }
            if (count($old) >= 60) break;
        }
        return [array_slice($old, 0, 60, true), array_slice($new, 0, 60, true)];
    }
}
