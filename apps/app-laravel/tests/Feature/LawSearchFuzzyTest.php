<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LawSearchFuzzyTest extends TestCase
{
    private function seedLaw(ReviewStore $s, string $id, string $title): void
    {
        $s->setStatus($id, ['status' => 'ingested', 'source_file' => $id.'.docx']);
        $s->writeReviewDocument($id, [
            'document_id' => $id, 'source_file' => $id.'.docx', 'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => ['title' => $title, 'access_scope' => 'public', 'law_type' => 'ระเบียบ', 'published_date' => '2565-01-01'],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_typo_query_finds_near_word_and_flags_fuzzy(): void
    {
        $s = app(ReviewStore::class);
        $id = 'fz_'.uniqid();
        $this->seedLaw($s, $id, 'ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยการเงิน');

        $res = $this->postJson('/api/laws/search', ['q' => 'มหาวิทยาลับ']);
        $res->assertOk();
        $hit = collect($res->json('results'))->firstWhere('law_id', $id);
        $this->assertNotNull($hit, 'typo query should still find the near-word title');
        $this->assertTrue($res->json('fuzzy'), 'response should flag fuzzy results');
    }

    public function test_exact_query_highlights_the_matched_term_in_title(): void
    {
        $s = app(ReviewStore::class);
        $id = 'hl_'.uniqid();
        $this->seedLaw($s, $id, 'ระเบียบว่าด้วยการเบิกจ่ายค่าเดินทาง');

        $res = $this->postJson('/api/laws/search', ['q' => 'เบิกจ่าย']);
        $hit = collect($res->json('results'))->firstWhere('law_id', $id);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('<mark>เบิกจ่าย</mark>', (string) $hit['title_highlighted']);
        $this->assertFalse($res->json('fuzzy'));
    }
}
