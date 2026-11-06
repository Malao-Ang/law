<?php

namespace Tests\Feature;

use App\Services\Auth\CurrentUser;
use App\Services\Auth\DocumentAccess;
use App\Services\Storage\MongoBlobStore;
use Illuminate\Http\Request;
use Tests\TestCase;

class VisibilitySummaryScopeTest extends TestCase
{
    private function fixtures(): void
    {
        config(['auth_ldap.enforced' => true]);
        $this->withHeader('Origin', 'http://localhost');
        app('mongo.blob.master')->write('master', 'unit', ['items' => [
            ['code' => 'DEP001', 'name' => 'One', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'DEP002', 'name' => 'Two', 'is_active' => true, 'sort_order' => 2],
        ]]);
        app('mongo.blob.users')->write('user', 'editor', [
            'username' => 'editor', 'is_super_admin' => true, 'memberships' => [
                ['unit_code' => 'DEP001', 'role' => 'editor'],
                ['unit_code' => 'DEP002', 'role' => 'editor'],
            ],
        ]);
        $blob = app(MongoBlobStore::class);
        foreach (['DEP001', 'DEP002'] as $unit) {
            foreach (['done', 'exported', 'failed'] as $status) {
                $id = $unit.'-'.$status;
                $blob->write('status', $id, [
                    'document_id' => $id, 'owner_unit_code' => $unit, 'status' => $status,
                    'responsible' => ['username' => 'editor'], 'uploaded_by' => ['username' => 'editor'],
                ]);
                $blob->write('review', $id, ['law_meta' => [
                    'title' => $id, 'law_type' => 'LTY01', 'agencies' => [$unit],
                    'published_date' => $status === 'exported' ? '2026-11-06' : '',
                ], 'relations' => [['target_document_id' => 'other']], 'pages' => []]);
            }
        }
    }

    public function test_editor_switch_scopes_all_rows_and_aggregates_despite_global_super_admin_and_ownership(): void
    {
        $this->fixtures();
        foreach (['DEP001', 'DEP002', 'DEP001'] as $unit) {
            $this->withSession([CurrentUser::SESSION_KEY => 'editor', CurrentUser::ACTIVE_UNIT_KEY => $unit,
                CurrentUser::ACTING_MODE_KEY => $unit]);
            $response = $this->getJson('/api/reports/summary')->assertOk();
            $response->assertJsonPath('totals.all', 3)->assertJsonPath('totals.published', 1)
                ->assertJsonPath('totals.failed', 1)->assertJsonPath('totals.relations', 3);
            $this->assertSame([$unit], array_column($response->json('by_agency'), 'key'));
            $this->assertCount(3, $response->json('documents'));
            $oversight = $this->getJson('/api/oversight/summary')->assertOk();
            $this->assertCount(3, $oversight->json('items'));
        }
        $this->withSession([CurrentUser::ACTING_MODE_KEY => 'super_admin']);
        $this->getJson('/api/reports/summary')->assertOk()->assertJsonPath('totals.all', 6);
        $this->assertCount(6, $this->getJson('/api/oversight/summary')->assertOk()->json('items'));
    }

    public function test_public_consumer_keeps_cross_unit_published_documents_and_hides_drafts(): void
    {
        $this->fixtures();
        $this->withSession([CurrentUser::SESSION_KEY => 'editor', CurrentUser::ACTIVE_UNIT_KEY => 'DEP001',
            CurrentUser::ACTING_MODE_KEY => 'DEP001']);
        $this->withHeader('Referer', 'http://localhost/law/relations/example')
            ->getJson('/api/reports/summary')->assertOk()->assertJsonPath('totals.all', 3);
        $this->getJson('/api/public/reports/summary')->assertOk()->assertJsonPath('totals.all', 2);
    }

    public function test_no_unit_scope_is_personal_and_anonymous_has_no_admin_bypass(): void
    {
        $this->fixtures();
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(CurrentUser::SESSION_KEY, 'editor');
        $access = app(DocumentAccess::class);
        $this->assertFalse($access->adminScope($request, ['owner_unit_code' => 'DEP001']));
        $this->assertTrue($access->adminScope($request, ['responsible' => ['username' => 'editor']]));
        $private = ['published_date' => '2026-11-06', 'access_scope' => 'private', 'owner_unit_code' => 'DEP003'];
        $this->assertFalse($access->publicScope($request, $private));
        $this->assertTrue($access->publicScope($request, $private + ['allowed_unit_codes' => ['DEP002']]));
        $this->assertTrue($access->publicScope($request, $private + ['allowed_usernames' => ['editor']]));
        $this->assertTrue($access->publicScope($request, array_replace($private, ['owner_unit_code' => 'DEP001'])));
        $this->assertFalse($access->publicScope($request, ['responsible' => ['username' => 'editor']]));
        config(['auth_ldap.enforced' => false]);
        $this->assertFalse($access->adminScope(Request::create('/'), []));
        $this->assertFalse($access->publicScope(Request::create('/'), ['published_date' => '2026-11-06', 'access_scope' => 'private']));
    }
}
