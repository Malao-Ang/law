<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class ReviewStoreNormalizeTest extends TestCase
{
    public function test_apply_normalization_results_updates_blocks_and_summary(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'doc_norm_test';

        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'sample.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 1],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'raw_text' => 'ราชการง',
                    'normalized_text' => 'ราชการง',
                    'ai_suggested_text' => 'ราชการง',
                    'approved_text' => 'ราชการง',
                    'needs_review' => true,
                    'flags' => ['fast_extracted'],
                    'meta' => ['spell_suggestions' => [['token' => 'x']]],
                ]],
            ]],
        ]);

        $doc = $store->applyNormalizationResults($documentId, [
            [
                'block_id' => 'b1',
                'normalized_text' => 'ราชการง',
                'approved_text' => 'ราชการ',
                'auto_corrected' => true,
                'flags' => ['auto_corrected'],
                'spell_suggestions' => [],
            ],
        ]);

        $block = $doc['pages'][0]['blocks'][0];
        $this->assertSame('ราชการ', $block['approved_text']);
        $this->assertSame('ราชการ', $block['ai_suggested_text']);
        $this->assertFalse($block['needs_review']);
        $this->assertSame([], $block['meta']['spell_suggestions']);
        $this->assertSame(0, $doc['summary']['review_required_count']);
    }

    public function test_apply_normalization_results_handles_block_without_meta(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'doc_norm_no_meta';

        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'sample.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'raw_text' => 'test',
                    'normalized_text' => 'test',
                    'ai_suggested_text' => 'test',
                    'approved_text' => 'test',
                    'needs_review' => false,
                    'flags' => ['fast_extracted'],
                    // intentionally no 'meta' key
                ]],
            ]],
        ]);

        $doc = $store->applyNormalizationResults($documentId, [
            [
                'block_id' => 'b1',
                'normalized_text' => 'test',
                'approved_text' => 'test fixed',
                'auto_corrected' => true,
                'flags' => ['auto_corrected'],
                'spell_suggestions' => [],
            ],
        ]);

        $block = $doc['pages'][0]['blocks'][0];
        $this->assertSame('test fixed', $block['approved_text']);
        $this->assertSame([], $block['meta']['spell_suggestions']);
    }
}
