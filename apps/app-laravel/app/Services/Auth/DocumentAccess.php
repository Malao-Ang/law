<?php

namespace App\Services\Auth;

use App\Services\MasterData\UnitStore;
use App\Services\ReviewStore;
use Illuminate\Http\Request;

/** All document action decisions use current memberships, never upload-time roles. */
class DocumentAccess
{
    public function __construct(private readonly CurrentUser $current, private readonly MongoUserStore $users,
        private readonly UnitStore $units, private readonly ReviewStore $documents) {}

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
        if ($action === 'transfer') {
            return false;
        }
        $active = $request->hasSession() ? $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY) : null;
        $code = $action === 'upload' ? $active : ($document['owner_unit_code'] ?? null);
        if (! is_string($code) || $code === '' || $active !== $code || ! ($this->units->find($code)['is_active'] ?? false)) {
            return false;
        }
        $membership = $this->membership($actor, $code);
        // The sender may cancel even after their role/actions change (rule 6).
        if ($action === 'cancel_esign') {
            return ($document['esign']['sent_by'] ?? null) === $actor['username']
                || $this->innerCanAct($actor, $document, $code);
        }
        if ($action === 'upload') {
            return in_array('upload', MongoUserStore::effectiveActions($membership ?? []), true);
        }
        $own = ($document['responsible']['username'] ?? null) === $actor['username'];
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
        foreach (['edit', 'delete', 'esign', 'publish', 'transfer', 'cancel_esign'] as $action) {
            $can[$action] = $this->allows($request, $action, $document);
        }
        return $can;
    }

    /** Check every category in a mixed review payload; unchanged form fields need no extra grant. */
    public function actions(Request $request, string $documentId, string $suffix): array
    {
        if ($suffix === 'export') return ['publish'];
        if ($suffix === 'transfer') return ['transfer'];
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
