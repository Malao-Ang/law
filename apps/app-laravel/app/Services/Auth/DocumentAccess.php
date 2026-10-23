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
        $id = $document['document_id'] ?? null;
        $review = is_string($id) ? $this->blobs->read('review', $id) : null;
        return array_merge($review['law_meta'] ?? [], $document['law_meta'] ?? [], $document);
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
        if (! config('auth_ldap.enforced')) return true;
        $document = $this->documentMeta($document);
        $actor = $this->current->user($request);
        if ($actor['is_super_admin'] ?? false) return true;
        $published = array_key_exists('is_published', $document) ? $document['is_published'] === true : self::isPublished($document);
        if ($published && ($document['access_scope'] ?? 'public') === 'public') return true;
        if ($actor === null) return false;
        $username = $actor['username'];
        $responsible = $document['responsible']['username'] ?? $document['responsible_username'] ?? null;
        $coEditors = $document['co_editor_usernames'] ?? array_column($document['co_editors'] ?? [], 'username');
        if ($responsible === $username || in_array($username, $coEditors, true)) return true;
        $code = (string) ($document['owner_unit_code'] ?? '');
        if (! $published) {
            $document['responsible'] = ['username' => $responsible];
            return $request->hasSession() && $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY) === $code
                && ($this->units->find($code)['is_active'] ?? false) && $this->innerCanAct($actor, $document, $code);
        }
        $codes = array_column($actor['memberships'] ?? [], 'unit_code');
        if (in_array($code, $codes, true)) return true;
        $allowedUnits = $document['allowed_unit_codes'] ?? [];
        $allowedUsers = $document['allowed_usernames'] ?? [];
        if (! array_key_exists('allowed_unit_codes', $document)) {
            foreach ($document['permission_group_ids'] ?? [] as $id) {
                $group = app(\App\Services\Permissions\PermissionStore::class)->findGroup($id);
                $allowedUnits = array_merge($allowedUnits, $group['unit_ids'] ?? []);
                $allowedUsers = array_merge($allowedUsers, $group['user_ids'] ?? []);
            }
        }
        return array_intersect($codes, $allowedUnits) !== [] || in_array($username, $allowedUsers, true);
    }

    public function authorizeRead(Request $request, array $document): void
    {
        if ($this->canRead($request, $document)) return;
        abort($this->current->user($request) === null ? 401 : 403, 'เอกสารนี้จำกัดสิทธิ์การเข้าถึง');
    }

    /** Filter before pagination and aggregations so unreadable drafts never leak. */
    public function searchVisibilityFilter(Request $request): array
    {
        $actor = $this->current->user($request);
        if ($actor['is_super_admin'] ?? false) return ['match_all' => (object) []];
        $clauses = [['term' => ['is_published' => true]]];
        if ($actor !== null) {
            $clauses[] = ['term' => ['responsible_username' => $actor['username']]];
            $clauses[] = ['term' => ['co_editor_usernames' => $actor['username']]];
            $code = $request->hasSession() ? $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY) : null;
            if (is_string($code) && ($this->units->find($code)['is_active'] ?? false)
                && ($this->membership($actor, $code)['role'] ?? null) === 'inner_admin') {
                $owners = [];
                foreach ($this->users->all() as $owner) {
                    if (! ($owner['is_super_admin'] ?? false)
                        && in_array($this->membership($owner, $code)['role'] ?? null, ['editor', 'user'], true)) $owners[] = $owner['username'];
                }
                if ($owners !== []) $clauses[] = ['bool' => ['filter' => [
                    ['term' => ['owner_unit_code' => $code]], ['terms' => ['responsible_username' => $owners]],
                ]]];
            }
        }
        return ['bool' => ['should' => $clauses, 'minimum_should_match' => 1]];
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
        return $owner !== null && ! ($owner['is_super_admin'] ?? false)
            && in_array($this->membership($owner, $code)['role'] ?? null, ['editor', 'user'], true);
    }

    public function allows(Request $request, string $action, array $document = []): bool
    {
        if (! config('auth_ldap.enforced')) {
            return true;
        }
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
        if ($action === 'manage_co_editors') {
            return ($membership['role'] ?? null) === 'inner_admin';
        }
        if ($action === 'upload') {
            return in_array('upload', MongoUserStore::effectiveActions($membership ?? []), true);
        }
        $own = ($document['responsible']['username'] ?? null) === $actor['username'];
        foreach ($document['co_editors'] ?? [] as $coEditor) {
            if (($coEditor['username'] ?? null) === $actor['username']
                && in_array($action, ['edit', 'delete', 'esign', 'publish'], true)
                && in_array($action, $coEditor['actions'] ?? [], true)
                && in_array($action, MongoUserStore::effectiveActions($membership ?? []), true)) {
                return true;
            }
        }
        return ($own && in_array($action, MongoUserStore::effectiveActions($membership ?? []), true))
            || $this->innerCanAct($actor, $document, $code);
    }

    public function authorize(Request $request, string $action, array $document = []): void
    {
        if (! config('auth_ldap.enforced')) {
            return;
        }
        abort_if($this->current->user($request) === null, 401, 'กรุณาเข้าสู่ระบบ');
        abort_unless($this->allows($request, $action, $document), 403, 'คุณไม่มีสิทธิ์ดำเนินการนี้กับเอกสารในหน่วยงานที่เลือก');
    }

    public function capabilities(Request $request, array $document): array
    {
        $can = [];
        foreach (['edit', 'delete', 'esign', 'publish', 'transfer', 'cancel_esign', 'manage_co_editors'] as $action) {
            $can[$action] = $this->allows($request, $action, $document);
        }
        $can['read'] = $this->canRead($request, $document);
        return $can;
    }

    /** Check every category in a mixed review payload; unchanged form fields need no extra grant. */
    public function actions(Request $request, string $documentId, string $suffix): array
    {
        if (in_array($suffix, ['export', 'export-pdf', 'export-word'], true)) return ['publish'];
        if ($suffix === 'transfer') return ['transfer'];
        if (str_starts_with($suffix, 'co-editors/')) return ['manage_co_editors'];
        if ($request->isMethod('DELETE') && $suffix === '') return ['delete'];
        if (str_starts_with($suffix, 'esign/')) return [$suffix === 'esign/cancel' ? 'cancel_esign' : 'esign'];
        if ($suffix === 'workflow-progress') return [(int) $request->input('completed_step') >= 6 ? 'publish' : 'edit'];
        if ($suffix !== 'document-review') return ['edit'];
        try {
            $review = $this->documents->getReviewDocument($documentId);
        } catch (\RuntimeException) {
            abort(404, 'ไม่พบข้อมูลเอกสารสำหรับแก้ไข');
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
