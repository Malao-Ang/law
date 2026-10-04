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
        $allLawFamilies = $this->masterData->all(MasterDataKind::LawFamily);
        $allLawTypes = $this->masterData->all(MasterDataKind::LawType);
        $allLawCategories = $this->masterData->all(MasterDataKind::LawCategory);
        $allLegalStructures = $this->masterData->all(MasterDataKind::LegalStructure);

        return response()->json([
            'document_types' => $this->lawTypeItems(array_values(array_filter($allLawTypes, static fn (array $item): bool => (bool) ($item['is_active'] ?? false))), $allLawFamilies),
            'document_types_all' => $this->lawTypeItems($allLawTypes, $allLawFamilies),
            'law_families' => $this->lawFamilyItems(array_values(array_filter($allLawFamilies, static fn (array $item): bool => (bool) ($item['is_active'] ?? false)))),
            'law_families_all' => $this->lawFamilyItems($allLawFamilies),
            'statuses' => $this->statusItems(array_values(array_filter($allStatusItems, static fn (array $item): bool => (bool) ($item['is_active'] ?? false)))),
            'statuses_all' => $this->statusItems($allStatusItems),
            'change_status_types' => config('lookups.change_status_types'),
            'change_status_details' => config('lookups.change_status_details'),
            'agencies' => config('lookups.agencies'),
            'law_groups' => $this->lawCategoryItems(array_values(array_filter($allLawCategories, static fn (array $item): bool => (bool) ($item['is_active'] ?? false)))),
            'law_groups_all' => $this->lawCategoryItems($allLawCategories),
            'legal_structures' => $this->legalStructureItems(array_values(array_filter($allLegalStructures, static fn (array $item): bool => (bool) ($item['is_active'] ?? false)))),
            'legal_structures_all' => $this->legalStructureItems($allLegalStructures),
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

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function lawFamilyItems(array $items): array
    {
        return array_map(static fn (array $item): array => [
            'title' => (string) ($item['name'] ?? ''),
            'value' => (string) ($item['code'] ?? ''),
            'code' => (string) ($item['code'] ?? ''),
            'source' => (string) ($item['attrs']['source'] ?? 'internal'),
            'color' => $item['attrs']['color'] ?? null,
            'sort_order' => (int) ($item['sort_order'] ?? 0),
        ], $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $families
     * @return list<array<string, mixed>>
     */
    private function lawTypeItems(array $items, array $families): array
    {
        $familiesByCode = array_column($families, null, 'code');

        return array_map(static function (array $item) use ($familiesByCode): array {
            $familyCode = (string) ($item['attrs']['family_code'] ?? '');
            $family = is_array($familiesByCode[$familyCode] ?? null) ? $familiesByCode[$familyCode] : [];

            return [
                'title' => (string) ($item['name'] ?? ''),
                'value' => (string) ($item['code'] ?? ''),
                'code' => (string) ($item['code'] ?? ''),
                'family_code' => $familyCode,
                'source' => (string) ($family['attrs']['source'] ?? 'internal'),
            ];
        }, $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function lawCategoryItems(array $items): array
    {
        return array_map(static fn (array $item): array => [
            'title' => (string) ($item['name'] ?? ''),
            'value' => (string) ($item['code'] ?? ''),
            'code' => (string) ($item['code'] ?? ''),
            'sort_order' => (int) ($item['sort_order'] ?? 0),
        ], $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function legalStructureItems(array $items): array
    {
        return array_map(static fn (array $item): array => [
            'title' => (string) ($item['name'] ?? ''),
            'value' => (string) ($item['code'] ?? ''),
            'code' => (string) ($item['code'] ?? ''),
            'family_codes' => array_values(array_map('strval', (array) ($item['attrs']['family_codes'] ?? []))),
            'file_types' => array_values(array_map('strval', (array) ($item['attrs']['file_types'] ?? []))),
            'is_head' => (bool) ($item['attrs']['is_head'] ?? false),
            'counts_as_section' => (bool) ($item['attrs']['counts_as_section'] ?? false),
            'is_required' => (bool) ($item['attrs']['is_required'] ?? false),
            'color' => (string) ($item['attrs']['color'] ?? 'blue-grey'),
            'export_key' => (string) ($item['attrs']['export_key'] ?? $item['code'] ?? ''),
            'sort_order' => (int) ($item['sort_order'] ?? 0),
        ], $items);
    }
}
