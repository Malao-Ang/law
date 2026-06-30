<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class BlockSizeApiTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function createDocumentWithBlocks(array $blocks): string
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);
        $documentId = 'doc_test_block_size_'.uniqid();

        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'block-size.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => count($blocks),
                'review_required_count' => 0,
            ],
            'pages' => [[
                'page_no' => 1,
                'image_path' => null,
                'blocks' => array_map(function (array $block): array {
                    $text = (string) ($block['approved_text'] ?? $block['normalized_text'] ?? $block['raw_text'] ?? '');

                    return [
                        'block_id' => (string) $block['block_id'],
                        'type' => (string) ($block['type'] ?? 'paragraph'),
                        'bbox' => null,
                        'reading_order' => 1,
                        'raw_text' => $text,
                        'normalized_text' => $text,
                        'ai_suggested_text' => $text,
                        'approved_text' => $text,
                        'confidence' => 1.0,
                        'needs_review' => false,
                        'flags' => [],
                        'meta' => array_merge([
                            'layout' => ['tabs' => []],
                            'formatting' => [],
                        ], is_array($block['meta'] ?? null) ? $block['meta'] : []),
                    ];
                }, $blocks),
            ]],
        ]);

        return $documentId;
    }

    public function test_patch_block_size_updates_image_display_dimensions(): void
    {
        $documentId = $this->createDocumentWithBlocks([
            [
                'block_id' => 'img-1',
                'type' => 'image',
                'meta' => ['image' => ['src_path' => 'test.jpg']],
            ],
        ]);

        $response = $this->patchJson("/api/documents/{$documentId}/blocks/img-1/size", [
            'page_no' => 1,
            'display_width_px' => 480,
            'display_height_px' => 320,
        ]);

        $response->assertOk()->assertJson(['status' => 'updated']);

        $review = $this->getJson("/api/documents/{$documentId}/review")->json();
        $block = collect($review['pages'][0]['blocks'])->firstWhere('block_id', 'img-1');
        $this->assertEquals(480, $block['meta']['image']['display_width_px']);
        $this->assertEquals(320, $block['meta']['image']['display_height_px']);
    }

    public function test_patch_block_size_updates_table_display_width(): void
    {
        $documentId = $this->createDocumentWithBlocks([
            [
                'block_id' => 'tbl-1',
                'type' => 'table',
                'meta' => ['table_html' => '<table></table>'],
            ],
        ]);

        $response = $this->patchJson("/api/documents/{$documentId}/blocks/tbl-1/size", [
            'page_no' => 1,
            'display_width_px' => 600,
            'display_height_px' => null,
        ]);

        $response->assertOk()->assertJson(['status' => 'updated']);

        $review = $this->getJson("/api/documents/{$documentId}/review")->json();
        $block = collect($review['pages'][0]['blocks'])->firstWhere('block_id', 'tbl-1');
        $this->assertEquals(600, $block['meta']['table_display_width_px']);
    }
}
