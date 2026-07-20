<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LawSearchRequest;
use App\Services\ReviewStore;
use App\Services\Search\LawSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LawSearchController extends Controller
{
    public function search(LawSearchRequest $request, LawSearchService $service, ReviewStore $store): JsonResponse
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

                return response()->json([
                    'total' => ($esResult['total'] ?? 0) + count($supplement),
                    'results' => array_merge($esResult['results'] ?? [], $supplement),
                    'facets' => $esResult['facets'] ?? $fileBased['facets'],
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Law search failed, falling back to file-based', ['error' => $exception->getMessage()]);
        }

        return response()->json($fileBased);
    }

    /** @param array<string,mixed> $params */
    private function fileBasedSearch(array $params, ReviewStore $store): array
    {
        $q = mb_strtolower(trim((string) ($params['q'] ?? '')));
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, (int) ($params['per_page'] ?? 20));

        $rows = array_values(array_filter($store->listLawMeta(), function (array $row) use ($q, $filters, $store): bool {
            if (($row['status'] ?? '') !== 'ingested' || ($row['access_scope'] ?? '') !== 'public') {
                return false;
            }
            if ($q !== '') {
                $titleMatch = str_contains(mb_strtolower((string) ($row['title'] ?? '')), $q);
                if (! $titleMatch && ! $this->contentMatches((string) ($row['document_id'] ?? ''), $q, $store)) {
                    return false;
                }
            }
            foreach (['law_type', 'change_status', 'signer_group'] as $field) {
                $want = $filters[$field] ?? null;
                if (! empty($want) && ! in_array($row[$field] ?? '', (array) $want, true)) {
                    return false;
                }
            }
            $wantAgency = $filters['agency'] ?? null;
            if (! empty($wantAgency) && array_intersect((array) $wantAgency, $row['agencies'] ?? []) === []) {
                return false;
            }
            $wantGroup = $filters['law_group'] ?? null;
            if (! empty($wantGroup) && array_intersect((array) $wantGroup, $row['law_groups'] ?? []) === []) {
                return false;
            }

            return true;
        }));

        $total = count($rows);
        $paged = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $results = array_map(fn (array $r): array => [
            'law_id' => $r['document_id'],
            'title' => $r['title'],
            'law_type' => $r['law_type'],
            'status' => $r['meta_status'],
            'change_status' => $r['change_status'],
            'summary' => null,
            'published_date' => $r['promulgation_date'] ?? null,
            'agency' => $r['agencies'][0] ?? null,
            'signer_group' => $r['signer_group'],
            'snippets' => $this->makeFileBasedSnippets($r['document_id'], $q, $store, (string) ($r['title'] ?? '')),
        ], $paged);

        $facets = $this->computeFileBasedFacets($rows);

        return compact('total', 'results', 'facets');
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
            // ponytail: O(n) export-file reads per search; move to ES-only if the
            // doc set grows large enough that this fallback dominates latency.
            $this->exportChunkCache[$documentId] = $chunks;
        }

        return $this->exportChunkCache[$documentId];
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

        foreach ($store->listLawMeta() as $row) {
            if (($row['access_scope'] ?? '') === 'private') {
                continue;
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

        return response()->json($facets);
    }

    private function tally(array &$counts, string $value): void
    {
        if ($value !== '') {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
    }
}
