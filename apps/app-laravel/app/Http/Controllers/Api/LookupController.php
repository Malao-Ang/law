<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Illuminate\Http\JsonResponse;

class LookupController extends Controller
{
    public function __construct(private readonly MasterDataStore $masterData) {}

    public function __invoke(): JsonResponse
    {
        $allStatusItems = $this->masterData->all(MasterDataKind::EnforcementStatus);

        return response()->json([
            'document_types' => config('lookups.document_types'),
            'statuses' => $this->statusItems(array_values(array_filter($allStatusItems, static fn (array $item): bool => (bool) ($item['is_active'] ?? false)))),
            'statuses_all' => $this->statusItems($allStatusItems),
            'change_status_types' => config('lookups.change_status_types'),
            'change_status_details' => config('lookups.change_status_details'),
            'agencies' => config('lookups.agencies'),
            'law_groups' => config('lookups.law_groups'),
            'law_sources' => config('lookups.law_sources'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statusItems(array $items): array
    {
        return array_map(static fn (array $item): array => [
            'title' => (string) ($item['name'] ?? ''),
            'value' => (string) ($item['code'] ?? ''),
            'code' => (string) ($item['code'] ?? ''),
            'color' => $item['attrs']['color'] ?? null,
            'role' => $item['attrs']['role'] ?? null,
        ], $items);
    }
}
