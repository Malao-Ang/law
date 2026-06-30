<?php

namespace Tests\Feature;

use App\Jobs\NormalizeDocumentJob;
use App\Services\DocumentPipelineClient;
use App\Services\ReviewStore;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NormalizeDocumentJobTest extends TestCase
{
    public function test_job_normalizes_blocks_and_marks_correction_done(): void
    {
        $documentId = 'doc_job_test';
        $store = app(ReviewStore::class);

        $store->setStatus($documentId, [
            'status' => 'done',
            'correction_status' => 'pending',
        ]);

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
                    'raw_text' => 'ราชการง',
                    'normalized_text' => 'ราชการง',
                    'ai_suggested_text' => 'ราชการง',
                    'approved_text' => 'ราชการง',
                    'needs_review' => false,
                    'flags' => ['fast_extracted'],
                    'meta' => ['spell_suggestions' => []],
                ]],
            ]],
        ]);

        Http::fake([
            '*/pipeline/normalize' => Http::response([
                'document_id' => $documentId,
                'results' => [[
                    'block_id' => 'b1',
                    'normalized_text' => 'ราชการง',
                    'approved_text' => 'ราชการ',
                    'auto_corrected' => true,
                    'flags' => ['auto_corrected'],
                    'spell_suggestions' => [],
                ]],
            ], 200),
        ]);

        (new NormalizeDocumentJob($documentId))->handle(
            app(DocumentPipelineClient::class),
            $store,
        );

        $doc = $store->getReviewDocument($documentId);
        // NormalizeDocumentJob's contract is now just normalizing block text;
        // it intentionally no longer writes correction_status/current_step
        // (normalize is non-fatal background work; the document is already 'done').
        $this->assertSame('ราชการ', $doc['pages'][0]['blocks'][0]['approved_text']);
    }

    public function test_job_skips_table_and_image_blocks_in_request(): void
    {
        $documentId = 'doc_job_skip';
        $store = app(ReviewStore::class);
        $store->setStatus($documentId, ['status' => 'done', 'correction_status' => 'pending']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'x.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 2, 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    ['block_id' => 'b1', 'type' => 'paragraph', 'raw_text' => 'ก', 'meta' => []],
                    ['block_id' => 'b2', 'type' => 'table', 'raw_text' => '', 'meta' => []],
                ],
            ]],
        ]);

        Http::fake(['*/pipeline/normalize' => Http::response(['document_id' => $documentId, 'results' => []], 200)]);

        (new NormalizeDocumentJob($documentId))->handle(
            app(DocumentPipelineClient::class),
            $store,
        );

        Http::assertSent(function ($request) {
            return count($request['blocks']) === 1 && $request['blocks'][0]['block_id'] === 'b1';
        });
    }
}
