<?php

namespace App\Services\Auth;

use App\Services\MasterData\UnitStore;
use App\Services\ReviewStore;
use App\Services\Storage\MongoBlobStore;
use Illuminate\Http\Request;

/** All document action decisions use current memberships, never upload-time roles. */
class DocumentAccess
{
    public function __construct(private readonly CurrentUser $current, private readonly MongoUserStore $users,
        private readonly UnitStore $units, private readonly ReviewStore $documents, private readonly MongoBlobStore $blobs) {}

    public function documentMeta(array $document): array
    {
        $id = $document['document_id'] ?? $document['law_id'] ?? null;
        $review = is_string($id) ? $this->blobs->read('review', $id) : null;
        $status = is_string($id) ? $this->documents->getStatus($id) : null;
        return array_merge($document, $review['law_meta'] ?? $document['law_meta'] ?? [], $status ?? []);
    }

    public function readDocument(string $id): ?array
    {
        $status = $this->documents->getStatus($id);
        return $status === null ? null : $this->documentMeta($status);
    }

    public static function isPublished(array $document): bool
    {
        return ! empty($document['published_date']) || (int) ($document['workflow_completed_step'] ?? 0) >= 6;
    }

    public function canRead(Request $request, array $document): bool
    {
        return $this->context($request) === 'public'
            ? $this->publicScope($request, $document)
            : $this->adminScope($request, $document);
    }

    /** Context is fixed by the route, never by a header or request payload. */
    public function context(Request $request): string
    {
        return (string) ($request->route()?->defaults['document_context'] ?? 'admin');
    }

    public function visible(Request $request, array $document): bool
    {
        return match ($this->context($request)) {
            'public' => $this->publicScope($request, $document),
            'relation' => $this->adminScope($request, $document) || $this->publicScope($request, $document),
            default => $this->adminScope($request, $document),
        };
    }

    public function relatedRow(Request $request, array $row): array
    {
        $id = (string) ($row['document_id'] ?? $row['target_document_id'] ?? '');
        $document = $this->readDocument($id);
        if ($document !== null && $this->canRead($request, $document)) return $this->sanitizeReferences($request, $row);
        $safe = array_intersect_key($row, array_flip(['id', 'type', 'scope', 'block_id', 'version_label', 'is_current']));
        return $safe + ['title' => 'เอกสารจำกัดสิทธิ์', 'target_title' => 'เอกสารจำกัดสิทธิ์',
            'document_id' => '', 'target_document_id' => '', 'restricted' => true, 'has_file' => false];
    }

    /** Remove indirect identifiers as well as titles for targets outside this read context. */
    public function sanitizeReferences(Request $request, array $row): array
    {
        unset($row['co_editors'], $row['co_editor_usernames']);
        if (is_array($row['law_meta'] ?? null)) $row['law_meta'] = $this->sanitizeReferences($request, $row['law_meta']);
        $readable = function ($id) use ($request): bool {
            if (! is_string($id) || $id === '') return false;
            $document = $this->readDocument($id);
            return $document !== null && $this->canRead($request, $document);
        };
        if (! empty($row['parent_document_id']) && ! $readable($row['parent_document_id'])) $row['parent_document_id'] = null;
        foreach (['parent_document_ids', 'repealed_laws'] as $field) {
            if (is_array($row[$field] ?? null)) $row[$field] = array_values(array_filter($row[$field], $readable));
        }
        if (is_array($row['relations'] ?? null)) {
            $row['relations'] = array_map(fn ($relation) => is_array($relation) && ! empty($relation['target_document_id'])
                ? $this->relatedRow($request, $relation) : $relation, $row['relations']);
        }
        return $row;
    }

    /**
     * Admin document list scope: Super Admin sees everything, a user working in a unit sees only that unit's documents,
     * a signed-in user without an active unit sees only documents they uploaded or are responsible for.
     */
    public function inWorkingScope(Request $request, array $document): bool
    {
        return $this->adminScope($request, $document);
    }

    public function adminScope(Request $request, array $document): bool
    {
        $actor = $this->current->user($request);
        if ($actor === null) return false;
        if ($actor['is_super_admin'] ?? false) return true;
        if ($request->session()->get(CurrentUser::ACTING_MODE_KEY) === 'user') return false;
        $document = $this->documentMeta($document);
        $active = $request->hasSession() ? $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY) : null;
        if (is_string($active) && $active !== '') return ($this->units->find($active)['is_active'] ?? false)
            && ($document['owner_unit_code'] ?? null) === $active;
        $username = $actor['username'];
        return ($document['uploaded_by']['username'] ?? null) === $username
            || ($document['responsible']['username'] ?? null) === $username;
    }

    /** Public browsing never grants access to drafts, including to their owners. */
    public function publicScope(Request $request, array $document): bool
    {
        $document = $this->documentMeta($document);
        if (! self::isPublished($document)) return false;
        // Preserve the published/private permission rules even on a dev server.
        if (($document['access_scope'] ?? 'public') === 'public') return true;
        $actor = $this->current->user($request);
        if ($actor === null) return false;
        if ($actor['is_super_admin'] ?? false) return true;
        $active = $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY);
        $codes = is_string($active) && $active !== ''
            && in_array($active, array_column($actor['memberships'] ?? [], 'unit_code'), true)
            && ($this->units->find($active)['is_active'] ?? false) ? [$active] : [];
        $groupIds = (array) ($document['permission_group_ids'] ?? []);
        $hasRestrictions = $groupIds !== [] || (! array_key_exists('permission_group_ids', $document)
            && ((array) ($document['allowed_unit_codes'] ?? []) !== [] || (array) ($document['allowed_usernames'] ?? []) !== []));
        if (! $hasRestrictions) return true;
        if (in_array($document['owner_unit_code'] ?? '', $codes, true)) return true;
        $allowedUnits = [];
        $allowedUsers = [];
        if (array_key_exists('permission_group_ids', $document)) {
            foreach ($document['permission_group_ids'] ?? [] as $id) {
                $group = app(\App\Services\Permissions\PermissionStore::class)->findGroup($id);
                $allowedUnits = array_merge($allowedUnits, $group['unit_ids'] ?? []);
                $allowedUsers = array_merge($allowedUsers, $group['user_ids'] ?? []);
            }
        } else {
            $allowedUnits = $document['allowed_unit_codes'] ?? [];
            $allowedUsers = $document['allowed_usernames'] ?? [];
        }
        return array_intersect($codes, $allowedUnits) !== [] || in_array($actor['username'], $allowedUsers, true);
    }

    public function authorizeRead(Request $request, array $document): void
    {
        if ($this->canRead($request, $document)) return;
        abort($this->current->user($request) === null ? 401 : 403, 'เอกสารนี้จำกัดสิทธิ์การเข้าถึง');
    }

    /** Filter before pagination and aggregations so unreadable drafts never leak. */
    public function searchVisibilityFilter(Request $request): array
    {
        $ids = [];
        foreach ($this->documents->listLawMeta() as $row) {
            if ($this->visible($request, $row)) $ids[] = $row['document_id'];
        }
        return ['terms' => ['law_id' => $ids]];
    }

    private function membership(?array $user, string $code): ?array
    {
        foreach ($user['memberships'] ?? [] as $membership) {
            if ($membership['unit_code'] === $code) {
                return $membership;
            }
        }
        return null;
    }

    public function actsOnBehalf(Request $request, array $document): bool
    {
        $actor = $this->current->user($request);
        return $actor !== null && ($document['responsible']['username'] ?? null) !== $actor['username'];
    }

    private function innerCanAct(?array $actor, array $document, string $code): bool
    {
        if (($this->membership($actor, $code)['role'] ?? null) !== 'inner_admin') {
            return false;
        }
        $username = $document['responsible']['username'] ?? null;
        $owner = is_string($username) ? $this->users->find($username) : null;
        return $owner !== null
            && in_array($this->membership($owner, $code)['role'] ?? null, ['editor', 'user'], true);
    }

    public function allows(Request $request, string $action, array $document = []): bool
    {
        $actor = $this->current->user($request);
        if ($actor === null) {
            return false;
        }
        if ($actor['is_super_admin'] ?? false) {
            return true;
        }
        $document = $this->documentMeta($document);
        if ($action === 'cancel_esign') $action = 'esign';
        if ($action === 'transfer') {
            return false;
        }
        $active = $request->hasSession() ? $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY) : null;
        $code = $action === 'upload' ? $active : ($document['owner_unit_code'] ?? null);
        if (! is_string($code) || $code === '' || $active !== $code || ! ($this->units->find($code)['is_active'] ?? false)) {
            return false;
        }
        $membership = $this->membership($actor, $code);
        if ($action === 'upload') {
            return in_array('upload', MongoUserStore::effectiveActions($membership ?? []), true);
        }
        $own = ($document['responsible']['username'] ?? null) === $actor['username'];
        return ($own && in_array($action, MongoUserStore::effectiveActions($membership ?? []), true))
            || $this->innerCanAct($actor, $document, $code);
    }

    public function authorize(Request $request, string $action, array $document = []): void
    {
        abort_if($this->current->user($request) === null, 401, 'กรุณาเข้าสู่ระบบ');
        abort_unless($this->allows($request, $action, $document), 403, 'คุณไม่มีสิทธิ์ดำเนินการนี้กับเอกสารในหน่วยงานที่เลือก');
    }

    public function canManagePermissions(Request $request, array $document): bool
    {
        $actor = $this->current->user($request);
        if ($actor === null || $this->context($request) === 'public' || ! $this->adminScope($request, $document)) return false;
        if ($actor['is_super_admin'] ?? false) return true;
        $role = $this->current->role($actor, $request);
        if ($role === 'inner_admin') return true;
        $document = $this->documentMeta($document);
        return $role === 'outer_admin' && ($document['responsible']['username'] ?? null) === $actor['username']
            && $this->allows($request, 'publish', $document);
    }

    public function capabilities(Request $request, array $document): array
    {
        $can = [];
        foreach (['edit', 'delete', 'esign', 'publish', 'transfer', 'cancel_esign'] as $action) {
            $can[$action] = $this->allows($request, $action, $document);
        }
        if ($this->context($request) === 'public') $can = array_fill_keys(array_keys($can), false);
        $can['manage_permissions'] = $this->canManagePermissions($request, $document);
        $can['read'] = $this->canRead($request, $document);
        return $can;
    }

    /** Check every category in a mixed review payload; unchanged form fields need no extra grant. */
    public function actions(Request $request, string $documentId, string $suffix): array
    {
        if (in_array($suffix, ['export', 'export-pdf', 'export-word'], true)) {
            if (in_array($suffix, ['export-pdf', 'export-word'], true) && ($this->readDocument($documentId)['access_scope'] ?? 'public') === 'private') {
                abort_unless($this->canManagePermissions($request, $this->readDocument($documentId) ?? []), 403, 'ไม่มีสิทธิ์เปลี่ยนเอกสารจำกัดสิทธิ์เป็นสาธารณะ');
            }
            return ['publish'];
        }
        if ($suffix === 'transfer') return ['transfer'];
        if ($request->isMethod('DELETE') && $suffix === '') return ['delete'];
        if (str_starts_with($suffix, 'esign/')) return [$suffix === 'esign/cancel' ? 'cancel_esign' : 'esign'];
        if ($suffix === 'workflow-progress') return [(int) $request->input('completed_step') >= 6 || self::isPublished($this->readDocument($documentId) ?? []) ? 'publish' : 'edit'];
        if ($suffix !== 'document-review') return ['edit'];
        try {
            $review = $this->documents->getReviewDocument($documentId);
        } catch (\RuntimeException) {
            abort(404, 'ไม่พบข้อมูลเอกสารสำหรับแก้ไข');
        }
        if (! $this->canManagePermissions($request, $this->readDocument($documentId) ?? [])) {
            foreach (['permission_group_ids', 'access_scope'] as $key) {
                abort_if($request->has('law_meta.'.$key) && $request->input('law_meta.'.$key) != ($review['law_meta'][$key] ?? ($key === 'access_scope' ? 'public' : [])),
                    403, 'ไม่มีสิทธิ์กำหนดสิทธิ์ผู้อื่น');
            }
        }
        $actions = [];
        $publishFields = ['published_date', 'access_scope', 'permission_group_ids'];
        $publishing = $request->has('law_meta.published_date')
            && $request->input('law_meta.published_date') != ($review['law_meta']['published_date'] ?? null);
        foreach ((array) $request->input('law_meta', []) as $key => $value) {
            if (in_array($key, DocumentOwnership::FIELDS, true)) continue;
            if ($value != ($review['law_meta'][$key] ?? null)) {
                $actions[] = (in_array($key, $publishFields, true) || ($key === 'status' && $publishing)) ? 'publish' : 'edit';
            }
        }
        foreach ($request->except(['law_meta', '_token']) as $key => $value) {
            // prepareForValidation adds reset_to_generated=false; it has no effect.
            if ($key !== 'reset_to_generated' || $value) $actions[] = 'edit';
        }
        $keys = array_diff(array_keys((array) $request->input('law_meta', [])), DocumentOwnership::FIELDS);
        $fallback = $keys !== [] && array_diff($keys, $publishFields) === [] ? 'publish' : 'edit';
        return array_values(array_unique($actions ?: [$fallback]));
    }
}
