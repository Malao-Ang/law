<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class ReportSummaryTest extends TestCase
{
    /** Seed a done document with the given law_meta. */
    // ponytail: renamed public to avoid collision with parent seed()
    private function seedDocument(ReviewStore $store, string $id, array $meta, string $status = 'done'): void
    {
        $store->setStatus($id, ['status' => $status, 'source_file' => $id.'.docx']);
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => $id.'.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'law_meta' => $meta,
            'pages' => [],
        ]);
    }

    public function test_summary_aggregates_scoped_by_agency(): void
    {
        $store = app(ReviewStore::class);
        $agency = 'AGENCY_'.uniqid();

        $this->seedDocument($store, 'd1_'.uniqid(), [
            'law_type' => 'พระราชบัญญัติ', 'agencies' => [$agency],
            'law_groups' => ['ด้านวิชาการ'], 'promulgation_date' => '1 มีนาคม 2551',
        ]);
        $this->seedDocument($store, 'd2_'.uniqid(), [
            'law_type' => 'พระราชบัญญัติ', 'agencies' => [$agency],
            'law_groups' => ['ด้านการเงิน'], 'promulgation_date' => '2 มีนาคม 2552',
        ]);
        $this->seedDocument($store, 'd3_'.uniqid(), [
            'law_type' => 'ประกาศ', 'agencies' => [$agency],
            'law_groups' => ['ด้านวิชาการ'], 'promulgation_date' => 'ไม่มีปี',
        ], 'ingested');

        $res = $this->getJson('/api/reports/summary?agency[]='.rawurlencode($agency));
        $res->assertOk();

        $res->assertJsonPath('totals.all', 3);
        $res->assertJsonPath('totals.published', 1);

        $byType = collect($res->json('by_type'))->keyBy('key');
        $this->assertSame(2, $byType['พระราชบัญญัติ']['count']);
        $this->assertSame(1, $byType['ประกาศ']['count']);

        $byGroup = collect($res->json('by_group'))->keyBy('key');
        $this->assertSame(2, $byGroup['ด้านวิชาการ']['count']);
        $this->assertSame(1, $byGroup['ด้านการเงิน']['count']);

        $byYear = collect($res->json('by_year'))->keyBy('key');
        $this->assertSame(1, $byYear['2551']['count']);
        $this->assertSame(1, $byYear['2552']['count']);
        $this->assertSame(1, $byYear['ไม่ระบุ']['count']);

        $this->assertCount(3, $res->json('documents'));
    }

    public function test_summary_status_filter_scopes_counts(): void
    {
        $store = app(ReviewStore::class);
        $agency = 'AGENCY_'.uniqid();

        $done = 'd_done_'.uniqid();
        $this->seedDocument($store, $done, ['law_type' => 'ประกาศ', 'agencies' => [$agency]]);

        $failed = 'd_fail_'.uniqid();
        $store->setStatus($failed, ['status' => 'failed', 'source_file' => 'f.docx']);
        $store->writeReviewDocument($failed, [
            'document_id' => $failed, 'source_file' => 'f.docx', 'source_type' => 'docx',
            'language' => 'th', 'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'law_meta' => ['law_type' => 'ประกาศ', 'agencies' => [$agency]], 'pages' => [],
        ]);

        $res = $this->getJson('/api/reports/summary?agency[]='.rawurlencode($agency).'&status=failed');
        $res->assertOk();
        $res->assertJsonPath('totals.all', 1);
        $this->assertSame($failed, $res->json('documents.0.id'));
    }

    public function test_list_law_meta_returns_rows_with_meta_fields(): void
    {
        $store = app(ReviewStore::class);
        $agency = 'AGENCY_'.uniqid();
        $id = 'doc_rep_'.uniqid();
        $this->seedDocument($store, $id, [
            'law_type' => 'พระราชบัญญัติ',
            'law_groups' => ['ด้านวิชาการ'],
            'agencies' => [$agency],
            'promulgation_date' => '9 มกราคม 2551',
        ]);

        $rows = collect($store->listLawMeta());
        $row = $rows->firstWhere('document_id', $id);

        $this->assertNotNull($row);
        $this->assertSame('พระราชบัญญัติ', $row['law_type']);
        $this->assertSame(['ด้านวิชาการ'], $row['law_groups']);
        $this->assertSame([$agency], $row['agencies']);
        $this->assertSame('9 มกราคม 2551', $row['promulgation_date']);
        $this->assertSame('done', $row['status']);
    }
}
