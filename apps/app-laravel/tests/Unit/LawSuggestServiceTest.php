<?php

namespace Tests\Unit;

use App\Services\Search\ElasticClient;
use App\Services\Search\LawSuggestService;
use Tests\TestCase;

class LawSuggestServiceTest extends TestCase
{
    public function test_builds_bool_prefix_query_for_suggestions(): void
    {
        $captured = [];
        $mock = \Mockery::mock(ElasticClient::class);
        $mock->shouldReceive('search')->once()->andReturnUsing(function (array $body) use (&$captured): array {
            $captured = $body;

            return ['hits' => ['hits' => [[
                '_source' => [
                    'law_id' => 'L1',
                    'title' => 'พระราชบัญญัติภาษี',
                    'keywords' => [],
                ],
            ]]]];
        });

        (new LawSuggestService($mock))->suggest(['q' => 'ภาษ', 'size' => 5]);

        $this->assertFalse($captured['track_total_hits']);
        $this->assertSame(5, $captured['size']);
        $this->assertSame(1, $captured['query']['bool']['filter'][0]['bool']['minimum_should_match']);
        $this->assertSame('bool_prefix', $captured['query']['bool']['should'][0]['multi_match']['type']);
        $this->assertContains('keywords_suggest._index_prefix', $captured['query']['bool']['should'][0]['multi_match']['fields']);
        $this->assertSame(['title^4', 'keywords_text^4', 'section_path^2'], $captured['query']['bool']['should'][1]['multi_match']['fields']);
        $this->assertSame('law_id', $captured['collapse']['field']);
    }

    public function test_falls_back_to_fuzzy_query_when_prefix_has_no_hits(): void
    {
        $captured = [];
        $mock = \Mockery::mock(ElasticClient::class);
        $mock->shouldReceive('search')->once()->andReturn(['hits' => ['hits' => []]]);
        $mock->shouldReceive('search')->once()->andReturnUsing(function (array $body) use (&$captured): array {
            $captured = $body;

            return ['hits' => ['hits' => [[
                '_source' => [
                    'law_id' => 'L2',
                    'title' => 'พระราชบัญญัติมหาวิทยาลัย',
                    'keywords' => ['มหาวิทยาลัย'],
                ],
            ]]]];
        });

        $out = (new LawSuggestService($mock))->suggest(['q' => 'มหาวทิยาลัย', 'size' => 5]);

        $this->assertSame('AUTO', $captured['query']['bool']['should'][0]['multi_match']['fuzziness']);
        $this->assertSame(['title^5', 'keywords_text^4', 'section_path^2'], $captured['query']['bool']['should'][0]['multi_match']['fields']);
        $this->assertSame(0, $captured['query']['bool']['should'][1]['multi_match']['prefix_length']);
        $this->assertSame('AUTO:3,6', $captured['query']['bool']['should'][1]['multi_match']['fuzziness']);
        $this->assertSame('L2', $out['suggestions'][0]['law_id']);
    }

    public function test_parses_compact_suggestion_payload(): void
    {
        $mock = \Mockery::mock(ElasticClient::class);
        $mock->shouldReceive('search')->once()->andReturn([
            'hits' => ['hits' => [[
                '_source' => [
                    'law_id' => 'L1',
                    'title' => 'พระราชบัญญัติภาษี',
                    'law_type' => 'phrb',
                    'agency' => 'กระทรวงการคลัง',
                    'published_date' => '2562',
                    'keywords' => ['ภาษีที่ดิน', 'ผู้เสียภาษี'],
                ],
            ]]],
        ]);

        $out = (new LawSuggestService($mock))->suggest(['q' => 'ภาษี']);

        $this->assertSame('L1', $out['suggestions'][0]['law_id']);
        $this->assertSame(['ภาษีที่ดิน', 'ผู้เสียภาษี'], $out['suggestions'][0]['keywords']);
    }
}
