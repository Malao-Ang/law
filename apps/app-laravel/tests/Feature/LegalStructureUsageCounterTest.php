<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LegalStructureUsageCounterTest extends TestCase
{
    public function test_usage_count_counts_documents_with_resolved_block_chunk_types_once(): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);
        $this->seedReviewDocument($store, 'lst_code', ['LST004', 'LST004']);
        $this->seedReviewDocument($store, 'lst_legacy', ['ARTICLE']);
        $this->seedReviewDocument($store, 'lst_chapter', ['CHAPTER']);
        $this->seedReviewDocument($store, 'lst_unknown', ['UNKNOWN']);

        $this->getJson('/api/master-data/legal_structure/LST004')
            ->assertOk()
            ->assertJsonPath('usage_count', 2);

        $this->getJson('/api/master-data/legal_structure/LST012')
            ->assertOk()
            ->assertJsonPath('usage_count', 1);
    }

    /**
     * @param  list<string>  $chunkTypes
     */
    private function seedReviewDocument(ReviewStore $store, string $documentId, array $chunkTypes): void
    {
        $store->setStatus($documentId, ['document_id' => $documentId, 'status' => 'done']);
        $blocks = [];
        foreach ($chunkTypes as $index => $chunkType) {
            $blocks[] = [
                'block_id' => 'b'.($index + 1),
                'type' => 'paragraph',
                'approved_text' => 'text',
                'meta' => [
                    'chunk_type' => $chunkType,
                    'landingai' => ['chunk_type' => 'ARTICLE'],
                ],
            ];
        }

        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => $documentId.'.pdf',
            'source_type' => 'pdf_text',
            'language' => 'th',
            'law_meta' => ['title' => $documentId],
            'pages' => [['page_no' => 1, 'blocks' => $blocks]],
            'summary' => ['page_count' => 1, 'block_count' => count($blocks), 'review_required_count' => 0],
        ]);
    }
}
