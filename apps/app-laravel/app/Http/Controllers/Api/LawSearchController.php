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
    public function search(LawSearchRequest $request, LawSearchService $service): JsonResponse
    {
        try {
            return response()->json($service->search($request->validated()));
        } catch (\Throwable $exception) {
            Log::warning('Law search failed', ['error' => $exception->getMessage()]);

            return response()->json(['message' => 'ค้นหาไม่พร้อมใช้งาน'], 503);
        }
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
