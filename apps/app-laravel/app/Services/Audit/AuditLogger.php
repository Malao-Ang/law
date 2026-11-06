<?php

namespace App\Services\Audit;

use App\Services\Auth\CurrentUser;
use App\Services\Auth\DocumentAccess;
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
            if (! isset(AuditLogStore::ACTIONS[$action], AuditLogStore::RESOURCES[$resource])) {
                return;
            }
            $request = request();
            $recorded = $request->attributes->get('audit_recorded_documents', []);
            if (($context['deduplicate'] ?? false) && $documentId && (isset($recorded[$documentId]) || $request->attributes->get('audit_deferred_document') === $documentId)) {
                return;
            }
            $current = app(CurrentUser::class);
            $user = $current->user($request);
            $active = $request->hasSession() ? $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY) : null;
            $document = $documentId ? app(ReviewStore::class)->getStatus($documentId) : null;
            if ($documentId && $request->attributes->has('audit_document_before') && ! isset($context['before'])) {
                $context['before'] = $request->attributes->get('audit_document_before');
                $context['after'] = app(DocumentAccess::class)->readDocument($documentId) ?? [];
            }
            if ($action === 'update' && $resource === 'document' && isset($context['before'], $context['after'])) {
                foreach (['published_date', 'access_scope'] as $field) {
                    if (($context['before'][$field] ?? null) !== ($context['after'][$field] ?? null)) {
                        $action = empty($context['after']['published_date']) && $field === 'published_date'
                            || ($field === 'access_scope' && ($context['after'][$field] ?? '') !== 'public') ? 'unpublish' : 'publish';
                    }
                }
            }
            [$before, $after] = self::diff((array) ($context['before'] ?? []), (array) ($context['after'] ?? []));
            $this->store->append([
                'action' => $action, 'resource' => $resource, 'resource_id' => $resourceId,
                'document_id' => $documentId, 'label' => $label ?? '', 'summary' => $summary,
                'changes' => $changes, 'before' => $before, 'after' => $after,
                'actor_username' => $user['username'] ?? '',
                'actor_name' => $user['name'] ?? 'ไม่ระบุตัวตน',
                'is_super_admin_account' => (bool) ($user['can_act_as_super_admin'] ?? false),
                'actor_role' => $user ? $current->role($user, $request) : '',
                'active_unit_code' => $active,
                'unit_code' => $context['unit_code'] ?? $document['owner_unit_code'] ?? $active,
                'on_behalf_of' => $context['on_behalf_of'] ?? ($request->attributes->get('audit_on_behalf_of')),
                'kind' => $context['kind'] ?? null,
                'result' => $context['result'] ?? 'success',
                'ip' => (string) $request->ip(), 'user_agent' => (string) $request->userAgent(),
            ]);
            if ($documentId) {
                $recorded[$documentId] = true;
                $request->attributes->set('audit_recorded_documents', $recorded);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Only changed leaf fields; omit secrets and large document payloads. */
    public static function diff(array $before, array $after, string $prefix = ''): array
    {
        $old = $new = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (preg_match('/password|token|secret|base64|blob|citizen/i', (string) $key)) {
                continue;
            }
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if ($a === $b) {
                continue;
            }
            $field = $prefix.$key;
            if (is_array($a) || is_array($b)) {
                [$x, $y] = self::diff((array) $a, (array) $b, $field.'.');
                $old += $x;
                $new += $y;
            } else {
                $old[$field] = is_string($a) ? mb_substr($a, 0, 240) : $a;
                $new[$field] = is_string($b) ? mb_substr($b, 0, 240) : $b;
            }
            if (count($old) >= 60) {
                break;
            }
        }

        return [array_slice($old, 0, 60, true), array_slice($new, 0, 60, true)];
    }
}
