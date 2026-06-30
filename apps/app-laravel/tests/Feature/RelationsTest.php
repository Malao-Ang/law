<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class RelationsTest extends TestCase
{
    private function seedDocument(ReviewStore $store, string $id): void
    {
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'x.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'p1-b0001', 'type' => 'section_header', 'reading_order' => 1,
                    'raw_text' => 'มาตรา ๓', 'normalized_text' => 'มาตรา ๓',
                    'ai_suggested_text' => 'มาตรา ๓', 'approved_text' => 'มาตรา ๓',
                    'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                    'meta' => ['layout' => ['tabs' => []], 'formatting' => []],
                ]],
            ]],
        ]);
    }

    public function test_relations_default_to_empty_array(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_rel_'.uniqid();
        $this->seedDocument($store, $id);

        $doc = $store->getReviewDocument($id);
        $this->assertArrayHasKey('relations', $doc);
        $this->assertSame([], $doc['relations']);
    }

    public function test_relations_persist_and_normalize(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_rel_'.uniqid();
        $this->seedDocument($store, $id);

        $response = $this->putJson("/api/documents/{$id}/document-review", [
            'relations' => [
                [
                    'id' => 'r1', 'scope' => 'section', 'block_id' => 'p1-b0001',
                    'type' => 'repeals', 'target_document_id' => null,
                    'target_title' => 'พ.ร.บ. เก่า', 'target_section' => 'มาตรา ๕',
                    'note' => '', 'url' => '',
                ],
                ['id' => 'r2', 'scope' => 'document', 'type' => 'related', 'target_title' => ''],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('relations.0.id', 'r1');
        $response->assertJsonPath('relations.0.type', 'repeals');
        $response->assertJsonPath('relations.0.target_section', 'มาตรา ๕');
        $response->assertJsonCount(1, 'relations');
    }
}
