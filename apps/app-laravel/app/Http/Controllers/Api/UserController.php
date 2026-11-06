<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\CurrentUser;
use App\Services\Auth\DocumentAccess;
use App\Services\Auth\MongoUserStore;
use App\Services\MasterData\UnitStore;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly CurrentUser $current, private readonly MongoUserStore $users,
        private readonly UnitStore $units, private readonly DocumentAccess $access, private readonly ReviewStore $documents) {}

    private function scope(Request $request): ?string
    {
        $actor = $this->current->user($request);
        abort_if($actor === null, 401, 'กรุณาเข้าสู่ระบบ');
        if ($actor['is_super_admin'] ?? false) return null;
        abort_unless(in_array($this->current->role($actor, $request), ['inner_admin', 'outer_admin'], true), 403, 'ไม่มีสิทธิ์จัดการผู้ใช้');
        $code = $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY);
        abort_unless(is_string($code) && ($this->units->find($code)['is_active'] ?? false), 403, 'กรุณาเลือกหน่วยงานที่ใช้งาน');
        return $code;
    }

    private function summary(array $user, ?string $scope): array
    {
        $profile = array_intersect_key($user, array_flip(['username', 'name', 'email', 'position', 'fac_id', 'fac_name', 'dep_id', 'dep_name', 'last_login_at']));
        $profile['is_super_admin'] = (bool) ($user['is_super_admin'] ?? false)
            || in_array($user['username'], (array) config('auth_ldap.super_admins', []), true);
        $profile['is_disabled'] = (bool) ($user['is_disabled'] ?? false);
        $profile['memberships'] = array_values(array_map(function ($membership) {
            $membership['unit_name'] = $this->units->find($membership['unit_code'])['name'] ?? $membership['unit_code'];
            $membership['actions'] = MongoUserStore::effectiveActions($membership);
            return $membership;
        }, array_filter($user['memberships'] ?? [], fn ($membership) => $scope === null || $membership['unit_code'] === $scope)));
        return $profile;
    }

    private function findVisible(string $username, ?string $scope): array
    {
        $user = $this->users->find($username);
        abort_if($user === null || empty($user['last_login_at'])
            || ($scope !== null && MongoUserStore::unitRole($user, $scope) === null), 404, 'ไม่พบผู้ใช้');
        return $user;
    }

    public function index(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:200'], 'unit' => ['nullable', 'string', 'max:128'],
            'role' => ['nullable', 'string', 'in:super_admin,inner_admin,outer_admin,editor,user'],
            'status' => ['nullable', 'string', 'in:active,disabled'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'string' => 'รูปแบบตัวกรองไม่ถูกต้อง', 'max' => 'ค่าตัวกรองเกินขนาดที่กำหนด',
            'in' => 'บทบาทหรือสถานะที่เลือกไม่ถูกต้อง', 'integer' => 'เลขหน้าและจำนวนรายการต้องเป็นจำนวนเต็ม',
            'min' => 'เลขหน้าและจำนวนรายการต้องไม่น้อยกว่าหนึ่ง',
        ]);
        if (! empty($input['unit'])) {
            abort_if($this->units->find($input['unit']) === null, 422, 'ไม่พบหน่วยงาน');
            abort_if($scope !== null && $input['unit'] !== $scope, 403, 'ไม่มีสิทธิ์ดูผู้ใช้หน่วยงานนี้');
        }
        $items = [];
        $query = mb_strtolower(trim($input['q'] ?? ''));
        foreach ($this->users->all() as $user) {
            if (empty($user['last_login_at']) || ($scope !== null && MongoUserStore::unitRole($user, $scope) === null)) continue;
            $item = $this->summary($user, $scope);
            if ($query !== '' && ! str_contains(mb_strtolower(($item['name'] ?? '').' '.$item['username']), $query)) continue;
            if (! empty($input['status']) && $item['is_disabled'] !== ($input['status'] === 'disabled')) continue;
            $memberships = array_filter($item['memberships'], fn ($m) => empty($input['unit']) || $m['unit_code'] === $input['unit']);
            if (! empty($input['unit']) && $memberships === []) continue;
            if (! empty($input['role'])) {
                $matches = $input['role'] === 'super_admin' ? $item['is_super_admin']
                    : (in_array($input['role'], array_column($memberships, 'role'), true) || ($input['role'] === 'user' && $item['memberships'] === [] && ! $item['is_super_admin']));
                if (! $matches) continue;
            }
            $items[] = $item;
        }
        usort($items, fn ($a, $b) => strnatcasecmp($a['name'] ?? $a['username'], $b['name'] ?? $b['username']) ?: strcmp($a['username'], $b['username']));
        $total = count($items);
        $perPage = (int) ($input['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($input['page'] ?? 1), $lastPage);
        return response()->json(['items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage,
            'units' => array_values(array_filter($this->units->all(), fn ($unit) => $scope === null || $unit['code'] === $scope))]);
    }

    public function show(Request $request, string $username): JsonResponse
    {
        $scope = $this->scope($request);
        $user = $this->findVisible($username, $scope);
        $owned = [];
        foreach ($this->documents->listLawMeta() as $document) {
            if (($document['responsible']['username'] ?? null) !== $user['username'] || ! $this->access->adminScope($request, $document)) continue;
            $owned[] = ['document_id' => $document['document_id'], 'title' => $document['title']];
        }
        return response()->json(['user' => $this->summary($user, $scope), 'documents' => $owned, 'document_count' => count($owned)]);
    }

    public function disabled(Request $request, string $username): JsonResponse
    {
        $this->scope($request);
        $actor = $this->current->user($request);
        abort_unless($actor['is_super_admin'] ?? false, 403, 'เฉพาะ Super Admin เท่านั้นที่ปิดหรือเปิดผู้ใช้ได้');
        $this->findVisible($username, null);
        $input = $request->validate(['is_disabled' => ['present', 'boolean']], [
            'present' => 'กรุณาระบุสถานะการใช้งาน', 'boolean' => 'สถานะการใช้งานไม่ถูกต้อง',
        ]);
        $user = $this->users->setDisabled($actor, $username, (bool) $input['is_disabled']);
        return response()->json(['user' => $this->summary($user, null)]);
    }
}
