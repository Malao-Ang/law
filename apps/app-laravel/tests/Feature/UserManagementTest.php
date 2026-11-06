<?php

namespace Tests\Feature;

use App\Services\Audit\AuditLogStore;
use App\Services\Auth\CurrentUser;
use App\Services\Auth\MongoUserStore;
use App\Services\Storage\MongoBlobStore;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['auth_ldap.enforced' => true, 'auth_ldap.super_admins' => ['super', 'envadmin']]);
        $this->withHeader('Origin', 'http://localhost');
        app('mongo.blob.master')->write('master', 'unit', ['items' => [
            ['code' => 'DEP001', 'name' => 'One', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'DEP002', 'name' => 'Two', 'is_active' => true, 'sort_order' => 2],
        ]]);
        $blob = app('mongo.blob.users');
        foreach (['super', 'envadmin', 'admin', 'outer', 'editor', 'user', 'second', 'invited'] as $name) {
            $role = ['admin' => 'inner_admin', 'outer' => 'outer_admin', 'editor' => 'editor'][$name] ?? 'user';
            $memberships = $name === 'user' ? [] : [MongoUserStore::membership($name === 'second' ? 'DEP002' : 'DEP001', $role)];
            if (in_array($name, ['super', 'admin', 'outer'], true)) $memberships[] = MongoUserStore::membership('DEP002', $role);
            $blob->write('user', $name, ['username' => $name, 'name' => strtoupper($name), 'email' => $name.'@example.test',
                'is_super_admin' => $name === 'super', 'is_disabled' => false, 'memberships' => $memberships,
                'last_login_at' => $name === 'invited' ? null : '2026-11-06T00:00:00Z']);
        }
        foreach (['DEP001', 'DEP002'] as $unit) {
            app(MongoBlobStore::class)->write('status', $unit, ['document_id' => $unit, 'owner_unit_code' => $unit, 'responsible' => ['username' => 'editor'], 'status' => 'done']);
            app(MongoBlobStore::class)->write('review', $unit, ['law_meta' => ['title' => 'Title '.$unit], 'pages' => []]);
        }
    }

    private function actor(string $name, string $mode = 'DEP001'): void
    {
        $this->withSession([CurrentUser::SESSION_KEY => $name, CurrentUser::ACTIVE_UNIT_KEY => 'DEP001', CurrentUser::ACTING_MODE_KEY => $mode]);
    }

    public function test_super_admin_lists_only_logged_in_users_filters_and_paginates_without_ums(): void
    {
        $this->actor('super', 'super_admin');
        $this->getJson('/api/users?per_page=2')->assertOk()->assertJsonPath('total', 7)->assertJsonCount(2, 'items')->assertJsonPath('last_page', 4);
        $this->getJson('/api/users?q=EDITOR')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.username', 'editor');
        $this->getJson('/api/users?unit=DEP002&role=user')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/users?role=super_admin')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/users/invited')->assertNotFound();
        $this->getJson('/api/users?unit=INVALID')->assertUnprocessable();
        $this->getJson('/api/users?role=owner')->assertUnprocessable();
        $this->getJson('/api/users?per_page=1000')->assertUnprocessable();
    }

    public function test_admin_and_outer_use_active_unit_only_and_redact_other_memberships_and_documents(): void
    {
        foreach (['admin', 'outer'] as $name) {
            $this->actor($name);
            $response = $this->getJson('/api/users')->assertOk()->assertJsonCount(1, 'units');
            foreach ($response->json('items') as $item) foreach ($item['memberships'] as $membership) $this->assertSame('DEP001', $membership['unit_code']);
            $this->getJson('/api/users/second')->assertNotFound();
            $this->getJson('/api/users?unit=DEP002')->assertForbidden();
            $this->getJson('/api/users/editor')->assertOk()->assertJsonPath('document_count', 1)->assertJsonPath('documents.0.document_id', 'DEP001');
        }
    }

    public function test_global_super_admin_acting_editor_and_plain_editor_user_cannot_manage_users(): void
    {
        $super = app('mongo.blob.users')->read('user', 'super');
        $super['memberships'][0]['role'] = 'editor';
        app('mongo.blob.users')->write('user', 'super', $super);
        foreach (['super', 'editor', 'user'] as $name) {
            $this->actor($name);
            $this->getJson('/api/users')->assertForbidden();
            $this->getJson('/api/users/editor')->assertForbidden();
            $this->patchJson('/api/users/editor/disabled', ['is_disabled' => true])->assertForbidden();
            $this->putJson('/api/units/DEP001/members/user', ['role' => 'editor'])->assertForbidden();
        }
        $this->flushSession();
        config(['auth_ldap.enforced' => false]);
        $this->getJson('/api/users')->assertUnauthorized();
        $this->patchJson('/api/users/editor/disabled', ['is_disabled' => true])->assertUnauthorized();
    }

    public function test_disabled_flag_is_validated_super_only_and_protects_self_and_env_admins(): void
    {
        foreach (['admin', 'outer'] as $name) {
            $this->actor($name);
            $this->patchJson('/api/users/editor/disabled', ['is_disabled' => true])->assertForbidden();
        }
        $this->actor('super', 'super_admin');
        $this->patchJson('/api/users/editor/disabled', [])->assertUnprocessable();
        $this->patchJson('/api/users/editor/disabled', ['is_disabled' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/users/super/disabled', ['is_disabled' => true])->assertUnprocessable();
        $this->patchJson('/api/users/envadmin/disabled', ['is_disabled' => true])->assertUnprocessable();
        $this->patchJson('/api/users/editor/disabled', ['is_disabled' => true])->assertOk()->assertJsonPath('user.is_disabled', true);
        $this->getJson('/api/users?status=disabled')->assertOk()->assertJsonPath('total', 1);
        $filter = app(AuditLogStore::class)->filter(['resource' => 'user'], null);
        $logs = iterator_to_array(app(AuditLogStore::class)->rows($filter));
        $this->assertNotEmpty($logs);
        $this->assertSame('super', $logs[0]['actor_username']);
        $this->assertSame(['is_disabled' => true], $logs[0]['after']);
        $this->actor('editor');
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->actor('super', 'super_admin');
        $this->patchJson('/api/users/editor/disabled', ['is_disabled' => false])->assertOk()->assertJsonPath('user.is_disabled', false);
    }

    public function test_memberships_validate_role_actions_and_allow_admin_only_existing_user_editor_in_active_unit(): void
    {
        $this->actor('admin');
        $this->putJson('/api/units/DEP001/members/editor', ['role' => 'editor', 'actions' => ['upload', 'edit']])->assertOk()->assertJsonPath('member.actions', ['upload', 'edit']);
        $this->putJson('/api/units/DEP001/members/editor', ['role' => 'inner_admin', 'actions' => []])->assertForbidden();
        $this->putJson('/api/units/DEP002/members/second', ['role' => 'editor', 'actions' => []])->assertForbidden();
        $this->actor('outer');
        $this->putJson('/api/units/DEP001/members/editor', ['role' => 'user'])->assertForbidden();
        $this->putJson('/api/units/DEP001/members', ['changes' => [['username' => 'editor', 'role' => 'user']]])->assertForbidden();
        $this->deleteJson('/api/units/DEP001/members/editor')->assertForbidden();
        $this->actor('super', 'super_admin');
        $this->putJson('/api/units/DEP001/members/user', ['role' => 'editor', 'actions' => ['edit']])->assertOk();
        $this->putJson('/api/units/DEP001/members/user', ['role' => 'super_admin', 'actions' => []])->assertUnprocessable();
        $this->putJson('/api/units/DEP001/members/user', ['role' => 'editor', 'actions' => ['grant']])->assertUnprocessable();
        $this->deleteJson('/api/units/DEP001/members/user')->assertNoContent();
    }
}
