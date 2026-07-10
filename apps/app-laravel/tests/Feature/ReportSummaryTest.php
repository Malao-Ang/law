<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class ReportSummaryTest extends TestCase
{
    /** Seed a done document with the given law_meta. */
    // ponytail: renamed public to avoid collision with parent seed()
    private function seedDocument(ReviewStore $store, string $id, array $meta): void
    {
        $store->setStatus($id, ['status' => 'done', 'source_file' => $id.'.docx']);
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
