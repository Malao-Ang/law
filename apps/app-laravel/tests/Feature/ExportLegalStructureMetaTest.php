<?php

namespace Tests\Feature;

use App\Services\ExportService;
use App\Services\ReviewStore;
use Tests\TestCase;

class ExportLegalStructureMetaTest extends TestCase
{
    public function test_block_chunks_carry_legal_structure_of_their_section_head(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_export_lst_'.uniqid();
        $block = static fn (string $bid, int $order, string $type, string $text, ?string $chunkType): array => [
            'block_id' => $bid,
            'type' => $type,
            'reading_order' => $order,
            'raw_text' => $text,
            'normalized_text' => $text,
            'ai_suggested_text' => $text,
            'approved_text' => $text,
            'confidence' => 1.0,
            'needs_review' => false,
            'flags' => [],
            'meta' => $chunkType === null ? [] : ['chunk_type' => $chunkType],
        ];

        $store->setStatus($id, ['status' => 'done', 'source_file' => 'x.docx']);
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'x.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 4, 'review_required_count' => 0],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [
                    $block('b1', 1, 'section_header', 'ข้อ 1', 'CLAUSE'),
                    $block('b2', 2, 'paragraph', 'เนื้อหาข้อ 1', null),
                    $block('b3', 3, 'section_header', 'หมวด 1', 'LST012'),
                    $block('b4', 4, 'paragraph', 'เนื้อหาหมวด', null),
                ],
            ]],
        ]);

        $result = app(ExportService::class)->export($id);
        $path = str_replace('storage/app/poc/', '', (string) $result['export_path']);
        $export = json_decode((string) file_get_contents($store->absolutePath($path)), true, 512, JSON_THROW_ON_ERROR);

        $byText = [];
        foreach ($export['chunks'] as $chunk) {
            $byText[$chunk['text']] = $chunk['meta']['legal_structure'] ?? null;
        }

        $this->assertSame(['code' => 'LST004', 'export_key' => 'CLAUSE'], $this->firstContaining($byText, 'เนื้อหาข้อ 1'));
        $this->assertSame(['code' => 'LST012', 'export_key' => 'CHAPTER'], $this->firstContaining($byText, 'เนื้อหาหมวด'));
    }

    /**
     * @param  array<string, mixed>  $byText
     */
    private function firstContaining(array $byText, string $needle): mixed
    {
        foreach ($byText as $text => $meta) {
            if (str_contains((string) $text, $needle)) {
                return $meta;
            }
        }

        $this->fail("No chunk contains {$needle}: ".json_encode(array_keys($byText), JSON_UNESCAPED_UNICODE));
    }
}
