<?php

namespace Tests\Feature;

use App\Services\Auth\CurrentUser;
use App\Services\Permissions\PermissionStore;
use App\Services\Search\LawIndexer;
use App\Services\Storage\MongoBlobStore;
use Tests\TestCase;

class DocumentReviewPermissionValidationTest extends TestCase
{
    private string $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth_ldap.enforced' => true]);
        $this->withHeader('Origin', 'http://localhost');
        app('mongo.blob.users')->write('user', 'super', [
            'username' => 'super', 'is_super_admin' => true, 'memberships' => [],
        ]);
        $this->withSession([CurrentUser::SESSION_KEY => 'super', CurrentUser::ACTING_MODE_KEY => 'super_admin']);
        $this->mock(LawIndexer::class)->shouldReceive('index')->andReturn([]);
        $this->groupId = app(PermissionStore::class)->createGroup([
            'name' => 'กลุ่มทดสอบ', 'user_ids' => ['super'], 'unit_ids' => [],
        ])['id'];
        app(MongoBlobStore::class)->write('status', 'validation', ['document_id' => 'validation', 'status' => 'done']);
        app(MongoBlobStore::class)->write('review', 'validation', ['document_id' => 'validation',
            'law_meta' => ['title' => 'ทดสอบ', 'access_scope' => 'public'], 'pages' => [], 'relations' => []]);
    }

    private function update(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/documents/validation/document-review', $payload);
    }

    private function assertThaiErrors(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertUnprocessable();
        foreach ([$response->json('message'), ...array_merge(...array_values($response->json('errors')))] as $message) {
            $this->assertMatchesRegularExpression('/[ก-๙]/u', $message);
            $this->assertDoesNotMatchRegularExpression('/[a-zA-Z_]/', $message);
        }
    }

    public function test_public_accepts_empty_groups_and_clears_existing_private_grants(): void
    {
        $this->update(['law_meta' => ['access_scope' => 'public', 'permission_group_ids' => []]])
            ->assertOk()->assertJsonPath('law_meta.permission_group_ids', []);
        $this->update(['law_meta' => ['access_scope' => 'private', 'permission_group_ids' => [$this->groupId]]])
            ->assertOk()->assertJsonPath('law_meta.permission_group_ids', [$this->groupId]);
        $this->update(['law_meta' => ['access_scope' => 'public', 'permission_group_ids' => []]])
            ->assertOk()->assertJsonPath('law_meta.permission_group_ids', []);
    }

    public function test_private_rejects_missing_empty_blank_null_unknown_and_malformed_groups_in_thai(): void
    {
        foreach ([[], ['permission_group_ids' => []], ['permission_group_ids' => null],
            ['permission_group_ids' => ['']], ['permission_group_ids' => [null]],
            ['permission_group_ids' => ['missing']], ['permission_group_ids' => 'bad'],
            ['permission_group_ids' => [123]], ['permission_group_ids' => [str_repeat('x', 129)]]] as $fields) {
            $response = $this->update(['law_meta' => ['access_scope' => 'private'] + $fields]);
            $this->assertThaiErrors($response);
            $this->assertSame('public', app(MongoBlobStore::class)->read('review', 'validation')['law_meta']['access_scope']);
        }
    }

    public function test_partial_private_updates_keep_valid_groups_and_cannot_clear_grants_without_scope(): void
    {
        $this->update(['law_meta' => ['access_scope' => 'private', 'permission_group_ids' => [$this->groupId]]])->assertOk();
        $this->update(['law_meta' => ['title' => 'ชื่อใหม่']])->assertOk()->assertJsonPath('law_meta.permission_group_ids', [$this->groupId]);
        $this->update(['law_meta' => ['access_scope' => 'private']])->assertOk()->assertJsonPath('law_meta.permission_group_ids', [$this->groupId]);
        $this->assertThaiErrors($this->update(['law_meta' => ['permission_group_ids' => []]]));
        $this->update(['notes' => 'บันทึก'])->assertOk();
    }

    public function test_nested_validation_messages_never_expose_english_field_paths(): void
    {
        $this->assertThaiErrors($this->update(['law_meta' => ['access_scope' => 'invalid'],
            'relations' => [['url' => 'bad', 'proclamation_due_date' => 'bad']], 'font_size_pt' => 1]));
        $this->assertThaiErrors($this->update(['law_meta' => ['law_type' => 'missing']]));
    }
}
