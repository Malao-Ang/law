<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LawSearchRequest;
use App\Services\ReviewStore;
use App\Services\Search\LawSearchService;
use App\Services\Search\LawSuggestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LawSearchController extends Controller
{
    public function search(LawSearchRequest $request, LawSearchService $service, LawSuggestService $suggestService, ReviewStore $store): JsonResponse
    {
        $params = $request->validated();

        // File-based is authoritative: it reads status files directly and always
        // reflects the current publish state without depending on ES being up-to-date.
        $fileBased = $this->fileBasedSearch($params, $store);

        try {
            $esResult = $service->search($params);
            if (($esResult['total'] ?? 0) > 0) {
                // ES has scored results; supplement with any ingested docs ES missed
                $esLawIds = array_fill_keys(array_column($esResult['results'] ?? [], 'law_id'), true);
                $supplement = array_values(array_filter(
                    $fileBased['results'],
                    fn (array $r): bool => ! isset($esLawIds[$r['law_id']]),
                ));
                $results = array_merge($esResult['results'] ?? [], $supplement);

                return response()->json($this->withSearchSuggestions([
                    'total' => ($esResult['total'] ?? 0) + count($supplement),
                    'results' => $results,
                    'facets' => $esResult['facets'] ?? $fileBased['facets'],
                    'fuzzy' => false,
                    'meta' => [
                        'engine' => $supplement === [] ? 'elastic' : 'mixed',
                        'mode' => (string) ($esResult['meta']['mode'] ?? 'exact'),
                        'confidence' => $this->aggregateConfidence($results),
                        'suggestions' => $esResult['meta']['suggestions'] ?? [],
                    ],
                ], $params, $suggestService));
            }
        } catch (\Throwable $exception) {
            Log::warning('Law search failed, falling back to file-based', ['error' => $exception->getMessage()]);
        }

        return response()->json($this->withSearchSuggestions($fileBased, $params, $suggestService));
    }

    /** @param array<string,mixed> $params */
    private function fileBasedSearch(array $params, ReviewStore $store): array
    {
        $q = mb_strtolower(trim((string) ($params['q'] ?? '')));
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, (int) ($params['per_page'] ?? 20));

        $allMeta = $store->listLawMeta();
        $childTypeIndex = [];
        foreach ($allMeta as $meta) {
            $parentId = trim((string) ($meta['parent_document_id'] ?? ''));
            if ($parentId === '') {
                continue;
            }
            $type = $this->canonicalType((string) ($meta['law_type'] ?? ''));
            $childTypeIndex[$parentId][$type] = ($childTypeIndex[$parentId][$type] ?? 0) + 1;
        }

        $rows = [];
        foreach ($allMeta as $row) {
            if (($row['status'] ?? '') !== 'ingested') {
                continue;
            }

            $match = $this->fileSearchMatch($row, $q, $store);
            if ($q !== '') {
                if (! $match['matched']) {
                    continue;
                }
            }
            foreach (['law_type', 'change_status', 'signer_group'] as $field) {
                $want = $filters[$field] ?? null;
                if (! empty($want) && ! in_array($row[$field] ?? '', (array) $want, true)) {
                    continue 2;
                }
            }
            $wantAgency = $filters['agency'] ?? null;
            if (! empty($wantAgency) && array_intersect((array) $wantAgency, $row['agencies'] ?? []) === []) {
                continue;
            }
            $wantGroup = $filters['law_group'] ?? null;
            if (! empty($wantGroup) && array_intersect((array) $wantGroup, $row['law_groups'] ?? []) === []) {
                continue;
            }

            $row['_search_match_mode'] = $match['mode'];
            $row['_search_confidence'] = $match['confidence'];
            $rows[] = $row;
        }

        $total = count($rows);
        $paged = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $usedFuzzy = false;
        foreach ($rows as $r) {
            if (($r['_search_match_mode'] ?? '') === 'file_fuzzy') {
                $usedFuzzy = true;
                break;
            }
        }

        $results = array_map(function (array $r) use ($q, $store, $childTypeIndex): array {
            $restricted = (string) ($r['access_scope'] ?? 'public') === 'private';
            $id = (string) $r['document_id'];

            return [
                'law_id' => $id,
                'title' => $r['title'],
                'title_highlighted' => $this->highlightTitle((string) ($r['title'] ?? ''), $q),
                'law_type' => $r['law_type'],
                'status' => $r['meta_status'],
                'change_status' => $r['change_status'],
                'summary' => null,
                'published_date' => $r['promulgation_date'] ?? null,
                'agency' => $r['agencies'][0] ?? null,
                'law_group' => $r['law_groups'][0] ?? null,
                'signer_group' => $r['signer_group'],
                'restricted' => $restricted,
                'child_types' => $childTypeIndex[$id] ?? [],
                'confidence' => (float) ($r['_search_confidence'] ?? 0.5),
                'match_mode' => (string) ($r['_search_match_mode'] ?? 'file_browse'),
                'snippets' => $restricted ? [] : $this->makeFileBasedSnippets($id, $q, $store, (string) ($r['title'] ?? '')),
            ];
        }, $paged);

        $facets = $this->computeFileBasedFacets($rows);
        $meta = [
            'engine' => 'file',
            'mode' => $this->aggregateMode($results),
            'confidence' => $this->aggregateConfidence($results),
            'suggestions' => [],
        ];

        return ['total' => $total, 'results' => $results, 'facets' => $facets, 'meta' => $meta, 'fuzzy' => $usedFuzzy];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{matched:bool,mode:string,confidence:float}
     */
    private function fileSearchMatch(array $row, string $q, ReviewStore $store): array
    {
        if ($q === '') {
            return ['matched' => true, 'mode' => 'file_browse', 'confidence' => 0.5];
        }

        $title = (string) ($row['title'] ?? '');
        if ($title !== '' && mb_stripos($title, $q) !== false) {
            return ['matched' => true, 'mode' => 'file_exact', 'confidence' => 0.84];
        }

        $restricted = (string) ($row['access_scope'] ?? 'public') === 'private';
        if (! $restricted && $this->contentMatches((string) ($row['document_id'] ?? ''), $q, $store)) {
            return ['matched' => true, 'mode' => 'file_exact', 'confidence' => 0.7];
        }

        $similarity = $this->textSimilarity($q, $title);

        return [
            'matched' => $similarity >= 0.42,
            'mode' => 'file_fuzzy',
            'confidence' => round(0.42 + (min(1.0, $similarity) * 0.24), 2),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function withSearchSuggestions(array $payload, array $params, LawSuggestService $suggestService): array
    {
        $query = trim((string) ($params['q'] ?? ''));
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $confidence = is_numeric($meta['confidence'] ?? null)
            ? (float) $meta['confidence']
            : $this->aggregateConfidence($results);

        $meta['engine'] = (string) ($meta['engine'] ?? 'file');
        $meta['mode'] = (string) ($meta['mode'] ?? $this->aggregateMode($results));
        $meta['confidence'] = round($confidence, 2);
        $meta['suggestions'] = is_array($meta['suggestions'] ?? null) ? $meta['suggestions'] : [];

        if (
            $query !== ''
            && mb_strlen($query) >= 2
            && (((int) ($payload['total'] ?? 0)) === 0 || ($confidence < 0.5 && str_contains((string) $meta['mode'], 'fuzzy')))
        ) {
            $meta['suggestions'] = $this->suggestionTexts($query, $suggestService);
        }

        $payload['meta'] = $meta;

        return $payload;
    }

    /**
     * @return string[]
     */
    private function suggestionTexts(string $query, LawSuggestService $suggestService): array
    {
        try {
            $suggestions = $suggestService->suggest(['q' => $query, 'size' => 5])['suggestions'] ?? [];
        } catch (\Throwable $exception) {
            Log::warning('Law search suggestions failed', ['error' => $exception->getMessage()]);

            return [];
        }

        $terms = [];
        foreach ($suggestions as $suggestion) {
            $title = trim((string) ($suggestion['title'] ?? ''));
            if ($title !== '') {
                $terms[] = $title;
            }
            foreach ((array) ($suggestion['keywords'] ?? []) as $keyword) {
                $keyword = trim((string) $keyword);
                if ($keyword !== '') {
                    $terms[] = $keyword;
                }
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, 5);
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
     * @param  array<int, array<string,mixed>>  $results
     */
    private function aggregateMode(array $results): string
    {
        $modes = array_values(array_unique(array_filter(array_map(
            static fn (array $result): string => (string) ($result['match_mode'] ?? ''),
            $results,
        ))));

        if ($modes === []) {
            return 'none';
        }

        return count($modes) === 1 ? $modes[0] : 'mixed';
    }

    private function textSimilarity(string $query, string $candidate): float
    {
        $query = $this->normalizeSearchText($query);
        $candidate = $this->normalizeSearchText($candidate);
        if ($query === '' || $candidate === '') {
            return 0.0;
        }
        if (str_contains($candidate, $query)) {
            return 1.0;
        }

        $best = $this->diceSimilarity($query, $candidate);
        $queryLength = mb_strlen($query);
        $candidateLength = mb_strlen($candidate);
        $windowSize = min($candidateLength, $queryLength + 4);
        if ($windowSize >= 2 && $candidateLength > $windowSize) {
            for ($i = 0; $i <= $candidateLength - $windowSize; $i++) {
                $best = max($best, $this->diceSimilarity($query, mb_substr($candidate, $i, $windowSize)));
            }
        }

        return $best;
    }

    private function normalizeSearchText(string $text): string
    {
        $normalized = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));

        return trim($normalized);
    }

    private function diceSimilarity(string $left, string $right): float
    {
        $leftGrams = $this->characterNgrams($left);
        $rightGrams = $this->characterNgrams($right);
        if ($leftGrams === [] || $rightGrams === []) {
            return $left === $right ? 1.0 : 0.0;
        }

        $rightCounts = array_count_values($rightGrams);
        $intersection = 0;
        foreach ($leftGrams as $gram) {
            if (($rightCounts[$gram] ?? 0) > 0) {
                $intersection++;
                $rightCounts[$gram]--;
            }
        }

        return (2 * $intersection) / (count($leftGrams) + count($rightGrams));
    }

    /**
     * @return string[]
     */
    private function characterNgrams(string $text): array
    {
        $length = mb_strlen($text);
        if ($length <= 1) {
            return $text === '' ? [] : [$text];
        }

        $grams = [];
        for ($i = 0; $i < $length - 1; $i++) {
            $grams[] = mb_substr($text, $i, 2);
        }

        return $grams;
    }

    /**
     * Per-request memo of export chunks so content search + snippet building
     * read each export file only once.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $exportChunkCache = [];

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadExportChunks(string $documentId, ReviewStore $store): array
    {
        if (! array_key_exists($documentId, $this->exportChunkCache)) {
            $chunks = [];
            $exportPath = $store->absolutePath($store->exportRelativePath($documentId));
            if ($documentId !== '' && is_file($exportPath)) {
                $export = json_decode((string) file_get_contents($exportPath), true) ?: [];
                $chunks = is_array($export['chunks'] ?? null) ? $export['chunks'] : [];
            }
            // An ingested doc can lack an export file (e.g. published via a path
            // that didn't persist one) — read searchable text straight from the
            // review blocks so content search works regardless.
            if ($chunks === [] && $documentId !== '') {
                $chunks = $this->chunksFromReview($documentId, $store);
            }
            // ponytail: O(n) export/review reads per search; move to ES-only if the
            // doc set grows large enough that this fallback dominates latency.
            $this->exportChunkCache[$documentId] = $chunks;
        }

        return $this->exportChunkCache[$documentId];
    }

    /**
     * Fallback text source: concatenate block text from the review document.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chunksFromReview(string $documentId, ReviewStore $store): array
    {
        try {
            $document = $store->getReviewDocument($documentId);
        } catch (\Throwable) {
            return [];
        }

        $texts = [];
        foreach ($document['pages'] ?? [] as $page) {
            foreach ($page['blocks'] ?? [] as $block) {
                $text = trim((string) ($block['approved_text'] ?? $block['ai_suggested_text'] ?? $block['normalized_text'] ?? $block['raw_text'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return $texts === [] ? [] : [['chunk_id' => $documentId.'_review', 'text' => implode("\n", $texts)]];
    }

    /** True when $q (already lowercased) appears in any export chunk text. */
    private function contentMatches(string $documentId, string $q, ReviewStore $store): bool
    {
        if ($q === '') {
            return false;
        }

        foreach ($this->loadExportChunks($documentId, $store) as $chunk) {
            if (mb_stripos((string) ($chunk['text'] ?? ''), $q) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function makeFileBasedSnippets(string $documentId, string $q, ReviewStore $store, string $title): array
    {
        if ($q === '') {
            return [];
        }

        $snippets = [];
        foreach ($this->loadExportChunks($documentId, $store) as $chunk) {
            $text = (string) ($chunk['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $pos = mb_stripos($text, $q);
            if ($pos === false) {
                continue;
            }
            $start = max(0, $pos - 60);
            $end = min(mb_strlen($text), $pos + mb_strlen($q) + 100);
            $excerpt = ($start > 0 ? '…' : '').mb_substr($text, $start, $end - $start).($end < mb_strlen($text) ? '…' : '');
            $snippets[] = (string) preg_replace('/('.preg_quote($q, '/').')/iu', '<mark>$1</mark>', $excerpt);
            if (count($snippets) >= 2) {
                break;
            }
        }
        if ($snippets !== []) {
            return $snippets;
        }

        // Fall back to title highlight when export is absent or query is only in title
        if ($title !== '' && mb_stripos($title, $q) !== false) {
            return [(string) preg_replace('/('.preg_quote($q, '/').')/iu', '<mark>$1</mark>', $title)];
        }

        return [];
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<string, mixed>
     */
    private function computeFileBasedFacets(array $rows): array
    {
        $termFields = ['law_type' => 'law_type', 'status' => 'meta_status', 'change_status' => 'change_status', 'signer_group' => 'signer_group'];
        $facets = [];

        foreach ($termFields as $key => $field) {
            $counts = [];
            foreach ($rows as $r) {
                $v = (string) ($r[$field] ?? '');
                if ($v !== '') {
                    $counts[$v] = ($counts[$v] ?? 0) + 1;
                }
            }
            arsort($counts);
            $facets[$key] = array_values(array_map(
                fn (string $v, int $c): array => ['value' => $v, 'count' => $c],
                array_keys($counts), array_values($counts),
            ));
        }

        $agencyCounts = [];
        $groupCounts = [];
        foreach ($rows as $r) {
            foreach ((array) ($r['agencies'] ?? []) as $a) {
                if ($a !== '') {
                    $agencyCounts[(string) $a] = ($agencyCounts[(string) $a] ?? 0) + 1;
                }
            }
            foreach ((array) ($r['law_groups'] ?? []) as $g) {
                if ($g !== '') {
                    $groupCounts[(string) $g] = ($groupCounts[(string) $g] ?? 0) + 1;
                }
            }
        }
        arsort($agencyCounts);
        arsort($groupCounts);
        $facets['agency'] = array_values(array_map(fn ($v, $c) => ['value' => $v, 'count' => $c], array_keys($agencyCounts), array_values($agencyCounts)));
        $facets['law_group'] = array_values(array_map(fn ($v, $c) => ['value' => $v, 'count' => $c], array_keys($groupCounts), array_values($groupCounts)));
        $facets['years'] = [];

        return $facets;
    }

    public function facets(ReviewStore $store): JsonResponse
    {
        $termCounts = [
            'law_type' => [],
            'status' => [],
            'change_status' => [],
            'agency' => [],
            'law_group' => [],
            'signer_group' => [],
        ];
        $yearCounts = [];
        $childCount = 0;
        $parentIds = [];
        $publicCount = 0;
        $changeCounts = ['new' => 0, 'amended' => 0, 'repealed' => 0];

        foreach ($store->listLawMeta() as $row) {
            if (($row['access_scope'] ?? '') === 'private') {
                continue;
            }

            $publicCount++;
            $parentId = trim((string) ($row['parent_document_id'] ?? ''));
            if ($parentId !== '') {
                $childCount++;
                $parentIds[$parentId] = true;
            }
            $cs = (string) ($row['change_status'] ?? '');
            if (isset($changeCounts[$cs])) {
                $changeCounts[$cs]++;
            }

            $this->tally($termCounts['law_type'], $row['law_type'] ?? '');
            $this->tally($termCounts['status'], $row['meta_status'] ?? '');
            $this->tally($termCounts['change_status'], $row['change_status'] ?? '');
            $this->tally($termCounts['signer_group'], $row['signer_group'] ?? '');

            foreach ((array) ($row['agencies'] ?? []) as $agency) {
                $this->tally($termCounts['agency'], (string) $agency);
            }
            foreach ((array) ($row['law_groups'] ?? []) as $group) {
                $this->tally($termCounts['law_group'], (string) $group);
            }

            if (($row['promulgation_date'] ?? '') !== '' && preg_match('/\d{4}/', (string) $row['promulgation_date'], $m) === 1) {
                $year = (int) $m[0];
                $yearCounts[$year] = ($yearCounts[$year] ?? 0) + 1;
            }
        }

        $facets = [];
        foreach ($termCounts as $field => $counts) {
            arsort($counts);
            $facets[$field] = array_values(array_map(
                fn (string $value, int $count): array => ['value' => $value, 'count' => $count],
                array_keys($counts),
                array_values($counts),
            ));
        }
        krsort($yearCounts);
        $facets['years'] = array_values(array_map(
            fn (int $year, int $count): array => ['year' => $year, 'count' => $count],
            array_keys($yearCounts),
            array_values($yearCounts),
        ));
        $facets['stats'] = [
            'total_laws' => $publicCount,
            'new_laws' => $changeCounts['new'],
            'amended_laws' => $changeCounts['amended'],
            'repealed_laws' => $changeCounts['repealed'],
            'parent_laws' => count($parentIds),
            'child_laws' => $childCount,
        ];

        return response()->json($facets);
    }

    private function canonicalType(string $lawType): string
    {
        return match (true) {
            str_contains($lawType, 'พระราชบัญญัติ'), $lawType === 'พ.ร.บ.', $lawType === 'phrb' => 'phrb',
            str_contains($lawType, 'ข้อบังคับ'), $lawType === 'kho-bangkhab' => 'kho-bangkhab',
            str_contains($lawType, 'ระเบียบ'), $lawType === 'rabiap' => 'rabiap',
            str_contains($lawType, 'ประกาศ'), $lawType === 'prakat' => 'prakat',
            default => 'other',
        };
    }

    private function tally(array &$counts, string $value): void
    {
        if ($value !== '') {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
    }

    /** Wrap the exact (case-insensitive) query substring in <mark>; plain otherwise. */
    private function highlightTitle(string $title, string $query): string
    {
        $q = trim($query);
        if ($q === '' || mb_stripos($title, $q) === false) {
            return $title;
        }

        return (string) preg_replace('/('.preg_quote($q, '/').')/iu', '<mark>$1</mark>', $title);
    }
}
