<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

/**
 * Saving the whole-document review editor (draft_html) must map indent/alignment
 * back onto each block's meta.layout, so the per-block renderers (RAG page,
 * law page) reflect what the reviewer set — not just the preview.
 */
class DraftHtmlLayoutWritebackTest extends TestCase
{
    private function seedTwoBlockDocument(ReviewStore $store, string $id): void
    {
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'x.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 2, 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    [
                        'block_id' => 'p1-b0001',
                        'type' => 'paragraph',
                        'reading_order' => 1,
                        'raw_text' => 'มาตรา ๑',
                        'normalized_text' => 'มาตรา ๑',
                        'ai_suggested_text' => 'มาตรา ๑',
                        'approved_text' => 'มาตรา ๑',
                        'confidence' => 1.0,
                        'needs_review' => false,
                        'flags' => [],
                        // Pre-existing layout with tabs that must survive the merge.
                        'meta' => ['layout' => ['indent_left' => 0, 'tabs' => [['position' => 720, 'type' => 'left']]], 'formatting' => []],
                    ],
                    [
                        'block_id' => 'p1-b0002',
                        'type' => 'paragraph',
                        'reading_order' => 2,
                        'raw_text' => 'ความในวรรคสอง',
                        'normalized_text' => 'ความในวรรคสอง',
                        'ai_suggested_text' => 'ความในวรรคสอง',
                        'approved_text' => 'ความในวรรคสอง',
                        'confidence' => 1.0,
                        'needs_review' => false,
                        'flags' => [],
                        'meta' => ['layout' => ['tabs' => []], 'formatting' => []],
                    ],
                ],
            ]],
        ]);
    }

    public function test_draft_html_indent_and_alignment_are_written_back_to_block_layout(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_writeback_'.uniqid();
        $this->seedTwoBlockDocument($store, $id);

        // Reviewer indented block 1 by two levels (48px = margin-left) and centred block 2.
        $draftHtml = '<article class="doc-review-document"><section class="doc-page" data-page-no="1">'
            .'<p data-block-id="p1-b0001" style="margin-left: 48px">มาตรา ๑</p>'
            .'<p data-block-id="p1-b0002" style="text-align: center">ความในวรรคสอง</p>'
            .'</section></article>';

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'draft_html' => $draftHtml,
        ]);

        $response->assertOk();

        $doc = $store->getReviewDocument($id);
        $blocks = $doc['pages'][0]['blocks'];

        // Block 1: 48px → 720 twips (TWIPS_PER_PX = 15), tabs preserved.
        $this->assertSame(720, $blocks[0]['meta']['layout']['indent_left']);
        $this->assertSame([['position' => 720, 'type' => 'left']], $blocks[0]['meta']['layout']['tabs']);

        // Block 2: alignment centred.
        $this->assertSame('center', $blocks[1]['meta']['layout']['alignment']);
    }
}
