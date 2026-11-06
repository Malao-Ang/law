<?php

namespace Tests\Feature;

use App\Services\Auth\CurrentUser;
use App\Services\Auth\DocumentAccess;
use App\Services\Auth\MongoUserStore;
use App\Services\OversightService;
use App\Services\Storage\MongoBlobStore;
use Tests\TestCase;

class DocumentScopeSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['auth_ldap.enforced' => true]);
        $this->withHeader('Origin', 'http://localhost');
        app('mongo.blob.master')->write('master', 'unit', ['items' => [
            ['code' => 'DEP001', 'name' => 'One', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'DEP002', 'name' => 'Two', 'is_active' => true, 'sort_order' => 2],
        ]]);
        foreach (['editor', 'other', 'admin', 'outer'] as $name) {
            $role = ['admin' => 'inner_admin', 'outer' => 'outer_admin'][$name] ?? 'editor';
            app('mongo.blob.users')->write('user', $name, ['username' => $name, 'is_super_admin' => $name === 'editor',
                'memberships' => array_map(fn ($code) => ['unit_code' => $code, 'role' => $role, 'actions' => MongoUserStore::ACTIONS], ['DEP001', 'DEP002'])]);
        }
        foreach (['DEP001', 'DEP002'] as $unit) foreach (['draft', 'public', 'private'] as $state) {
            $id = $unit.'-'.$state;
            app(MongoBlobStore::class)->write('status', $id, ['document_id' => $id, 'owner_unit_code' => $unit,
                'responsible' => ['username' => 'editor'], 'status' => $state === 'draft' ? 'done' : 'ingested',
                'workflow_completed_step' => $state === 'draft' ? 1 : 6]);
            app(MongoBlobStore::class)->write('review', $id, ['document_id' => $id, 'law_meta' => [
                'title' => 'Secret '.$id, 'law_type' => 'LTY01', 'status' => 'EST01',
                'published_date' => $state === 'draft' ? '' : '2026-11-06',
                'access_scope' => $state === 'private' ? 'private' : 'public',
                'co_editors' => [['username' => 'other', 'actions' => MongoUserStore::ACTIONS]],
            ], 'relations' => [], 'pages' => []]);
        }
        $this->actor('editor', 'DEP001');
    }

    private function actor(string $name, string $unit): void
    {
        $this->withSession([CurrentUser::SESSION_KEY => $name, CurrentUser::ACTIVE_UNIT_KEY => $unit, CurrentUser::ACTING_MODE_KEY => $unit]);
    }

    public function test_every_direct_read_and_mutation_obeys_active_unit_even_for_published_and_global_super_admin(): void
    {
        foreach (['DEP001', 'DEP002', 'DEP001'] as $active) {
            $this->actor('editor', $active);
            $other = $active === 'DEP001' ? 'DEP002' : 'DEP001';
            foreach (['draft', 'public', 'private'] as $state) {
                $id = $other.'-'.$state;
                foreach (['', '/review', '/preview', '/versions', '/active-children', '/incoming-relations', '/file', '/related-download.zip', '/images/x.png', '/pages/1/image', '/esign/signed-pdf', '/esign/preview-pdf', '/export-pdf/preview'] as $suffix) {
                    $this->getJson('/api/documents/'.$id.$suffix)->assertForbidden();
                }
                foreach (['export', 'export-pdf', 'export-word', 'export-pdf-original', 'esign/send', 'esign/cancel', 'retry-correction', 'transfer'] as $suffix) {
                    // Unregistered operations must also never execute.
                    $response = $this->postJson('/api/documents/'.$id.'/'.$suffix, ['scope' => 'public']);
                    $this->assertContains($response->status(), [403, 404, 405]);
                }
                $this->putJson('/api/documents/'.$id.'/document-review', ['notes' => 'changed'])->assertForbidden();
                $this->deleteJson('/api/documents/'.$id)->assertForbidden();
            }
            $this->getJson('/api/documents/'.$active.'-draft/review')->assertOk();
        }
        $this->withSession([CurrentUser::ACTING_MODE_KEY => 'super_admin']);
        $this->getJson('/api/documents/DEP002-draft/review')->assertOk();
    }

    public function test_editor_reads_same_unit_but_cannot_edit_another_author_or_use_legacy_co_editor_grants(): void
    {
        $this->actor('other', 'DEP001');
        $this->getJson('/api/documents/DEP001-draft/review')->assertOk()->assertJsonPath('can.edit', false);
        $this->putJson('/api/documents/DEP001-draft/document-review', ['notes' => 'changed'])->assertForbidden();
        foreach (['putJson', 'deleteJson'] as $method) $this->assertContains($this->$method('/api/documents/DEP001-draft/co-editors/editor')->status(), [404, 405]);
        $this->actor('editor', 'DEP001');
        $this->putJson('/api/documents/DEP001-draft/document-review', ['law_meta' => ['permission_group_ids' => ['grant']]])->assertForbidden();
        $this->postJson('/api/permission-groups', ['name' => 'grant'])->assertForbidden();
        $this->putJson('/api/units/DEP001/members/other', ['role' => 'editor'])->assertForbidden();
        $this->actor('outer', 'DEP001');
        $this->putJson('/api/units/DEP001/members/other', ['role' => 'editor'])->assertForbidden();
        $this->deleteJson('/api/units/DEP001/members/other')->assertForbidden();
        $this->actor('admin', 'DEP001');
        $this->putJson('/api/units/DEP002/members/other', ['role' => 'editor'])->assertForbidden();
    }

    public function test_public_is_published_only_and_private_requires_membership_or_explicit_grant(): void
    {
        $this->flushSession();
        $this->getJson('/api/public/documents/DEP002-draft/review')->assertUnauthorized();
        $this->getJson('/api/public/documents/DEP002-private/review')->assertUnauthorized();
        $this->getJson('/api/public/documents/DEP002-public/review')->assertOk()->assertJsonPath('can.edit', false);
        $this->actor('editor', 'DEP001');
        $this->getJson('/api/public/documents/DEP002-private/review')->assertOk();
        $this->getJson('/api/public/documents/DEP002-draft/review')->assertForbidden();
        $this->postJson('/api/laws/search', ['q' => ''])->assertOk()->assertJsonPath('total', 4);
        $this->flushSession();
        $response = $this->postJson('/api/laws/search', ['q' => '']);
        $response->assertOk()->assertJsonPath('total', 2);
        $this->assertStringNotContainsString('Secret DEP002-private', $response->getContent());
        $this->postJson('/api/laws/suggest', ['q' => 'Secret'])->assertOk()->assertJsonMissing(['title' => 'Secret DEP002-private']);
    }

    public function test_nested_relations_redact_all_hidden_metadata_and_deny_target_downloads(): void
    {
        $blob = app(MongoBlobStore::class);
        $review = $blob->read('review', 'DEP001-public');
        $review['relations'] = [['id' => 'link', 'type' => 'related', 'scope' => 'document', 'target_document_id' => 'DEP002-draft',
            'target_title' => 'Hidden title', 'target_section' => 'Hidden section', 'url' => '/hidden.pdf', 'note' => 'Hidden note']];
        $blob->write('review', 'DEP001-public', $review);
        $response = $this->getJson('/api/documents/DEP001-public/review')->assertOk();
        $response->assertJsonPath('relations.0.target_title', 'เอกสารจำกัดสิทธิ์')->assertJsonPath('relations.0.target_document_id', '');
        foreach (['Hidden title', 'Hidden section', 'Hidden note', '/hidden.pdf'] as $secret) $this->assertStringNotContainsString($secret, $response->getContent());
        $this->getJson('/api/documents/DEP001-public/related/DEP002-draft/file')->assertForbidden();
        $this->getJson('/api/relation-candidates')->assertOk()->assertJsonCount(5, 'documents');
        $this->getJson('/api/relation-candidates/DEP002-draft/review')->assertForbidden();
        $this->getJson('/api/relation-candidates/DEP002-public/review')->assertOk();
        $this->putJson('/api/documents/DEP001-draft/document-review', ['relations' => [['id' => 'x', 'type' => 'related', 'scope' => 'document', 'target_document_id' => 'DEP002-draft', 'target_title' => 'Hidden']]])->assertForbidden();
    }

    public function test_audit_uses_active_admin_role_and_scheduled_reminders_do_not_require_http_session(): void
    {
        $this->actor('editor', 'DEP001');
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->actor('admin', 'DEP001');
        $this->getJson('/api/audit-logs')->assertOk()->assertJsonCount(1, 'units')->assertJsonPath('units.0.code', 'DEP001');
        $this->getJson('/api/oversight/mail')->assertOk();
        $catalog = app(OversightService::class)->reminderCatalog();
        $this->flushSession();
        $this->assertSame($catalog, app(OversightService::class)->reminderCatalog());
    }

    public function test_published_cross_unit_relations_and_whole_replacement_never_mutate_uneditable_targets(): void
    {
        $this->mock(\App\Services\Search\LawIndexer::class)->shouldReceive('index');
        $blob = app(MongoBlobStore::class);
        $other = $blob->read('status', 'DEP001-public');
        $other['responsible'] = ['username' => 'other'];
        $blob->write('status', 'DEP001-public', $other);
        $targets = ['DEP002-public', 'DEP001-public'];
        $before = [];
        foreach ($targets as $id) $before[$id] = $blob->read('review', $id);
        $whole = collect(app(\App\Services\MasterData\MasterDataStore::class)->all(\App\Services\MasterData\MasterDataKind::ChangeStatus))
            ->first(fn ($row) => ($row['attrs']['role'] ?? null) === 'whole')['code'];
        $this->assertNotEmpty($whole);
        $relations = array_map(fn ($id) => ['id' => $id, 'scope' => 'document', 'type' => 'supersedes',
            'target_document_id' => $id, 'target_title' => 'Secret '.$id], $targets);
        $this->putJson('/api/documents/DEP001-draft/document-review', [
            'law_meta' => ['change_status' => $whole], 'relations' => $relations,
        ])->assertOk();
        foreach ($targets as $id) {
            $this->assertSame($before[$id]['law_meta'], $blob->read('review', $id)['law_meta']);
        }
        $this->assertCount(2, $blob->read('review', 'DEP001-draft')['relations']);
        $this->putJson('/api/documents/DEP001-draft/document-review', ['notes' => 'แก้ไขภายหลัง'])->assertOk();
        foreach ($targets as $id) $this->assertSame($before[$id]['law_meta'], $blob->read('review', $id)['law_meta']);
    }

    public function test_headers_query_and_payload_cannot_select_public_context_for_admin_access(): void
    {
        $this->withHeaders(['Referer' => 'http://localhost/law/DEP002-public', 'X-Document-Context' => 'public']);
        $this->getJson('/api/documents/DEP002-public/review?mode=public&document_context=public')->assertForbidden();
        $this->putJson('/api/documents/DEP002-public/document-review?mode=public', [
            'document_context' => 'public', 'notes' => 'เปลี่ยนแปลง',
        ])->assertForbidden();
        $this->getJson('/api/public/documents/DEP002-public/review?mode=admin')->assertOk()->assertJsonPath('can.edit', false);
    }

    public function test_private_public_reads_follow_current_group_user_and_unit_grants_without_coeditor_bypass(): void
    {
        app('mongo.blob.users')->write('user', 'outsider', ['username' => 'outsider', 'memberships' => [], 'is_super_admin' => false]);
        $blob = app(MongoBlobStore::class);
        $review = $blob->read('review', 'DEP002-private');
        $review['law_meta']['co_editors'][] = ['username' => 'outsider', 'actions' => MongoUserStore::ACTIONS];
        $blob->write('review', 'DEP002-private', $review);
        $this->withSession([CurrentUser::SESSION_KEY => 'outsider', CurrentUser::ACTIVE_UNIT_KEY => null, CurrentUser::ACTING_MODE_KEY => null]);
        $this->getJson('/api/public/documents/DEP002-private/review')->assertForbidden();
        $group = app(\App\Services\Permissions\PermissionStore::class)->createGroup([
            'name' => 'กลุ่มอ่าน', 'unit_ids' => [], 'user_ids' => ['outsider'],
        ]);
        $review['law_meta']['permission_group_ids'] = [$group['id']];
        $blob->write('review', 'DEP002-private', $review);
        $this->getJson('/api/public/documents/DEP002-private/review')->assertOk();
        app('mongo.blob.permissions')->write('data', 'groups', []);
        $this->getJson('/api/public/documents/DEP002-private/review')->assertForbidden();
        $this->actor('other', 'DEP001');
        $group = app(\App\Services\Permissions\PermissionStore::class)->createGroup([
            'name' => 'กลุ่มหน่วยงาน', 'unit_ids' => ['DEP001'], 'user_ids' => [],
        ]);
        $review['law_meta']['permission_group_ids'] = [$group['id']];
        $blob->write('review', 'DEP002-private', $review);
        $this->getJson('/api/public/documents/DEP002-private/review')->assertOk();
        $this->getJson('/api/documents/DEP002-private/review')->assertForbidden();
    }
}
