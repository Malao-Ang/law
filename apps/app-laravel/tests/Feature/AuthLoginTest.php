<?php

namespace Tests\Feature;

use App\Services\Auth\MongoUserStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    private const CITIZEN_ID = '1234567890123';

    private const PASSWORD = 'S3cret-pass-word';

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buu.domain' => 'https://kong.test',
            'buu.client_id' => 'cid',
            'buu.client_secret' => 'csecret',
            'buu.endpoints.ldap.login.provision_key' => 'pk-ldap',
            'buu.endpoints.ums.person.provision_key' => 'pk-ums',
            'auth_ldap.super_admins' => ['lib06002'],
            'auth_ldap.enforced' => false,
        ]);
        Cache::flush();
        RateLimiter::clear('auth-login|alice|127.0.0.1');

        $this->logFile = tempnam(sys_get_temp_dir(), 'authlog');
        config([
            'logging.default' => 'auth_test',
            'logging.channels.auth_test' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
            'logging.channels.auth' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    /** @param array<string, mixed> $headers */
    private function spa(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withHeaders(['Referer' => 'http://localhost:8500/login'])->json($method, $uri, $data);
    }

    private function fakeKong(string $username = 'alice', string $fullname = 'นางสาวสมหญิง ใจดี', bool $ums = true): void
    {
        $token = ['access_token' => 'tok', 'refresh_token' => 'rtok', 'expires_in' => 7200, 'token_type' => 'bearer'];

        $people = $ums ? [[
            'psn_id' => 'P1', 'prf_nameth' => 'นางสาว', 'psn_fnameth' => 'สมหญิง', 'psn_lnameth' => 'ใจดี',
            'psn_retire' => 'N', 'liw_nameth' => 'นักวิชาการ', 'fac_id' => '02', 'fac_nameth' => 'สำนักงานอธิการบดี',
            'dep_id' => '0201', 'dep_nameth' => 'กองกฎหมาย', 'psn_email' => 'alice@buu.ac.th', 'usid' => 'U1',
            'uslogin' => strtoupper($username), 'psn_idcard' => self::CITIZEN_ID,
        ], [
            'psn_id' => 'P2', 'psn_fnameth' => 'สมหญิง', 'psn_lnameth' => 'ใจดี', 'uslogin' => 'someoneelse',
            'fac_id' => '09', 'psn_idcard' => '9999999999999',
        ]] : [];

        Http::fake([
            'kong.test/*/oauth2/token' => Http::response($token),
            'kong.test/service-api/ldap.loginBuu' => Http::response([
                'status' => 'success',
                'result' => ['fullname' => $fullname, 'email' => $username.'@buu.ac.th', 'facultyname' => 'x', 'citizenid' => self::CITIZEN_ID],
            ]),
            'kong.test/service-api/ums.SPerson' => Http::response(['status' => 'success', 'result' => $people]),
        ]);
    }

    public function test_first_login_creates_user_with_role_user_and_ums_profile(): void
    {
        $this->fakeKong();

        $this->spa('POST', '/api/auth/login', ['username' => 'Alice', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('role', 'user')
            ->assertJsonPath('user.username', 'alice')
            ->assertJsonPath('user.fac_id', '02')
            ->assertJsonPath('user.dep_name', 'กองกฎหมาย')
            ->assertJsonPath('user.position', 'นักวิชาการ')
            ->assertJsonPath('user.retired', false)
            ->assertJsonPath('auth_enforced', false);

        $doc = app(MongoUserStore::class)->find('alice');
        $this->assertArrayNotHasKey('role', $doc);
        $this->assertSame('user', $doc['memberships'][0]['role']);
        $this->assertFalse($doc['is_super_admin']);
        $this->assertFalse($doc['is_disabled']);
        $this->assertSame('U1', $doc['usid']);
        $this->assertNotNull($doc['last_login_at']);
    }

    public function test_user_document_and_logs_never_contain_password_or_citizen_id(): void
    {
        $this->fakeKong();

        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => self::PASSWORD])->assertOk();

        $doc = app(MongoUserStore::class)->find('alice');
        $this->assertEqualsCanonicalizing([
            'username', 'name', 'email', 'usid', 'psn_id', 'fac_id', 'fac_name', 'dep_id', 'dep_name',
            'position', 'retired', 'is_disabled', 'created_at', 'updated_at', 'last_login_at',
            'ums_fac_id', 'ums_dep_id', 'memberships', 'is_super_admin',
        ], array_keys($doc));

        $stored = json_encode($doc, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::CITIZEN_ID, $stored);
        $this->assertStringNotContainsString(self::PASSWORD, $stored);

        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('auth.login', $log);
        $this->assertStringNotContainsString(self::PASSWORD, $log);
        $this->assertStringNotContainsString(self::CITIZEN_ID, $log);

        $this->spa('GET', '/api/auth/me')->assertOk()->assertJsonMissingPath('user.citizenid');
    }

    public function test_configured_super_admin_gets_super_admin_role_on_every_login(): void
    {
        $this->fakeKong('lib06002', 'นายผู้ดูแล ระบบ', false);

        $this->spa('POST', '/api/auth/login', ['username' => 'lib06002', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('role', 'super_admin');

        $this->assertTrue(app(MongoUserStore::class)->find('lib06002')['is_super_admin']);
    }

    public function test_login_does_not_overwrite_existing_role_from_ums(): void
    {
        $this->fakeKong();
        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => self::PASSWORD])->assertOk();

        app('mongo.blob.users')->withLock('user', 'alice', function (array &$data): void {
            $data['memberships'][0]['role'] = 'editor';
        });
        Cache::flush();
        RateLimiter::clear('auth-login|alice|127.0.0.1');

        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('role', 'editor');
    }

    public function test_user_missing_from_ums_logs_in_without_faculty(): void
    {
        $this->fakeKong('alice', 'นายไม่มี ในระบบ', false);

        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('role', 'user')
            ->assertJsonPath('user.fac_id', null)
            ->assertJsonPath('user.dep_id', null)
            ->assertJsonPath('user.name', 'นายไม่มี ในระบบ');
    }

    public function test_ums_failure_does_not_block_login(): void
    {
        Http::fake([
            'kong.test/*/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
            'kong.test/service-api/ldap.loginBuu' => Http::response(['status' => 'success', 'result' => ['fullname' => 'สมชาย ใจดี', 'email' => 'a@buu.ac.th']]),
            'kong.test/service-api/ums.SPerson' => Http::response(['status' => 'error'], 500),
        ]);

        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.fac_id', null);
    }

    public function test_wrong_password_returns_401_thai_message(): void
    {
        Http::fake([
            'kong.test/*/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
            'kong.test/service-api/ldap.loginBuu' => Http::response(['status' => 'fail', 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], 400),
        ]);

        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');

        $this->assertNull(app(MongoUserStore::class)->find('alice'));
        $this->spa('GET', '/api/auth/me')->assertStatus(401);
        $this->assertStringContainsString('invalid_credentials', (string) file_get_contents($this->logFile));
    }

    public function test_kong_server_error_returns_503(): void
    {
        Http::fake([
            'kong.test/*/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
            'kong.test/service-api/ldap.loginBuu' => Http::response('boom', 502),
        ]);

        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => 'x'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'ระบบยืนยันตัวตนของมหาวิทยาลัยไม่พร้อมใช้งาน');
    }

    public function test_kong_unreachable_returns_503(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => 'x'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'ระบบยืนยันตัวตนของมหาวิทยาลัยไม่พร้อมใช้งาน');
    }

    public function test_disabled_account_gets_403_and_no_session(): void
    {
        $this->fakeKong();
        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => self::PASSWORD])->assertOk();
        app('mongo.blob.users')->withLock('user', 'alice', function (array &$data): void {
            $data['is_disabled'] = true;
        });
        $this->spa('POST', '/api/auth/logout')->assertNoContent();
        RateLimiter::clear('auth-login|alice|127.0.0.1');

        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertJsonPath('message', 'บัญชีนี้ถูกปิดการใช้งาน');
        $this->spa('GET', '/api/auth/me')->assertStatus(401);
    }

    public function test_login_is_rate_limited_per_username_and_ip(): void
    {
        Http::fake([
            'kong.test/*/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
            'kong.test/service-api/ldap.loginBuu' => Http::response(['status' => 'fail'], 400),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => 'bad'])->assertStatus(401);
        }

        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => 'bad'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->spa('POST', '/api/auth/login', ['username' => 'bob', 'password' => 'bad'])->assertStatus(401);
    }

    public function test_login_requires_username_and_password(): void
    {
        $this->spa('POST', '/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_me_requires_login_then_logout_ends_session(): void
    {
        $this->spa('GET', '/api/auth/me')->assertStatus(401);

        $this->fakeKong();
        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => self::PASSWORD])->assertOk();

        $this->spa('GET', '/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.username', 'alice')
            ->assertJsonPath('role', 'user');

        $this->spa('POST', '/api/auth/logout')->assertNoContent();
        $this->spa('GET', '/api/auth/me')->assertStatus(401);
    }

    public function test_ldap_request_sends_secret_false_and_ums_request_uses_name_parts(): void
    {
        $this->fakeKong();
        $this->spa('POST', '/api/auth/login', ['username' => 'alice', 'password' => self::PASSWORD])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/service-api/ldap.loginBuu')
            && $r['secret'] === false && $r['username'] === 'alice');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/service-api/ums.SPerson')
            && $r['fname'] === 'สมหญิง' && $r['lname'] === 'ใจดี');
    }
}
