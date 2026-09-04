<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VersionChainFileInfoTest extends TestCase
{
    public function test_version_chain_includes_file_info(): void
    {
        $store = app(ReviewStore::class);

        // v1 — has a real PDF file
        $v1 = $store->generateDocumentId();
        $stored = $store->storeUpload(
            UploadedFile::fake()->create('law_v1.pdf', 10, 'application/pdf'),
            $v1,
        );
        $store->setStatus($v1, [
            'status' => 'done',
            'source_path' => $stored['relative_path'],
            'source_file' => 'law_v1.pdf',
        ]);
        $store->writeReviewDocument($v1, [
            'document_id' => $v1, 'source_file' => 'law_v1.pdf', 'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => [
                'title' => 'กฎหมาย v1', 'law_type' => 'ประกาศ', 'status' => 'ถูกแทนที่',
                'change_status' => null, 'agency' => '', 'agencies' => [],
                'promulgation_date' => '2023-01-01',
            ],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        // v2 — child of v1, no file stored
        $v2 = $store->generateDocumentId();
        $store->setStatus($v2, ['status' => 'done', 'source_file' => 'law_v2.pdf']);
        $store->writeReviewDocument($v2, [
            'document_id' => $v2, 'source_file' => 'law_v2.pdf', 'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => [
                'title' => 'กฎหมาย v2', 'law_type' => 'ประกาศ', 'status' => 'มีผลบังคับใช้',
                'change_status' => 'ปรับปรุงรายข้อ', 'agency' => '', 'agencies' => [],
                'promulgation_date' => '2024-01-01',
                'parent_document_id' => $v1,
            ],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        $response = $this->getJson("/api/documents/{$v2}/versions");
        $response->assertOk();
        $response->assertJsonCount(2, 'versions');

        // v1 has physical file → has_file = true
        $v1Item = collect($response->json('versions'))->firstWhere('document_id', $v1);
        $this->assertNotNull($v1Item, 'v1 should appear in version chain');
        $this->assertTrue($v1Item['has_file'], 'v1 should have has_file=true');
        $this->assertSame('law_v1.pdf', $v1Item['source_file']);

        // v2 has no source_path in status → has_file = false
        $v2Item = collect($response->json('versions'))->firstWhere('document_id', $v2);
        $this->assertNotNull($v2Item, 'v2 should appear in version chain');
        $this->assertFalse($v2Item['has_file'], 'v2 should have has_file=false');

        $store->deleteDocument($v1);
        $store->deleteDocument($v2);
    }
}
