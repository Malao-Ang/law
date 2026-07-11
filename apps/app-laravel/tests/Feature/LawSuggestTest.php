<?php

namespace Tests\Feature;

use App\Services\Search\LawSuggestService;
use Tests\TestCase;

class LawSuggestTest extends TestCase
{
    public function test_suggest_endpoint_returns_compact_results(): void
    {
        $fake = [
            'suggestions' => [[
                'law_id' => 'L1',
                'title' => 'พระราชบัญญัติภาษี',
                'law_type' => 'phrb',
                'agency' => 'กระทรวงการคลัง',
                'published_date' => '2562',
                'keywords' => ['ภาษีที่ดิน'],
            ]],
        ];

        $this->mock(LawSuggestService::class, fn ($mock) => $mock->shouldReceive('suggest')->once()->andReturn($fake));

        $this->postJson('/api/laws/suggest', ['q' => 'ภาษี'])
            ->assertOk()
            ->assertJsonPath('suggestions.0.law_id', 'L1')
            ->assertJsonPath('suggestions.0.keywords.0', 'ภาษีที่ดิน');
    }

    public function test_suggest_endpoint_returns_503_when_es_unavailable(): void
    {
        $this->mock(LawSuggestService::class, fn ($mock) => $mock->shouldReceive('suggest')->andThrow(new \RuntimeException('no route to host')));

        $this->postJson('/api/laws/suggest', ['q' => 'ภาษี'])->assertStatus(503);
    }
}
