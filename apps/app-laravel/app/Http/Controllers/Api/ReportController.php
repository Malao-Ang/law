<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private const PUBLISHED = ['exported', 'ingested'];

    private const PROCESSING = ['queued', 'processing', 'ingesting'];

    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function summary(Request $request): JsonResponse
    {
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $type = trim((string) $request->query('type', ''));
        $status = trim((string) $request->query('status', ''));
        $groups = array_values(array_filter((array) $request->query('group', []), 'is_string'));
        $agencies = array_values(array_filter((array) $request->query('agency', []), 'is_string'));

        $rows = array_filter($this->reviewStore->listLawMeta(), function (array $r) use ($dateFrom, $dateTo, $type, $status, $groups, $agencies): bool {
            $updated = (string) ($r['updated_at'] ?? '');
            if ($dateFrom !== '' && ($updated === '' || substr($updated, 0, 10) < $dateFrom)) {
                return false;
            }
            if ($dateTo !== '' && ($updated === '' || substr($updated, 0, 10) > $dateTo)) {
                return false;
            }
            if ($type !== '' && ($r['law_type'] ?? '') !== $type) {
                return false;
            }
            if ($status !== '' && ($r['status'] ?? '') !== $status) {
                return false;
            }
            if ($groups !== [] && array_intersect($groups, $r['law_groups'] ?? []) === []) {
                return false;
            }
            if ($agencies !== [] && array_intersect($agencies, $r['agencies'] ?? []) === []) {
                return false;
            }

            return true;
        });

        return response()->json([
            'totals' => $this->totals($rows),
            'by_type' => $this->countScalar($rows, 'law_type'),
            'by_group' => $this->countList($rows, 'law_groups'),
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
            'esign' => 0, // ponytail: no eSign workflow yet, add when signing lands
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
     * Count by a single scalar field; empty → "ไม่ระบุ". Sorted desc.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function countScalar(array $rows, string $field): array
    {
        $buckets = [];
        foreach ($rows as $r) {
            $key = trim((string) ($r[$field] ?? '')) ?: 'ไม่ระบุ';
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }

        return $this->toSortedList($buckets);
    }

    /**
     * Count by exploding a list field; empty list → "ไม่ระบุ". Sums may exceed total.
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
     * Count by Buddhist year extracted from promulgation_date. No match → "ไม่ระบุ".
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
        return array_map(static fn (array $r): array => [
            'id' => $r['document_id'],
            'title' => $r['title'],
            'type' => trim((string) ($r['law_type'] ?? '')) ?: 'ไม่ระบุ',
            'group' => ($r['law_groups'][0] ?? '') ?: 'ไม่ระบุ',
            'agency' => ($r['agencies'][0] ?? '') ?: 'ไม่ระบุ',
            'status' => $r['status'],
            'meta_status' => trim((string) ($r['meta_status'] ?? '')),
            'change_status' => trim((string) ($r['change_status'] ?? '')),
            'published_date' => trim((string) ($r['published_date'] ?? '')),
            'date' => $r['updated_at'],
            'section_count' => isset($r['section_count']) ? (int) $r['section_count'] : null,
            'page_count' => (int) ($r['page_count'] ?? 0),
            'parent_document_id' => $r['parent_document_id'] ?? null,
            'parent_document_ids' => is_array($r['parent_document_ids'] ?? null) ? $r['parent_document_ids'] : [],
            'workflow_completed_step' => isset($r['workflow_completed_step']) ? (int) $r['workflow_completed_step'] : null,
        ], array_values($rows));
    }
}
