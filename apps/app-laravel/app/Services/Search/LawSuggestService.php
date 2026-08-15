<?php

namespace App\Services\Search;

class LawSuggestService
{
    private const FUZZY_MIN_QUERY_LENGTH = 4;

    public function __construct(private readonly ElasticClient $client) {}

    /**
     * @param  array{q:string,size?:int}  $params
     * @return array{suggestions: array<int, array<string, mixed>>}
     */
    public function suggest(array $params): array
    {
        $query = trim((string) ($params['q'] ?? ''));
        $size = min(10, max(1, (int) ($params['size'] ?? 8)));

        if (mb_strlen($query) < 2) {
            return ['suggestions' => []];
        }

        $raw = $this->client->search($this->buildPrefixBody($query, $size));

        if (($raw['hits']['hits'] ?? []) === [] && mb_strlen($query) >= self::FUZZY_MIN_QUERY_LENGTH) {
            $raw = $this->client->search($this->buildFuzzyBody($query, $size));
        }

        return ['suggestions' => $this->parseSuggestions($raw)];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPrefixBody(string $query, int $size): array
    {
        return [
            'track_total_hits' => false,
            'size' => $size,
            '_source' => ['law_id', 'title', 'law_type', 'agency', 'published_date', 'keywords'],
            'query' => [
                'bool' => [
                    'filter' => [[
                        'bool' => [
                            'should' => [
                                ['term' => ['visibility' => 'public']],
                                ['term' => ['access_scope' => 'public']],
                                ['bool' => ['must_not' => [['exists' => ['field' => 'visibility']]]]],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ]],
                    'should' => [
                        [
                            'multi_match' => [
                                'query' => $query,
                                'type' => 'bool_prefix',
                                'fields' => [
                                    'title_suggest',
                                    'title_suggest._2gram',
                                    'title_suggest._3gram',
                                    'title_suggest._index_prefix',
                                    'keywords_suggest',
                                    'keywords_suggest._2gram',
                                    'keywords_suggest._3gram',
                                    'keywords_suggest._index_prefix',
                                    'section_path_suggest',
                                    'section_path_suggest._2gram',
                                    'section_path_suggest._3gram',
                                    'section_path_suggest._index_prefix',
                                ],
                                'boost' => 3,
                            ],
                        ],
                        [
                            'multi_match' => [
                                'query' => $query,
                                'type' => 'best_fields',
                                'fields' => ['title^4', 'keywords_text^4', 'section_path^2'],
                            ],
                        ],
                        [
                            'wildcard' => [
                                'keywords' => [
                                    'value' => "*{$query}*",
                                    'boost' => 4,
                                ],
                            ],
                        ],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
            'collapse' => ['field' => 'law_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFuzzyBody(string $query, int $size): array
    {
        return [
            'track_total_hits' => false,
            'size' => $size,
            '_source' => ['law_id', 'title', 'law_type', 'agency', 'published_date', 'keywords'],
            'query' => [
                'bool' => [
                    'filter' => [[
                        'bool' => [
                            'should' => [
                                ['term' => ['visibility' => 'public']],
                                ['term' => ['access_scope' => 'public']],
                                ['bool' => ['must_not' => [['exists' => ['field' => 'visibility']]]]],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ]],
                    'should' => [
                        [
                            'multi_match' => [
                                'query' => $query,
                                'type' => 'best_fields',
                                'fields' => ['title^5', 'keywords_text^4', 'section_path^2'],
                                'fuzziness' => 'AUTO',
                                'prefix_length' => 1,
                                'max_expansions' => 25,
                                'boost' => 2,
                            ],
                        ],
                        [
                            'multi_match' => [
                                'query' => $query,
                                'type' => 'best_fields',
                                'fields' => ['title^4', 'keywords_text^4'],
                                'fuzziness' => 'AUTO:3,6',
                                'prefix_length' => 0,
                                'max_expansions' => 50,
                                'operator' => 'and',
                            ],
                        ],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
            'collapse' => ['field' => 'law_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function parseSuggestions(array $raw): array
    {
        $suggestions = [];
        foreach ($raw['hits']['hits'] ?? [] as $hit) {
            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            $suggestions[] = [
                'law_id' => $source['law_id'] ?? null,
                'title' => $source['title'] ?? null,
                'law_type' => $source['law_type'] ?? null,
                'agency' => $source['agency'] ?? null,
                'published_date' => $source['published_date'] ?? null,
                'keywords' => array_values(array_filter((array) ($source['keywords'] ?? []), 'is_string')),
            ];
        }

        return $suggestions;
    }
}
