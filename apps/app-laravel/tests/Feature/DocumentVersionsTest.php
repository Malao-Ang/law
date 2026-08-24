<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DocumentVersionsTest extends TestCase
{
    private function writeDoc(ReviewStore $store, string $id, ?string $parentId, string $promulgation, string $status, string $changeStatus): void
    {
        $store->setStatus($id, ['status' => 'done', 'source_file' => "{$id}.pdf"]);
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => "{$id}.pdf",
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => [
                'title' => "Doc {$id}",
                'status' => $status,
                'change_status' => $changeStatus,
                'promulgation_date' => $promulgation,
                'effective_date' => $promulgation,
                'parent_document_id' => $parentId,
            ],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_returns_ordered_chain_with_current_leaf(): void
    {
        $store = app(ReviewStore::class);
        $suffix = uniqid();
        $v1 = "ver_{$suffix}_1";
        $v2 = "ver_{$suffix}_2";
        $v3 = "ver_{$suffix}_3";
        $this->writeDoc($store, $v1, null, '2566-06-01', 'ยกเลิกการใช้งาน', 'กฎหมายใหม่');
        $this->writeDoc($store, $v2, $v1, '2567-02-12', 'ยกเลิกการใช้งาน', 'ปรับปรุงทั้งฉบับ');
        $this->writeDoc($store, $v3, $v2, '2568-05-20', 'มีผลบังคับใช้', 'ปรับปรุงทั้งฉบับ');
        Cache::forget('law-meta-list');

        $res = $this->getJson("/api/documents/{$v2}/versions")->assertOk()->json();

        $this->assertSame($v3, $res['current_document_id']);
        $this->assertSame([$v1, $v2, $v3], array_column($res['versions'], 'document_id'));
        $this->assertSame(['v1.0', 'v2.0', 'v3.0'], array_column($res['versions'], 'version_label'));
        $this->assertSame([false, false, true], array_column($res['versions'], 'is_current'));
        $this->assertSame('มีผลบังคับใช้', $res['versions'][2]['status']);
    }

    public function test_lone_document_returns_single_current_version(): void
    {
        $store = app(ReviewStore::class);
        $id = 'ver_lone_'.uniqid();
        $this->writeDoc($store, $id, null, '2568-01-01', 'มีผลบังคับใช้', 'กฎหมายใหม่');
        Cache::forget('law-meta-list');

        $res = $this->getJson("/api/documents/{$id}/versions")->assertOk()->json();

        $this->assertSame($id, $res['current_document_id']);
        $this->assertCount(1, $res['versions']);
        $this->assertTrue($res['versions'][0]['is_current']);
    }

    public function test_unknown_document_returns_404(): void
    {
        $this->getJson('/api/documents/does-not-exist-'.uniqid().'/versions')->assertStatus(404);
    }
}
