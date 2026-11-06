<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\CurrentUser;
use App\Services\Auth\MongoUserStore;
use App\Services\Buu\BuuApiException;
use App\Services\Buu\UmsPersonService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    public function __invoke(Request $request, CurrentUser $currentUser, MongoUserStore $users, UmsPersonService $ums): JsonResponse
    {
        $actor = $currentUser->user($request);
        abort_if($actor === null, 401, 'กรุณาเข้าสู่ระบบ');
        $adminMemberships = array_filter((array) ($actor['memberships'] ?? []), fn (array $member): bool => in_array($member['role'], ['inner_admin', 'outer_admin'], true));
        abort_unless(($actor['is_super_admin'] ?? false) || $adminMemberships !== [], 403, 'ไม่มีสิทธิ์ค้นหาผู้ใช้');
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']], [
            'q.required' => 'กรุณาระบุชื่อหรือชื่อผู้ใช้ที่ต้องการค้นหา', 'q.string' => 'คำค้นหาไม่ถูกต้อง',
            'q.min' => 'กรุณาระบุคำค้นหาอย่างน้อย 2 ตัวอักษร', 'q.max' => 'คำค้นหายาวเกินกำหนด',
        ]);
        $query = mb_strtolower(trim($data['q']));
        $people = [];
        foreach ($users->all() as $user) {
            if (! empty($user['last_login_at']) && str_contains(mb_strtolower(($user['username'] ?? '').' '.($user['name'] ?? '')), $query)) {
                $people[$user['username']] = $this->summary($user);
                if (count($people) >= 50) {
                    break;
                }
            }
        }
        $unavailable = false;
        try {
            foreach ($ums->searchPeople($data['q']) as $person) {
                $people[$person['username']] ??= $this->summary($person);
            }
        } catch (BuuApiException|ConnectionException) {
            $unavailable = true;
        }

        return response()->json(['items' => array_slice(array_values($people), 0, 50), 'ums_unavailable' => $unavailable,
            'message' => $unavailable ? 'ไม่สามารถค้นหาจาก UMS ได้ แสดงเฉพาะผู้ใช้ที่เคยเข้าสู่ระบบ' : null]);
    }

    private function summary(array $person): array
    {
        return array_intersect_key($person, array_flip(['username', 'name', 'email', 'fac_id', 'fac_name', 'dep_id', 'dep_name']));
    }
}
