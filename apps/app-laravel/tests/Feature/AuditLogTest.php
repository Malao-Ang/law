<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_mutations_are_listed_with_actor_and_filters(): void
    {
        $id = 'aud_doc_'.uniqid();
        $store = app(ReviewStore::class);
        $store->setStatus($id, ['status' => 'done', 'source_file' => $id.'.docx']);
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => $id.'.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => ['title' => 'กฎหมายสำหรับ audit', 'law_type' => 'ประกาศ'],
            'pages' => [],
        ]);

        $this->withHeaders([
            'X-Actor-Name' => rawurlencode('ผู้ใช้ทดสอบ'),
            'X-Actor-Role' => rawurlencode('Mock user'),
        ])->putJson("/api/oversight/{$id}", [
            'status' => 'อยู่ระหว่างการยกร่าง',
            'publish_due_date' => '2026-12-01',
            'steps' => $this->steps(),
            'primary' => ['name' => 'สมชาย', 'email' => 'owner@example.com'],
            'backup' => ['name' => 'สุดา', 'email' => 'backup@example.com'],
        ])->assertOk();

        $created = $this->postJson('/api/permission-groups', [
            'name' => 'กลุ่ม audit '.uniqid(),
            'unit_ids' => ['unit_legal'],
        ])->assertCreated();

        $this->deleteJson('/api/permission-groups/'.$created->json('id'))->assertOk();
        $this->deleteJson('/api/documents/'.$id)->assertOk();

        $all = $this->getJson('/api/audit-logs')->assertOk();
        $actions = collect($all->json('items'))->pluck('action')->all();
        $this->assertContains('update', $actions);
        $this->assertContains('create', $actions);
        $this->assertContains('delete', $actions);

        $oversight = collect($all->json('items'))->firstWhere('resource', 'oversight');
        $this->assertSame('ผู้ใช้ทดสอบ', $oversight['actor_name']);
        $this->assertSame('Mock user', $oversight['actor_role']);
        $this->assertSame('กฎหมายสำหรับ audit', $oversight['label']);
        $this->assertSame('แก้ไขสถานะกำกับดูแล', $oversight['summary']);

        $filtered = $this->getJson('/api/audit-logs?action=delete&resource=document&q='.urlencode($id))->assertOk();
        $this->assertCount(1, $filtered->json('items'));
        $this->assertSame('ลบเอกสาร', $filtered->json('items.0.summary'));
        $this->assertSame($id, $filtered->json('items.0.document_id'));
    }

    public function test_relation_changes_are_logged_as_create_update_and_delete(): void
    {
        $id = 'aud_rel_'.uniqid();
        $store = app(ReviewStore::class);
        $store->setStatus($id, ['status' => 'done', 'source_file' => $id.'.docx']);
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => $id.'.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => ['title' => 'กฎหมายแม่', 'law_type' => 'ประกาศ'],
            'relations' => [],
            'pages' => [],
        ]);

        $relation = [
            'id' => 'rel_1',
            'scope' => 'document',
            'type' => 'amends',
            'target_document_id' => 'child_1',
            'target_title' => 'ประกาศลูก',
            'target_section' => null,
            'note' => null,
        ];

        $this->putJson("/api/documents/{$id}/document-review", [
            'relations' => [$relation],
        ])->assertOk();

        $relation['type'] = 'repeals';
        $this->putJson("/api/documents/{$id}/document-review", [
            'relations' => [$relation],
        ])->assertOk();

        $this->putJson("/api/documents/{$id}/document-review", [
            'relations' => [],
        ])->assertOk();

        $items = collect($this->getJson('/api/audit-logs?resource=relation')->assertOk()->json('items'));
        $this->assertEqualsCanonicalizing(
            ['เพิ่มความสัมพันธ์', 'แก้ไขความสัมพันธ์', 'ลบความสัมพันธ์'],
            $items->pluck('summary')->all(),
        );
        $this->assertSame('ยกเลิก · ประกาศลูก', $items->firstWhere('summary', 'ลบความสัมพันธ์')['label']);
        $this->assertSame('แก้ไขเพิ่มเติม · ประกาศลูก', $items->firstWhere('summary', 'เพิ่มความสัมพันธ์')['label']);
        $this->assertSame($id, $items->first()['document_id']);
    }

    /**
     * @return array<int, array{key: string, due_date: null, completed_on: null}>
     */
    private function steps(): array
    {
        $steps = [];
        foreach (['drafting', 'submitted', 'approved', 'published', 'cancelled'] as $key) {
            $steps[] = ['key' => $key, 'due_date' => null, 'completed_on' => null];
        }

        return $steps;
    }
}
