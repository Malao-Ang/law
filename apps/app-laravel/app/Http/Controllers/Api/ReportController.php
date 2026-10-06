<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MasterData\ChangeStatuses;
use App\Services\MasterData\EnforcementStatuses;
use App\Services\MasterData\LawCategories;
use App\Services\MasterData\LawTypes;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private const PUBLISHED = ['exported', 'ingested'];

    private const PROCESSING = ['queued', 'processing', 'ingesting'];

    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly EnforcementStatuses $enforcementStatuses,
        private readonly LawTypes $lawTypes,
        private readonly LawCategories $lawCategories,
        private readonly ChangeStatuses $changeStatuses,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $type = trim((string) $request->query('type', ''));
        $typeCode = $type === '' ? '' : (string) ($this->lawTypes->resolve($type)['code'] ?? $type);
        $status = trim((string) $request->query('status', ''));
        $metaStatus = null;
        if ($status !== '') {
            $resolvedStatus = $this->enforcementStatuses->resolve($status);
            $metaStatus = $resolvedStatus === null ? null : (string) $resolvedStatus['code'];
        }
        $groups = array_values(array_unique(array_map(
            fn (string $group): string => (string) ($this->lawCategories->resolve($group)['code'] ?? trim($group)),
            array_values(array_filter((array) $request->query('group', []), 'is_string')),
        )));
        $agencies = array_values(array_filter((array) $request->query('agency', []), 'is_string'));

        $rows = array_filter($this->reviewStore->listLawMeta(), function (array $r) use ($dateFrom, $dateTo, $typeCode, $status, $metaStatus, $groups, $agencies): bool {
            $updated = (string) ($r['updated_at'] ?? '');
            if ($dateFrom !== '' && ($updated === '' || substr($updated, 0, 10) < $dateFrom)) {
                return false;
            }
            if ($dateTo !== '' && ($updated === '' || substr($updated, 0, 10) > $dateTo)) {
                return false;
            }
            if ($typeCode !== '' && (string) ($this->lawTypes->resolve($r['law_type'] ?? '')['code'] ?? ($r['law_type'] ?? '')) !== $typeCode) {
                return false;
            }
            if ($metaStatus !== null && ($r['meta_status'] ?? '') !== $metaStatus) {
                return false;
            }
            if ($status !== '' && $metaStatus === null && ($r['status'] ?? '') !== $status) {
                return false;
            }
            if ($groups !== [] && array_intersect($groups, $this->categoryKeys((array) ($r['law_groups'] ?? []))) === []) {
                return false;
            }
            if ($agencies !== [] && array_intersect($agencies, $r['agencies'] ?? []) === []) {
                return false;
            }

            return true;
        });

        return response()->json([
            'totals' => $this->totals($rows),
            'by_type' => $this->countLawTypes($rows),
            'by_group' => $this->countLawCategories($rows),
            'by_agency' => $this->countList($rows, 'agencies'),
            'by_year' => $this->countYear($rows),
            'documents' => $this->documents($rows),
        ]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function totals(array $rows): array
    {
        $count = static fn (array $statuses): int => count(array_filter(
            $rows,
            static fn (array $r): bool => in_array($r['status'] ?? '', $statuses, true),
        ));

        return [
            'all' => count($rows),
            'published' => $count(self::PUBLISHED),
            'processing' => $count(self::PROCESSING),
            'failed' => $count(['failed']),
            'esign' => 0,
            'relations' => array_sum(array_map(
                static fn (array $r): int => (int) ($r['relations_count'] ?? 0),
                $rows,
            )),
            'legacy_links' => array_sum(array_map(
                static fn (array $r): int => (int) ($r['legacy_link_count'] ?? 0),
                $rows,
            )),
        ];
    }

    /**
     * Count by exploding a list field; empty list -> "ไม่ระบุ". Sums may exceed total.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function countList(array $rows, string $field): array
    {
        $buckets = [];
        foreach ($rows as $r) {
            $values = $r[$field] ?? [];
            if ($values === []) {
                $values = ['ไม่ระบุ'];
            }
            foreach ($values as $value) {
                $key = trim((string) $value) ?: 'ไม่ระบุ';
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            }
        }

        return $this->toSortedList($buckets);
    }

    /**
     * Count by Buddhist year extracted from promulgation_date. No match -> "ไม่ระบุ".
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function countYear(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $r) {
            $key = 'ไม่ระบุ';
            if (preg_match('/(25\d{2})/u', (string) ($r['promulgation_date'] ?? ''), $m) === 1) {
                $key = $m[1];
            }
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }

        return $this->toSortedList($buckets);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function countLawTypes(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $r) {
            $raw = trim((string) ($r['law_type'] ?? ''));
            $resolved = $this->lawTypes->resolve($raw);
            $key = (string) ($resolved['code'] ?? ($raw ?: 'ไม่ระบุ'));
            $label = $resolved === null ? ($raw ?: 'ไม่ระบุ') : (string) ($resolved['name'] ?? $key);
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['key' => $key, 'label' => $label, 'count' => 0];
            }
            $buckets[$key]['count']++;
        }

        usort($buckets, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));

        return array_values($buckets);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, count: int}>
     */
    private function countLawCategories(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $r) {
            $codes = $this->categoryKeys((array) ($r['law_groups'] ?? []));
            if ($codes === []) {
                $codes = ['ไม่ระบุ'];
            }
            foreach ($codes as $code) {
                if (! isset($buckets[$code])) {
                    $label = $code === 'ไม่ระบุ' ? $code : ($this->lawCategories->labelOf($code) ?: $code);
                    $buckets[$code] = ['key' => $code, 'label' => $label, 'count' => 0];
                }
                $buckets[$code]['count']++;
            }
        }

        usort($buckets, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));

        return array_values($buckets);
    }

    /**
     * Category code per value; values that do not resolve (pre-migration data) are kept as-is.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function categoryKeys(array $values): array
    {
        $keys = [];
        foreach ($values as $value) {
            $raw = trim((string) $value);
            $key = $raw === '' ? '' : (string) ($this->lawCategories->resolve($raw)['code'] ?? $raw);
            if ($key !== '' && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @param array<string, int> $buckets */
    private function toSortedList(array $buckets): array
    {
        arsort($buckets);

        return array_map(
            static fn (string $key, int $count): array => ['key' => $key, 'label' => $key, 'count' => $count],
            array_keys($buckets),
            array_values($buckets),
        );
    }

    /**
     * Filtered document list for drill-down (first group/agency shown).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function documents(array $rows): array
    {
        return array_map(function (array $r): array {
            $rawType = trim((string) ($r['law_type'] ?? ''));
            $type = $this->lawTypes->resolve($rawType);

            return [
                'id' => $r['document_id'],
                'title' => $r['title'],
                'type' => $type === null ? ($rawType ?: 'ไม่ระบุ') : (string) ($type['name'] ?? $rawType),
                'type_code' => $type === null ? $rawType : (string) ($type['code'] ?? $rawType),
                'group' => $this->lawCategories->labelOf($r['law_groups'][0] ?? '') ?: 'ไม่ระบุ',
                'group_code' => $this->categoryKeys((array) ($r['law_groups'] ?? []))[0] ?? '',
                'agency' => ($r['agencies'][0] ?? '') ?: 'ไม่ระบุ',
                'status' => $r['status'],
                'meta_status' => trim((string) ($r['meta_status'] ?? '')),
                'change_status' => $this->changeStatuses->labelOf($r['change_status'] ?? ''),
                'change_status_code' => (string) ($this->changeStatuses->resolve($r['change_status'] ?? '')['code'] ?? trim((string) ($r['change_status'] ?? ''))),
                'published_date' => trim((string) ($r['published_date'] ?? '')),
                'source' => trim((string) ($r['source'] ?? '')),
                'document_type' => trim((string) ($r['document_type'] ?? 'new')),
                'access_scope' => $r['access_scope'] ?? 'public',
                'date' => $r['updated_at'],
                'section_count' => isset($r['section_count']) ? (int) $r['section_count'] : null,
                'page_count' => (int) ($r['page_count'] ?? 0),
                'parent_document_id' => $r['parent_document_id'] ?? null,
                'parent_document_ids' => is_array($r['parent_document_ids'] ?? null) ? $r['parent_document_ids'] : [],
                'workflow_completed_step' => isset($r['workflow_completed_step']) ? (int) $r['workflow_completed_step'] : null,
                'esign_sign_status' => $r['esign_sign_status'] ?? null,
                'esign_submitted_at' => $r['esign_submitted_at'] ?? null,
            ];
        }, array_values($rows));
    }
}
