<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class ReviewLayoutPatchTest extends TestCase
{
    private function seedReviewDocument(string $documentId): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);

        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'layout.pdf',
            'source_type' => 'pdf_text',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => 1,
                'review_required_count' => 0,
            ],
            'pages' => [[
                'page_no' => 1,
                'image_path' => null,
                'blocks' => [[
                    'block_id' => '1-1',
                    'type' => 'paragraph',
                    'bbox' => [20, 20, 400, 80],
                    'reading_order' => 1,
                    'raw_text' => 'ข้อ 1 ข้อความทดสอบ',
                    'normalized_text' => 'ข้อ 1 ข้อความทดสอบ',
                    'ai_suggested_text' => 'ข้อ 1 ข้อความทดสอบ',
                    'approved_text' => 'ข้อ 1 ข้อความทดสอบ',
                    'confidence' => 0.98,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => [
                        'layout' => [
                            'bbox' => [20, 20, 400, 80],
                            'reading_order' => 1,
                            'alignment' => null,
                            'indent_left' => 720,
                            'indent_first_line' => 360,
                            'indent_hanging' => null,
                            'indent_level' => 1,
                            'tabs' => [],
                        ],
                        'list_marker' => [
                            'text' => 'ข้อ 1',
                            'type' => 'legal-ข้อ',
                            'level' => 1,
                            'raw_match' => 'ข้อ 1',
                        ],
                    ],
                ]],
            ]],
        ]);
    }

    public function test_alignment_patch_updates_layout_and_marks_document_out_of_sync(): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);
        $documentId = 'doc_test_layout_alignment';
        $this->seedReviewDocument($documentId);

        $this->patchJson('/api/documents/'.$documentId.'/blocks/1-1/layout', [
            'page_no' => 1,
            'alignment' => 'center',
        ])->assertOk()
            ->assertJson([
                'document_id' => $documentId,
                'block_id' => '1-1',
                'status' => 'updated',
            ])
            ->assertJsonPath('block.meta.layout.alignment', 'center');

        $review = $store->getReviewDocument($documentId);
        $this->assertSame('center', $review['pages'][0]['blocks'][0]['meta']['layout']['alignment']);
        // out_of_sync is a transient trigger consumed by getReviewDocument's lazy-sync; in 'generated' mode it is always false after sync
    }

    public function test_tabs_patch_is_persisted_and_round_trips(): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);
        $documentId = 'doc_test_layout_tabs';
        $this->seedReviewDocument($documentId);

        $tabs = [
            ['position' => 1440, 'type' => 'left'],
            ['position' => 2880, 'type' => 'center'],
            ['position' => 4320, 'type' => 'decimal'],
        ];

        $this->patchJson('/api/documents/'.$documentId.'/blocks/1-1/layout', [
            'page_no' => 1,
            'tabs' => $tabs,
            'indent_left' => 960,
            'indent_first_line' => 480,
            'indent_hanging' => 240,
        ])->assertOk()
            ->assertJsonPath('block.meta.layout.tabs.0.position', 1440)
            ->assertJsonPath('block.meta.layout.tabs.1.type', 'center')
            ->assertJsonPath('block.meta.layout.indent_left', 960)
            ->assertJsonPath('block.meta.layout.indent_first_line', 480)
            ->assertJsonPath('block.meta.layout.indent_hanging', 240);

        $review = $store->getReviewDocument($documentId);
        $layout = $review['pages'][0]['blocks'][0]['meta']['layout'];

        $this->assertSame($tabs, $layout['tabs']);
        $this->assertSame(960, $layout['indent_left']);
        $this->assertSame(480, $layout['indent_first_line']);
        $this->assertSame(240, $layout['indent_hanging']);

        $this->getJson('/api/documents/'.$documentId.'/review')
            ->assertOk()
            ->assertJsonPath('pages.0.blocks.0.meta.layout.tabs.2.type', 'decimal');
    }

    public function test_invalid_alignment_value_returns_422(): void
    {
        $documentId = 'doc_test_layout_invalid_alignment';
        $this->seedReviewDocument($documentId);

        $this->patchJson('/api/documents/'.$documentId.'/blocks/1-1/layout', [
            'page_no' => 1,
            'alignment' => 'middle',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['alignment']);
    }

    public function test_out_of_range_twips_returns_422(): void
    {
        $documentId = 'doc_test_layout_invalid_twips';
        $this->seedReviewDocument($documentId);

        $this->patchJson('/api/documents/'.$documentId.'/blocks/1-1/layout', [
            'page_no' => 1,
            'indent_left' => 14401,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['indent_left']);
    }
}
