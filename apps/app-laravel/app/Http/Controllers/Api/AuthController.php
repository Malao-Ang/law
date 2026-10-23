<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\CurrentUser;
use App\Services\Auth\MongoUserStore;
use App\Services\Buu\BuuApiException;
use App\Services\Buu\BuuLdapService;
use App\Services\Buu\UmsPersonService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function __construct(
        private readonly BuuLdapService $ldap,
        private readonly UmsPersonService $ums,
        private readonly MongoUserStore $users,
        private readonly CurrentUser $currentUser,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'username.required' => 'กรุณากรอกชื่อผู้ใช้',
            'password.required' => 'กรุณากรอกรหัสผ่าน',
            'username.max' => 'ชื่อผู้ใช้ยาวเกินกำหนด',
            'password.max' => 'รหัสผ่านยาวเกินกำหนด',
        ]);

        if (! $request->hasSession()) {
            return response()->json(['message' => 'ไม่สามารถเริ่มเซสชันได้ กรุณาโหลดหน้าเว็บใหม่'], 419);
        }

        $username = MongoUserStore::key($data['username']);
        $throttleKey = 'auth-login|'.$username.'|'.$request->ip();
        $maxAttempts = (int) config('auth_ldap.login_max_attempts', 5);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $this->audit($username, 'throttled', $request);

            return response()
                ->json(['message' => 'พยายามเข้าสู่ระบบบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($throttleKey));
        }
        RateLimiter::hit($throttleKey, (int) config('auth_ldap.login_decay_seconds', 60));

        try {
            $result = $this->ldap->login($username, $data['password']);
        } catch (BuuApiException $exception) {
            if ($this->isInvalidCredentials($exception)) {
                $this->audit($username, 'invalid_credentials', $request);

                return response()->json(['message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], 401);
            }

            return $this->unavailable($username, $request);
        } catch (ConnectionException) {
            return $this->unavailable($username, $request);
        }

        $fullname = is_string($result['fullname'] ?? null) ? trim($result['fullname']) : '';
        if ($result === []) {
            $this->audit($username, 'invalid_credentials', $request);

            return response()->json(['message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], 401);
        }

        $person = $this->ums->findByLogin($username, $fullname);
        $email = is_string($result['email'] ?? null) ? trim($result['email']) : '';

        $user = $this->users->upsertFromLogin(
            $username,
            ['name' => $fullname, 'email' => $email],
            $person,
            in_array($username, (array) config('auth_ldap.super_admins', []), true),
        );

        if (($user['is_disabled'] ?? false) === true) {
            $this->audit($username, 'disabled', $request);

            return response()->json(['message' => 'บัญชีนี้ถูกปิดการใช้งาน'], 403);
        }

        RateLimiter::clear($throttleKey);
        $this->currentUser->login($request, $username);
        $this->audit($username, 'success', $request);

        return response()->json($this->currentUser->payload($user, $request));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->currentUser->user($request);
        if ($user === null) {
            return response()->json([
                'message' => 'กรุณาเข้าสู่ระบบ',
                'auth_enforced' => (bool) config('auth_ldap.enforced'),
            ], 401);
        }

        return response()->json($this->currentUser->payload($user, $request));
    }

    public function activeUnit(Request $request, \App\Services\MasterData\UnitStore $units): JsonResponse
    {
        $user = $this->currentUser->user($request);
        abort_if($user === null, 401, 'กรุณาเข้าสู่ระบบ');
        $data = $request->validate(['unit_code' => ['required', 'string', 'regex:/^DEP[0-9]{3}$/']], [
            'unit_code.required' => 'กรุณาเลือกหน่วยงาน',
            'unit_code.string' => 'รหัสหน่วยงานไม่ถูกต้อง',
            'unit_code.regex' => 'รหัสหน่วยงานไม่ถูกต้อง',
        ]);
        abort_unless(($user['is_super_admin'] ?? false) || in_array($data['unit_code'], array_column((array) ($user['memberships'] ?? []), 'unit_code'), true), 403, 'ไม่มีสิทธิ์เลือกหน่วยงานนี้');
        $unit = $units->find($data['unit_code']);
        abort_if($unit === null || ! $unit['is_active'], 422, 'ไม่พบหน่วยงานหรือหน่วยงานถูกปิดใช้งาน');
        $request->session()->put(CurrentUser::ACTIVE_UNIT_KEY, $unit['code']);

        return response()->json($this->currentUser->payload($user, $request));
    }

    public function logout(Request $request): Response
    {
        $this->currentUser->logout($request);

        return response()->noContent();
    }

    private function isInvalidCredentials(BuuApiException $exception): bool
    {
        $body = $exception->responseBody;

        return $exception->statusCode === 400
            || (is_array($body) && ($body['status'] ?? null) === 'fail');
    }

    private function unavailable(string $username, Request $request): JsonResponse
    {
        $this->audit($username, 'unavailable', $request);

        return response()->json(['message' => 'ระบบยืนยันตัวตนของมหาวิทยาลัยไม่พร้อมใช้งาน'], 503);
    }

    private function audit(string $username, string $result, Request $request): void
    {
        Log::channel('auth')->info('auth.login', [
            'username' => $username,
            'result' => $result,
            'ip' => $request->ip(),
        ]);
    }
}
