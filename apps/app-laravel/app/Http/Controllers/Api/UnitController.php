<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\CurrentUser;
use App\Services\Auth\MongoUserStore;
use App\Services\Buu\UmsFacultyService;
use App\Services\MasterData\UnitStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function __construct(private readonly UnitStore $units, private readonly CurrentUser $currentUser) {}

    public function index(Request $request, MongoUserStore $users): JsonResponse
    {
        $this->authorizeUser($request);

        $counts = [];
        foreach ($users->all() as $user) {
            foreach ((array) ($user['memberships'] ?? []) as $membership) {
                $code = $membership['unit_code'];
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        return response()->json(['items' => array_map(fn (array $unit) => array_merge($unit, [
            'member_count' => $counts[$unit['code']] ?? 0,
        ]), $this->units->all())]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $this->authorizeUser($request);
        $unit = $this->units->find($code);
        abort_if($unit === null, 404, 'ไม่พบหน่วยงาน');

        return response()->json(['item' => $unit]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeUser($request, true);

        return response()->json(['item' => $this->units->save($this->validateUnit($request, true))], 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $this->authorizeUser($request, true);

        return response()->json(['item' => $this->units->save($this->validateUnit($request), $code)]);
    }

    public function setActive(Request $request, string $code): JsonResponse
    {
        $this->authorizeUser($request, true);
        $data = $request->validate(['is_active' => ['required', 'boolean']], [
            'is_active.required' => 'กรุณาระบุสถานะการใช้งาน', 'is_active.boolean' => 'สถานะการใช้งานไม่ถูกต้อง',
        ]);

        return response()->json(['item' => $this->units->save($data, $code)]);
    }

    /** Retain codes and affiliations when removing a unit from use. */
    public function destroy(Request $request, string $code): JsonResponse
    {
        $this->authorizeUser($request, true);

        return response()->json(['item' => $this->units->save(['is_active' => false], $code)]);
    }

    public function syncFaculties(Request $request, UmsFacultyService $ums): JsonResponse
    {
        $this->authorizeUser($request, true);

        return response()->json($ums->sync($this->units));
    }

    public function members(Request $request, string $code, MongoUserStore $users): JsonResponse
    {
        $actor = $this->authorizeUser($request);
        abort_unless(($actor['is_super_admin'] ?? false) || in_array(MongoUserStore::unitRole($actor, $code), ['inner_admin', 'outer_admin'], true), 403, 'ไม่มีสิทธิ์ดูสมาชิกหน่วยงานนี้');
        abort_if($this->units->find($code) === null, 404, 'ไม่พบหน่วยงาน');
        return $this->membersResponse($code, $users);
    }

    private function membersResponse(string $code, MongoUserStore $users): JsonResponse
    {
        $items = [];
        foreach ($users->all() as $user) {
            foreach ((array) ($user['memberships'] ?? []) as $membership) {
                if ($membership['unit_code'] === $code) {
                    $items[] = array_merge($membership, [
                        'username' => $user['username'], 'name' => $user['name'] ?? null, 'email' => $user['email'] ?? null,
                        'is_disabled' => (bool) ($user['is_disabled'] ?? false), 'actions' => MongoUserStore::effectiveActions($membership),
                    ]);
                }
            }
        }

        return response()->json(['items' => $items]);
    }

    public function updateMember(Request $request, string $code, string $username, MongoUserStore $users, \App\Services\Buu\UmsPersonService $ums): JsonResponse
    {
        $actor = $this->authorizeUser($request);
        $this->authorizeMemberWrite($actor, $code);
        $data = $request->validate([
            'role' => ['required', 'string', 'in:inner_admin,outer_admin,editor,user'],
            'actions' => ['sometimes', 'array'], 'actions.*' => ['string', 'distinct', 'in:upload,edit,delete,esign,publish'],
        ], [
            'required' => 'กรุณาระบุบทบาทสมาชิก', 'string' => 'รูปแบบข้อมูลไม่ถูกต้อง', 'in' => 'บทบาทหรือสิทธิ์ไม่ถูกต้อง',
            'array' => 'รูปแบบรายการสิทธิ์ไม่ถูกต้อง', 'distinct' => 'รายการสิทธิ์ต้องไม่ซ้ำกัน',
        ]);
        $data['actions'] = $data['actions'] ?? [];
        abort_if($this->units->find($code) === null, 404, 'ไม่พบหน่วยงาน');
        $member = $users->changeMembership($actor, $code, $username, $data, $ums->directoryPerson($username));

        return response()->json(['member' => $member]);
    }

    public function updateMembers(Request $request, string $code, MongoUserStore $users, \App\Services\Buu\UmsPersonService $ums): JsonResponse
    {
        $actor = $this->authorizeUser($request);
        $this->authorizeMemberWrite($actor, $code);
        abort_if($this->units->find($code) === null, 404, 'ไม่พบหน่วยงาน');
        $data = $request->validate(['changes' => ['required', 'array', 'min:1']], [
            'required' => 'กรุณาระบุรายการเปลี่ยนแปลง', 'array' => 'รูปแบบรายการเปลี่ยนแปลงไม่ถูกต้อง', 'min' => 'กรุณาระบุอย่างน้อยหนึ่งรายการ',
        ]);
        $prepared = [];
        foreach ($data['changes'] as $row) {
            $username = is_array($row) && is_string($row['username'] ?? null) ? MongoUserStore::key($row['username']) : '(ไม่ระบุชื่อผู้ใช้)';
            $validator = \Illuminate\Support\Facades\Validator::make(is_array($row) ? $row : [], [
                'username' => ['required', 'string', 'max:255'],
                'remove' => ['sometimes', 'boolean'],
                'role' => ['required_unless:remove,true', 'string', 'in:inner_admin,outer_admin,editor,user'],
                'actions' => ['sometimes', 'array'], 'actions.*' => ['string', 'distinct', 'in:upload,edit,delete,esign,publish'],
            ]);
            abort_if($validator->fails(), 422, "ผู้ใช้ {$username}: บทบาทหรือรายการสิทธิ์ไม่ถูกต้อง");
            abort_if(isset($prepared[$username]), 422, "ผู้ใช้ {$username}: รายการชื่อผู้ใช้ซ้ำกัน");
            $validated = $validator->validated();
            $change = ($validated['remove'] ?? false) ? null : ['role' => $validated['role'], 'actions' => $validated['actions'] ?? []];
            $user = $users->find($username);
            $person = $user === null && $change !== null ? $ums->directoryPerson($username) : null;
            try {
                MongoUserStore::validateMembershipChange($actor, $code, $user, $change, $person);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                abort($e->getStatusCode() === 403 ? 403 : 422, "ผู้ใช้ {$username}: {$e->getMessage()}");
            }
            $prepared[$username] = ['change' => $change, 'person' => $person];
        }
        foreach ($prepared as $username => $row) {
            $users->changeMembership($actor, $code, $username, $row['change'], $row['person']);
        }

        // The actor may have changed their own role, but can still receive this save result.
        return $this->membersResponse($code, $users);
    }

    public function removeMember(Request $request, string $code, string $username, MongoUserStore $users): \Illuminate\Http\Response
    {
        $actor = $this->authorizeUser($request, true);
        abort_if($this->units->find($code) === null, 404, 'ไม่พบหน่วยงาน');
        $users->changeMembership($actor, $code, $username, null);

        return response()->noContent();
    }

    private function authorizeUser(Request $request, bool $super = false): array
    {
        $actor = $this->currentUser->user($request);
        abort_if($actor === null, 401, 'กรุณาเข้าสู่ระบบ');
        abort_if($super && ! ($actor['is_super_admin'] ?? false), 403, 'เฉพาะ Super Admin เท่านั้นที่จัดการรายการนี้ได้');

        return $actor;
    }

    private function authorizeMemberWrite(array $actor, string $code): void
    {
        abort_unless(($actor['is_super_admin'] ?? false) || MongoUserStore::unitRole($actor, $code) === 'inner_admin', 403, 'ไม่มีสิทธิ์แก้ไขสมาชิกหน่วยงานนี้');
    }

    private function validateUnit(Request $request, bool $create = false): array
    {
        return $request->validate([
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$create ? 'required' : 'sometimes', 'string', 'in:faculty,division,department,law_subunit'],
            'parent_code' => ['nullable', 'string', 'regex:/^DEP[0-9]{3}$/'],
            'ums_fac_id' => ['nullable', 'string', 'max:64'], 'ums_dep_id' => ['nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0'],
        ], [
            'required' => 'กรุณาระบุชื่อและชนิดหน่วยงาน', 'string' => 'รูปแบบข้อมูลหน่วยงานไม่ถูกต้อง',
            'max' => 'ข้อมูลหน่วยงานยาวเกินกำหนด', 'in' => 'ชนิดหน่วยงานไม่ถูกต้อง', 'regex' => 'รหัสหน่วยงานแม่ไม่ถูกต้อง',
            'boolean' => 'สถานะการใช้งานไม่ถูกต้อง', 'integer' => 'ลำดับต้องเป็นจำนวนเต็ม', 'min' => 'ลำดับต้องไม่ติดลบ',
        ]);
    }
}
