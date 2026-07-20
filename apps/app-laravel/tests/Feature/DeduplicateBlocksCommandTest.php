<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class DeduplicateBlocksCommandTest extends TestCase
{
    private ReviewStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = app(ReviewStore::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeBlock(string $id, string $text, string $type = 'paragraph'): array
    {
        return [
            'block_id' => $id,
            'type' => $type,
            'bbox' => null,
            'reading_order' => 1,
            'raw_text' => $text,
            'normalized_text' => $text,
            'ai_suggested_text' => $text,
            'approved_text' => $text,
            'confidence' => 1.0,
            'needs_review' => false,
            'flags' => [],
            'meta' => ['layout' => ['tabs' => []], 'formatting' => []],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function seedDocument(string $id, array $blocks): void
    {
        $this->store->setStatus($id, ['status' => 'done', 'source_file' => 'test.docx']);
        $this->store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'test.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => count($blocks), 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => $blocks,
            ]],
        ]);
    }

    public function test_removes_duplicate_text_blocks(): void
    {
        $id = 'doc_dedup_'.uniqid();
        $longText = str_repeat('ข้อความซ้ำ', 5);

        $this->seedDocument($id, [
            $this->makeBlock('b1', $longText),
            $this->makeBlock('b2', $longText),
        ]);

        $this->artisan('docs:dedup-blocks')
            ->expectsOutputToContain("Removed 1 duplicate block(s) from {$id}.")
            ->expectsOutputToContain('Dedup complete. Updated 1 document(s); removed 1 block(s).')
            ->assertExitCode(0);

        $doc = $this->store->getReviewDocument($id);

        $this->assertCount(1, $doc['pages'][0]['blocks']);
        $this->assertSame('b1', $doc['pages'][0]['blocks'][0]['block_id']);
        $this->assertSame(1, $doc['summary']['block_count']);
    }

    public function test_keeps_short_text_even_if_duplicate(): void
    {
        $id = 'doc_dedup_short_'.uniqid();
        $shortText = 'หมายเหตุ';

        $this->seedDocument($id, [
            $this->makeBlock('b1', $shortText),
            $this->makeBlock('b2', $shortText),
        ]);

        $this->artisan('docs:dedup-blocks')
            ->expectsOutputToContain('Dedup complete. Updated 0 document(s); removed 0 block(s).')
            ->assertExitCode(0);

        $doc = $this->store->getReviewDocument($id);

        $this->assertCount(2, $doc['pages'][0]['blocks']);
    }

    public function test_keeps_duplicate_image_and_table_blocks(): void
    {
        $id = 'doc_dedup_media_'.uniqid();
        $text = str_repeat('ตาราง', 10);

        $this->seedDocument($id, [
            $this->makeBlock('t1', $text, 'table'),
            $this->makeBlock('t2', $text, 'table'),
            $this->makeBlock('i1', $text, 'image'),
            $this->makeBlock('i2', $text, 'image'),
        ]);

        $this->artisan('docs:dedup-blocks')
            ->expectsOutputToContain('Dedup complete. Updated 0 document(s); removed 0 block(s).')
            ->assertExitCode(0);

        $doc = $this->store->getReviewDocument($id);

        $this->assertCount(4, $doc['pages'][0]['blocks']);
    }

    public function test_dry_run_does_not_modify_document(): void
    {
        $id = 'doc_dedup_dry_'.uniqid();
        $longText = str_repeat('ทดสอบ', 10);

        $this->seedDocument($id, [
            $this->makeBlock('b1', $longText),
            $this->makeBlock('b2', $longText),
        ]);

        $this->artisan('docs:dedup-blocks --dry-run')
            ->expectsOutputToContain("Would remove 1 duplicate block(s) from {$id}.")
            ->expectsOutputToContain('Dry run complete. 1 document(s) would change; 1 block(s) would be removed.')
            ->assertExitCode(0);

        $doc = $this->store->getReviewDocument($id);

        $this->assertCount(2, $doc['pages'][0]['blocks']);
        $this->assertSame(2, $doc['summary']['block_count']);
    }
}
