<?php

namespace App\Services\Search;

class LawSearchService
{
    private const TERM_FILTERS = ['law_type', 'status', 'change_status', 'agency', 'law_group', 'signer_group'];
    private const FUZZY_MIN_QUERY_LENGTH = 4;
    private const EXTERNAL_LAW_TYPE_ALIASES = [
        'กฎหมายภายนอก',
        'พระราชบัญญัติ',
        'พระราชกำหนด',
        'กฎกระทรวง',
        'ประกาศกระทรวง',
        'พ.ร.บ.',
        'พ.ร.บ',
        'พรบ',
        'พ.ร.ก.',
        'พ.ร.ก',
        'พรก',
        'phrb',
        'prb',
        'phrk',
        'kotmai-krung',
        'kotmai-phaainok',
        'kot-krathruang',
        'kotmai-krw',
        'prakat-krw',
    ];
    private const EXTERNAL_LAW_GROUP_ALIASES = [
        'กฎหมายภายนอก',
        'kotmai-phaainok',
        'kotmai-krung',
    ];
    private const LAW_TYPE_FILTER_ALIASES = [
        'external' => ['กฎหมายภายนอก', 'kotmai-phaainok', 'kotmai-krung'],
        'external-act' => ['พระราชบัญญัติ', 'พ.ร.บ.', 'พ.ร.บ', 'พรบ', 'phrb', 'prb'],
        'external-decree' => ['พระราชกำหนด', 'พ.ร.ก.', 'พ.ร.ก', 'พรก', 'phrk'],
        'external-ministerial-rule' => ['กฎกระทรวง', 'kot-krathruang', 'kotmai-krw'],
        'external-ministerial-announcement' => ['ประกาศกระทรวง', 'prakat-krw'],
    ];

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

        $mode = 'exact';
        $body = $this->buildExactBody($q, $filters, $page, $perPage);
        $raw = $this->client->search($body);

        $parsed = LawSearchQuery::parse($q);
        if ($q !== '' && mb_strlen($q) >= self::FUZZY_MIN_QUERY_LENGTH) {
            if (($raw['hits']['hits'] ?? []) === []) {
                // NOT-only queries legitimately return hits without positive terms;
                // skip the fuzzy fallback so they are not re-queried unnecessarily.
                if (! $parsed->isNegatedOnly()) {
                    $mode = 'fuzzy';
                    $raw = $this->client->search($this->buildFuzzyBody($q, $filters, $page, $perPage));
                }
            } else {
                // For NOT-only queries there are no positive variants to check against hits;
                // classify as exact to avoid a spurious 'fuzzy' mode label.
                $mode = $parsed->isNegatedOnly()
                    ? 'exact'
                    : $this->modeFromExactHits($raw, $parsed->rawVariants());
            }
        }

        return $this->parse($raw, $mode);
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
            $query = LawSearchQuery::parse($q);
            $must = $query->requiresExpandedDsl() ? $this->buildExpandedMust($query, false) : [
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
                        [
                            'multi_match' => [
                                'query' => $q,
                                'type' => 'best_fields',
                                'fields' => ['title^2', 'keywords_text^2', 'text'],
                                'fuzziness' => 'AUTO',
                                'prefix_length' => 2,
                                'max_expansions' => 10,
                                'boost' => 0.3,
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
        $query = LawSearchQuery::parse($q);
        $must = $query->requiresExpandedDsl() ? $this->buildExpandedMust($query, true) : [
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
     * @param  array<string,mixed>  $raw
     * @param  array<int,string>  $variants
     */
    private function modeFromExactHits(array $raw, array $variants): string
    {
        $hits = $raw['hits']['hits'] ?? [];
        if ($hits === [] || $variants === []) {
            return 'exact';
        }

        $exact = 0;
        foreach ($hits as $hit) {
            if ($this->hitContainsQueryVariant($hit, $variants)) {
                $exact++;
            }
        }

        return $exact === 0 ? 'fuzzy' : 'exact';
    }

    /**
     * @param  array<string,mixed>  $hit
     * @param  array<int,string>  $variants
     */
    private function hitContainsQueryVariant(array $hit, array $variants): bool
    {
        $haystacks = [];
        $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
        foreach (['title', 'keywords_text', 'section_path', 'summary', 'text'] as $field) {
            if (isset($source[$field])) {
                $haystacks[] = (string) $source[$field];
            }
        }
        foreach ((array) ($source['keywords'] ?? []) as $keyword) {
            $haystacks[] = (string) $keyword;
        }

        foreach (['text', 'title', 'keywords_text'] as $field) {
            foreach ($hit['highlight'][$field] ?? [] as $fragment) {
                $haystacks[] = strip_tags((string) $fragment);
            }
            foreach ($hit['inner_hits']['snippets']['hits']['hits'] ?? [] as $innerHit) {
                foreach ($innerHit['highlight'][$field] ?? [] as $fragment) {
                    $haystacks[] = strip_tags((string) $fragment);
                }
            }
        }

        foreach ($variants as $variant) {
            $variant = trim($variant);
            if ($variant === '') {
                continue;
            }
            foreach ($haystacks as $haystack) {
                if (mb_stripos($haystack, $variant) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildExpandedMust(LawSearchQuery $query, bool $fuzzy): array
    {
        $groups = [];
        foreach ($query->orGroups() as $group) {
            $must = [];
            $mustNot = [];
            foreach ($group as $term) {
                $clause = $this->termSearchClause($term['variants'], $term['quoted'], $fuzzy && ! $term['negated']);
                if ($term['negated']) {
                    $mustNot[] = $clause;
                } else {
                    $must[] = $clause;
                }
            }

            $bool = [];
            if ($must !== []) {
                $bool['must'] = $must;
            } elseif ($mustNot !== []) {
                // NOT-only group: must match everything except negated terms
                $bool['must'] = [['match_all' => (object) []]];
            }
            if ($mustNot !== []) {
                $bool['must_not'] = $mustNot;
            }
            if ($bool !== []) {
                $groups[] = ['bool' => $bool];
            }
        }

        if ($groups === []) {
            return ['match_all' => (object) []];
        }

        return [
            'bool' => [
                'should' => $groups,
                'minimum_should_match' => 1,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $variants
     * @return array<string,mixed>
     */
    private function termSearchClause(array $variants, bool $quoted, bool $fuzzy): array
    {
        $should = [];
        foreach ($variants as $variant) {
            $should[] = [
                'multi_match' => [
                    'query' => $variant,
                    'type' => $quoted ? 'phrase' : 'best_fields',
                    'fields' => ['title^5', 'keywords_text^4', 'section_path^2', 'summary^1.5', 'text'],
                ],
            ];
            $should[] = [
                'term' => [
                    'keywords' => [
                        'value' => $variant,
                        'boost' => 8,
                    ],
                ],
            ];
            if ($fuzzy && ! $quoted && mb_strlen($variant) >= self::FUZZY_MIN_QUERY_LENGTH) {
                $should[] = [
                    'multi_match' => [
                        'query' => $variant,
                        'type' => 'best_fields',
                        'fields' => ['title^3', 'keywords_text^3', 'section_path^2', 'summary', 'text'],
                        'fuzziness' => 'AUTO',
                        'prefix_length' => 2,
                        'max_expansions' => 20,
                        'boost' => 0.5,
                    ],
                ];
            }
        }

        return [
            'bool' => [
                'should' => $should,
                'minimum_should_match' => 1,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $must
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function buildBodyFromMust(array $must, array $filters, int $page, int $perPage): array
    {

        $filterClauses = [$this->visibleAccessFilter()];
        foreach (self::TERM_FILTERS as $field) {
            if (! empty($filters[$field])) {
                $values = array_values((array) $filters[$field]);
                if ($field === 'law_type') {
                    $values = $this->expandLawTypeFilterValues($values);
                }
                $filterClauses[] = ['terms' => [$field => $values]];
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
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function expandLawTypeFilterValues(array $values): array
    {
        $expanded = [];
        foreach ($values as $value) {
            $type = trim((string) $value);
            if ($type === '') {
                continue;
            }
            $key = $this->lawTypeFilterKey($type);
            if ($key === 'external') {
                array_push($expanded, ...self::EXTERNAL_LAW_TYPE_ALIASES);
                continue;
            }
            $aliases = self::LAW_TYPE_FILTER_ALIASES[$key] ?? [$type];
            foreach ($aliases as $alias) {
                $expanded[] = $alias;
            }
        }

        return array_values(array_unique($expanded));
    }

    private function canonicalLawType(string $lawType): string
    {
        $lawType = trim($lawType);
        $compact = preg_replace('/\s+/u', '', mb_strtolower($lawType)) ?? mb_strtolower($lawType);

        return match (true) {
            in_array($compact, self::EXTERNAL_LAW_TYPE_ALIASES, true),
                str_contains($lawType, 'พระราชบัญญัติ'),
                str_contains($lawType, 'พระราชกำหนด'),
                str_contains($lawType, 'กฎกระทรวง'),
                str_contains($lawType, 'ประกาศกระทรวง') => 'kotmai-phaainok',
            default => 'other',
        };
    }

    private function lawTypeFilterKey(string $lawType): string
    {
        $lawType = trim($lawType);
        $compact = preg_replace('/\s+/u', '', mb_strtolower($lawType)) ?? mb_strtolower($lawType);

        return match (true) {
            in_array($compact, self::EXTERNAL_LAW_GROUP_ALIASES, true) => 'external',
            str_contains($lawType, 'พระราชบัญญัติ'),
                in_array($compact, ['พ.ร.บ.', 'พ.ร.บ', 'พรบ', 'phrb', 'prb'], true) => 'external-act',
            str_contains($lawType, 'พระราชกำหนด'),
                in_array($compact, ['พ.ร.ก.', 'พ.ร.ก', 'พรก', 'phrk'], true) => 'external-decree',
            str_contains($lawType, 'กฎกระทรวง'),
                in_array($compact, ['kot-krathruang', 'kotmai-krw'], true) => 'external-ministerial-rule',
            str_contains($lawType, 'ประกาศกระทรวง'),
                $compact === 'prakat-krw' => 'external-ministerial-announcement',
            default => 'other',
        };
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function parse(array $raw, string $mode): array
    {
        $results = [];
        $maxScore = $this->maxScore($raw);
        foreach ($raw['hits']['hits'] ?? [] as $hit) {
            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            $snippets = [];
            $score = is_numeric($hit['_score'] ?? null) ? (float) $hit['_score'] : null;
            $confidence = $this->confidenceFromScore($score, $maxScore, $mode);

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

            $restricted = $this->isRestrictedSource($source);

            $titleHighlighted = null;
            $titleHighlightFragments = $hit['highlight']['title'] ?? [];
            if ($titleHighlightFragments !== []) {
                $titleHighlighted = $titleHighlightFragments[0];
            }
            if ($titleHighlighted === null) {
                foreach ($hit['inner_hits']['snippets']['hits']['hits'] ?? [] as $innerHit) {
                    $innerTitleFragments = $innerHit['highlight']['title'] ?? [];
                    if ($innerTitleFragments !== []) {
                        $titleHighlighted = $innerTitleFragments[0];
                        break;
                    }
                }
            }

            $results[] = [
                'law_id' => $source['law_id'] ?? null,
                'title' => $source['title'] ?? null,
                'title_highlighted' => $titleHighlighted,
                'law_type' => $source['law_type'] ?? null,
                'status' => $source['status'] ?? null,
                'change_status' => $source['change_status'] ?? null,
                'summary' => $source['summary'] ?? null,
                'published_date' => $source['published_date'] ?? null,
                'agency' => $source['agency'] ?? null,
                'law_group' => $source['law_group'] ?? null,
                'signer_group' => $source['signer_group'] ?? null,
                'restricted' => $restricted,
                'requires_permission' => $restricted && $this->hasPermissionGroups($source),
                'child_types' => (object) [],
                'confidence' => $confidence,
                'match_mode' => $mode,
                'snippets' => $restricted ? [] : array_values(array_unique($snippets)),
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
            'meta' => [
                'engine' => 'elastic',
                'mode' => $mode,
                'confidence' => $this->aggregateConfidence($results),
                'suggestions' => [],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    private function maxScore(array $raw): float
    {
        if (is_numeric($raw['hits']['max_score'] ?? null) && (float) $raw['hits']['max_score'] > 0) {
            return (float) $raw['hits']['max_score'];
        }

        $scores = array_map(
            static fn (array $hit): float => is_numeric($hit['_score'] ?? null) ? (float) $hit['_score'] : 0.0,
            $raw['hits']['hits'] ?? [],
        );

        return max([1.0, ...$scores]);
    }

    private function confidenceFromScore(?float $score, float $maxScore, string $mode): float
    {
        if ($score === null || $score <= 0) {
            return $mode === 'fuzzy' ? 0.55 : 0.72;
        }

        $normalized = min(1.0, max(0.0, $score / max(0.0001, $maxScore)));
        $confidence = $mode === 'fuzzy'
            ? 0.42 + ($normalized * 0.28)
            : 0.70 + ($normalized * 0.30);

        return round(min(1.0, $confidence), 2);
    }

    /**
     * @param  array<int, array<string,mixed>>  $results
     */
    private function aggregateConfidence(array $results): float
    {
        if ($results === []) {
            return 0.0;
        }

        return round(max(array_map(
            static fn (array $result): float => is_numeric($result['confidence'] ?? null) ? (float) $result['confidence'] : 0.0,
            $results,
        )), 2);
    }

    /**
     * Keep indexed public docs and private teasers visible.
     *
     * @return array<string, mixed>
     */
    private function visibleAccessFilter(): array
    {
        return [
            'bool' => [
                'should' => [
                    ['term' => ['visibility' => 'public']],
                    ['term' => ['visibility' => 'restricted']],
                    ['term' => ['access_scope' => 'public']],
                    ['term' => ['access_scope' => 'private']],
                    ['bool' => ['must_not' => [['exists' => ['field' => 'visibility']]]]],
                ],
                'minimum_should_match' => 1,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function isRestrictedSource(array $source): bool
    {
        return ($source['visibility'] ?? null) === 'restricted'
            || ($source['access_scope'] ?? null) === 'private';
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function hasPermissionGroups(array $source): bool
    {
        return is_array($source['permission_group_ids'] ?? null)
            && array_values(array_filter(
                $source['permission_group_ids'],
                static fn (mixed $value): bool => trim((string) $value) !== '',
            )) !== [];
    }
}
