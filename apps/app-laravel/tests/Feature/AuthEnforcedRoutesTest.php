<?php

namespace Tests\Feature;

use App\Services\Auth\MongoUserStore;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthEnforcedRoutesTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function guardedRoutes(): array
    {
        return [
            'create master data' => ['POST', '/api/master-data/enforcement_status'],
            'delete document' => ['DELETE', '/api/documents/doc-x'],
            'update review' => ['PUT', '/api/documents/doc-x/document-review'],
            'list documents' => ['GET', '/api/documents'],
            'create permission group' => ['POST', '/api/permission-groups'],
            'upload document' => ['POST', '/api/documents'],
        ];
    }

    private function loginAs(string $role): void
    {
        app('mongo.blob.users')->write('user', 'tester', [
            'username' => 'tester', 'name' => 'Tester', 'role' => $role, 'is_disabled' => false,
        ]);
        $this->withSession(['auth_username' => 'tester']);
    }

    private function hit(string $method, string $uri): TestResponse
    {
        return $this->withHeaders(['Referer' => 'http://localhost:8500/admin'])->json($method, $uri, []);
    }

    #[DataProvider('guardedRoutes')]
    public function test_guest_gets_401_when_enforced(string $method, string $uri): void
    {
        config(['auth_ldap.enforced' => true]);

        $this->hit($method, $uri)->assertStatus(401);
    }

    #[DataProvider('guardedRoutes')]
    public function test_role_user_gets_403_when_enforced(string $method, string $uri): void
    {
        config(['auth_ldap.enforced' => true]);
        $this->loginAs(MongoUserStore::ROLE_USER);

        $this->hit($method, $uri)->assertStatus(403)->assertJsonPath('message', 'ไม่มีสิทธิ์เข้าถึง');
    }

    #[DataProvider('guardedRoutes')]
    public function test_super_admin_passes_guard_when_enforced(string $method, string $uri): void
    {
        config(['auth_ldap.enforced' => true]);
        $this->loginAs(MongoUserStore::ROLE_SUPER_ADMIN);

        $status = $this->hit($method, $uri)->getStatusCode();
        $this->assertNotContains($status, [401, 403]);
    }

    #[DataProvider('guardedRoutes')]
    public function test_guard_is_inactive_when_not_enforced(string $method, string $uri): void
    {
        config(['auth_ldap.enforced' => false]);

        $status = $this->hit($method, $uri)->getStatusCode();
        $this->assertNotContains($status, [401, 403]);
    }

    public function test_disabled_user_session_is_rejected_when_enforced(): void
    {
        config(['auth_ldap.enforced' => true]);
        app('mongo.blob.users')->write('user', 'tester', [
            'username' => 'tester', 'role' => 'super_admin', 'is_disabled' => true,
        ]);
        $this->withSession(['auth_username' => 'tester']);

        $this->hit('DELETE', '/api/documents/doc-x')->assertStatus(401);
    }

    public function test_public_read_routes_stay_open_when_enforced(): void
    {
        config(['auth_ldap.enforced' => true]);

        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/lookups')->assertOk();
        $this->postJson('/api/laws/search', [])->assertStatus(200);
    }

    public function test_pipeline_callback_is_not_session_guarded(): void
    {
        config(['auth_ldap.enforced' => true]);

        $status = $this->postJson('/api/internal/pipeline-callback', [])->getStatusCode();
        $this->assertNotContains($status, [401, 403, 419]);
    }
}
