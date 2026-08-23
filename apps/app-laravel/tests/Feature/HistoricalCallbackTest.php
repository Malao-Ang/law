<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class HistoricalCallbackTest extends TestCase
{
    public function test_old_doc_callback_writes_export_and_keeps_stub(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_test_'.uniqid();
        $store->createHistoricalStub($id, 'old.pdf', ['source' => 'internal', 'law_type' => 'ประกาศ']);
        $store->setStatus($id, ['status' => 'done', 'document_type' => 'old']);

        $response = $this->postJson('/api/internal/pipeline-callback', [
            'document_id' => $id,
            'status' => 'done',
            'output' => [
                'document_id' => $id,
                'pages' => [[
                    'page_no' => 1,
                    'blocks' => [
                        ['block_id' => 'b1', 'type' => 'paragraph', 'approved_text' => 'ข้อความจากการสแกน'],
                    ],
                ]],
            ],
        ]);

        $response->assertOk();

        // Export written with the OCR text
        $exportPath = $store->absolutePath($store->exportRelativePath($id));
        $this->assertFileExists($exportPath);
        $export = json_decode(file_get_contents($exportPath), true);
        $this->assertStringContainsString('ข้อความจากการสแกน', $export['chunks'][0]['text']);

        // Review blob (stub) NOT overwritten — still empty pages
        $review = $store->getReviewDocument($id);
        $this->assertSame([], $review['pages']);
        $this->assertSame('old', $review['law_meta']['document_type']);

        $store->deleteDocument($id);
    }
}
