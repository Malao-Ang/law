<?php

namespace Tests\Unit;

use App\Services\Search\ElasticClient;
use App\Services\Search\LawSearchService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LawSearchServiceTest extends TestCase
{
    public function test_builds_query_with_filters_collapse_highlight_and_aggs(): void
    {
        $captured = [];
        $mock = \Mockery::mock(ElasticClient::class);
        $mock->shouldReceive('search')->once()->andReturnUsing(function (array $body) use (&$captured): array {
            $captured = $body;

            return ['hits' => ['hits' => []], 'aggregations' => ['total_laws' => ['value' => 0]]];
        });
        $mock->shouldReceive('search')->once()->andReturn(['hits' => ['hits' => []], 'aggregations' => ['total_laws' => ['value' => 0]]]);

        $service = new LawSearchService($mock);
        $service->search([
            'q' => 'ภาษี',
            'filters' => ['law_type' => ['phrb'], 'year_from' => 2560, 'year_to' => 2565],
            'page' => 1,
            'per_page' => 20,
        ]);

        $this->assertArrayHasKey('collapse', $captured);
        $this->assertSame('law_id', $captured['collapse']['field']);
        $this->assertArrayHasKey('highlight', $captured);
        $this->assertArrayHasKey('aggs', $captured);
        $this->assertSame('ภาษี', $captured['query']['bool']['must']['bool']['should'][0]['multi_match']['query']);
        $this->assertSame(['title^5', 'keywords_text^4', 'section_path^2', 'summary^1.5', 'text'], $captured['query']['bool']['must']['bool']['should'][0]['multi_match']['fields']);
        $this->assertSame('ภาษี', $captured['query']['bool']['must']['bool']['should'][1]['term']['keywords']['value']);

        $filters = Collection::make($captured['query']['bool']['filter']);
        $this->assertTrue($filters->contains(fn (array $filter): bool => ($filter['bool']['minimum_should_match'] ?? null) === 1));
        $this->assertTrue($filters->contains(['terms' => ['law_type' => ['phrb']]]));
        $this->assertTrue($filters->contains(fn (array $filter): bool => isset($filter['range']['published_year'])));
    }

    public function test_parses_response_into_results_and_facets(): void
    {
        $raw = [
            'aggregations' => [
                'total_laws' => ['value' => 1],
                'law_type' => ['buckets' => [['key' => 'phrb', 'doc_count' => 3]]],
                'status' => ['buckets' => []],
                'change_status' => ['buckets' => []],
                'agency' => ['buckets' => []],
                'law_group' => ['buckets' => []],
                'signer_group' => ['buckets' => []],
                'years' => ['buckets' => []],
            ],
            'hits' => ['max_score' => 12.0, 'hits' => [[
                '_score' => 12.0,
                '_source' => [
                    'law_id' => 'L1',
                    'title' => 'พ.ร.บ. ทดสอบ',
                    'law_type' => 'phrb',
                    'status' => 'active',
                    'summary' => 's',
                    'published_date' => '2565',
                    'agency' => 'ก',
                ],
                'inner_hits' => ['snippets' => ['hits' => ['hits' => [
                    ['highlight' => ['text' => ['ว่าด้วย<mark>ภาษี</mark>อากร']]],
                ]]]],
            ]]],
        ];

        $mock = \Mockery::mock(ElasticClient::class);
        $mock->shouldReceive('search')->once()->andReturn($raw);

        $out = (new LawSearchService($mock))->search([
            'q' => 'ภาษี',
            'filters' => [],
            'page' => 1,
            'per_page' => 20,
        ]);

        $this->assertSame(1, $out['total']);
        $this->assertCount(1, $out['results']);
        $this->assertSame('L1', $out['results'][0]['law_id']);
        $this->assertSame(['ว่าด้วย<mark>ภาษี</mark>อากร'], $out['results'][0]['snippets']);
        $this->assertSame(1.0, $out['results'][0]['confidence']);
        $this->assertSame('exact', $out['results'][0]['match_mode']);
        $this->assertSame([['value' => 'phrb', 'count' => 3]], $out['facets']['law_type']);
        $this->assertSame('elastic', $out['meta']['engine']);
        $this->assertSame('exact', $out['meta']['mode']);
        $this->assertSame(1.0, $out['meta']['confidence']);
    }

    public function test_short_queries_do_not_trigger_fuzzy_retry(): void
    {
        $calls = 0;
        $mock = \Mockery::mock(ElasticClient::class);
        $mock->shouldReceive('search')->once()->andReturnUsing(function () use (&$calls): array {
            $calls++;

            return ['hits' => ['hits' => []], 'aggregations' => ['total_laws' => ['value' => 0]]];
        });

        (new LawSearchService($mock))->search([
            'q' => 'ภาษ',
            'filters' => [],
            'page' => 1,
            'per_page' => 20,
        ]);

        $this->assertSame(1, $calls);
    }

    public function test_empty_exact_results_retry_with_fuzzy_query(): void
    {
        $bodies = [];
        $empty = ['hits' => ['hits' => []], 'aggregations' => ['total_laws' => ['value' => 0]]];
        $raw = [
            'aggregations' => [
                'total_laws' => ['value' => 1],
                'law_type' => ['buckets' => []],
                'status' => ['buckets' => []],
                'change_status' => ['buckets' => []],
                'agency' => ['buckets' => []],
                'law_group' => ['buckets' => []],
                'signer_group' => ['buckets' => []],
                'years' => ['buckets' => []],
            ],
            'hits' => ['max_score' => 5.0, 'hits' => [[
                '_score' => 5.0,
                '_source' => ['law_id' => 'L2', 'title' => 'ประกาศมหาวิทยาลัย'],
            ]]],
        ];

        $mock = \Mockery::mock(ElasticClient::class);
        $mock->shouldReceive('search')->twice()->andReturnUsing(function (array $body) use (&$bodies, $empty, $raw): array {
            $bodies[] = $body;

            return count($bodies) === 1 ? $empty : $raw;
        });

        $out = (new LawSearchService($mock))->search([
            'q' => 'มหาวิทยาลับ',
            'filters' => [],
            'page' => 1,
            'per_page' => 20,
        ]);

        $this->assertSame('fuzzy', $out['results'][0]['match_mode']);
        $this->assertSame(0.7, $out['results'][0]['confidence']);
        $this->assertSame('fuzzy', $out['meta']['mode']);
        $this->assertSame('AUTO', $bodies[1]['query']['bool']['must']['bool']['should'][1]['multi_match']['fuzziness']);
    }
}
