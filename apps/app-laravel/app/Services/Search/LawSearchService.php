<?php

namespace App\Services\Search;

class LawSearchService
{
    private const TERM_FILTERS = ['law_type', 'status', 'change_status', 'agency', 'law_group', 'signer_group'];
    private const FUZZY_MIN_QUERY_LENGTH = 4;

    public function __construct(private readonly ElasticClient $client) {}

    /**
     * @param  array{q?:string,filters?:array<string,mixed>,page?:int,per_page?:int}  $params
     * @return array<string,mixed>
     */
    public function search(array $params): array
    {
        $q = trim((string) ($params['q'] ?? ''));
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, (int) ($params['per_page'] ?? 20));

        $body = $this->buildExactBody($q, $filters, $page, $perPage);
        $raw = $this->client->search($body);

        if ($q !== '' && mb_strlen($q) >= self::FUZZY_MIN_QUERY_LENGTH && ($raw['hits']['hits'] ?? []) === []) {
            $raw = $this->client->search($this->buildFuzzyBody($q, $filters, $page, $perPage));
        }

        return $this->parse($raw);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function buildExactBody(string $q, array $filters, int $page, int $perPage): array
    {
        if ($q === '') {
            $must = ['match_all' => (object) []];
        } else {
            $must = [
                'bool' => [
                    'should' => [
                        [
                            'multi_match' => [
                                'query' => $q,
                                'type' => 'best_fields',
                                'fields' => ['title^5', 'keywords_text^4', 'section_path^2', 'summary^1.5', 'text'],
                            ],
                        ],
                        [
                            'term' => [
                                'keywords' => [
                                    'value' => $q,
                                    'boost' => 8,
                                ],
                            ],
                        ],
                    ],
                    'minimum_should_match' => 1,
                ],
            ];
        }

        return $this->buildBodyFromMust($must, $filters, $page, $perPage);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function buildFuzzyBody(string $q, array $filters, int $page, int $perPage): array
    {
        $must = [
            'bool' => [
                'should' => [
                    [
                        'multi_match' => [
                            'query' => $q,
                            'type' => 'best_fields',
                            'fields' => ['title^5', 'keywords_text^4', 'section_path^2', 'summary^1.5', 'text'],
                            'boost' => 3,
                        ],
                    ],
                    [
                        'multi_match' => [
                            'query' => $q,
                            'type' => 'best_fields',
                            'fields' => ['title^3', 'keywords_text^3', 'section_path^2', 'summary', 'text'],
                            'fuzziness' => 'AUTO',
                            'prefix_length' => 2,
                            'max_expansions' => 20,
                            'boost' => 0.5,
                        ],
                    ],
                ],
                'minimum_should_match' => 1,
            ],
        ];

        return $this->buildBodyFromMust($must, $filters, $page, $perPage);
    }

    /**
     * @param  array<string,mixed>  $must
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function buildBodyFromMust(array $must, array $filters, int $page, int $perPage): array
    {

        $filterClauses = [$this->publicAccessFilter()];
        foreach (self::TERM_FILTERS as $field) {
            if (! empty($filters[$field])) {
                $filterClauses[] = ['terms' => [$field => array_values((array) $filters[$field])]];
            }
        }

        $range = [];
        if (! empty($filters['year_from'])) {
            $range['gte'] = (int) $filters['year_from'];
        }
        if (! empty($filters['year_to'])) {
            $range['lte'] = (int) $filters['year_to'];
        }
        if ($range !== []) {
            $filterClauses[] = ['range' => ['published_year' => $range]];
        }

        $highlight = [
            'pre_tags' => ['<mark>'],
            'post_tags' => ['</mark>'],
            'fields' => [
                'text' => (object) [],
                'title' => (object) [],
                'keywords_text' => (object) [],
            ],
        ];

        $aggs = [
            'total_laws' => ['cardinality' => ['field' => 'law_id']],
        ];

        foreach (self::TERM_FILTERS as $field) {
            $aggs[$field] = ['terms' => ['field' => $field, 'size' => 50]];
        }
        $aggs['years'] = ['terms' => ['field' => 'published_year', 'size' => 50]];

        return [
            'query' => ['bool' => ['must' => $must, 'filter' => $filterClauses]],
            'collapse' => [
                'field' => 'law_id',
                'inner_hits' => [
                    'name' => 'snippets',
                    'size' => 3,
                    'highlight' => $highlight,
                ],
            ],
            'highlight' => $highlight,
            'aggs' => $aggs,
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function parse(array $raw): array
    {
        $results = [];
        foreach ($raw['hits']['hits'] ?? [] as $hit) {
            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            $snippets = [];

            foreach ($hit['inner_hits']['snippets']['hits']['hits'] ?? [] as $innerHit) {
                foreach (['text', 'title'] as $field) {
                    foreach ($innerHit['highlight'][$field] ?? [] as $fragment) {
                        $snippets[] = $fragment;
                    }
                }
            }

            if ($snippets === []) {
                foreach (['text', 'title'] as $field) {
                    foreach ($hit['highlight'][$field] ?? [] as $fragment) {
                        $snippets[] = $fragment;
                    }
                }
            }

            if ($snippets === [] && isset($source['text'])) {
                $snippets[] = mb_substr((string) $source['text'], 0, 200);
            }

            $results[] = [
                'law_id' => $source['law_id'] ?? null,
                'title' => $source['title'] ?? null,
                'law_type' => $source['law_type'] ?? null,
                'status' => $source['status'] ?? null,
                'change_status' => $source['change_status'] ?? null,
                'summary' => $source['summary'] ?? null,
                'published_date' => $source['published_date'] ?? null,
                'agency' => $source['agency'] ?? null,
                'signer_group' => $source['signer_group'] ?? null,
                'snippets' => array_values(array_unique($snippets)),
            ];
        }

        $facets = [];
        foreach (self::TERM_FILTERS as $field) {
            $facets[$field] = array_map(
                fn (array $bucket): array => ['value' => (string) $bucket['key'], 'count' => (int) $bucket['doc_count']],
                $raw['aggregations'][$field]['buckets'] ?? [],
            );
        }
        $facets['years'] = array_map(
            fn (array $bucket): array => ['year' => (int) $bucket['key'], 'count' => (int) $bucket['doc_count']],
            $raw['aggregations']['years']['buckets'] ?? [],
        );

        return [
            'total' => (int) ($raw['aggregations']['total_laws']['value'] ?? 0),
            'results' => $results,
            'facets' => $facets,
        ];
    }

    /**
     * Keep legacy indexed docs visible until they are reindexed with access_scope.
     *
     * @return array<string, mixed>
     */
    private function publicAccessFilter(): array
    {
        return [
            'bool' => [
                'should' => [
                    ['term' => ['access_scope' => 'public']],
                    ['bool' => ['must_not' => [['exists' => ['field' => 'access_scope']]]]],
                ],
                'minimum_should_match' => 1,
            ],
        ];
    }
}
