# SP3 — AdminDashboardPage Real Data Design

**Status:** Approved for implementation
**Date:** 2026-07-11

## Context

SP1 migrated all document state to MongoDB. SP2 did the same for permissions. `AdminReportPage.vue` already calls `GET /api/reports/summary` → `ReportController` → `ReviewStore.listLawMeta()` → MongoDB and shows real data. `AdminDashboardPage.vue` still imports static constants from `dashboardData.ts` — a pure mock with no API backing.

SP3 wires the dashboard to real MongoDB data by reusing the already-existing report endpoint.

## Goals

- `AdminDashboardPage.vue` shows real document counts (all, published, processing, failed) and real recent uploads, fetched from MongoDB.
- Remove the two dashboard sections that require law-relationship data we don't have: completeness bars and urgent alerts.
- Delete `dashboardData.ts` (mock file, only used by the dashboard).
- Delete `DashboardMetricCard.vue` (only used by the dashboard; replaced by `AdminStatCard`).

## Non-goals

- No backend changes — `GET /api/reports/summary` already works and returns the required data.
- No new TypeScript types — `ReportSummary` and `ReportDocument` already exist in `types/document.ts`.
- No change to `AdminReportPage.vue` — already wired to real data.
- No MongoDB aggregation optimization — current N+1 read pattern is acceptable for POC scale.
- No law-relationship widgets (completeness %, urgent alerts, law gap counts) — require data that doesn't exist yet.

## Architecture

### Data flow

```
AdminDashboardPage.vue
  → onMounted: fetchReportSummary()   (no filters = all documents)
  → GET /api/reports/summary
  → ReportController::summary()
  → ReviewStore::listLawMeta()
  → MongoBlobStore (documents collection)
  → returns ReportSummary
```

`fetchReportSummary` (default arg `{}`) and the `ReportSummary` type are already defined in `api/client.ts` and `types/document.ts`.

### File changes

| Action | Path | Purpose |
|---|---|---|
| **Modify** | `apps/app-laravel/resources/js/pages/admin/AdminDashboardPage.vue` | Replace static imports with API call; remove completeness/alert sections |
| **Delete** | `apps/app-laravel/resources/js/data/dashboardData.ts` | Mock-only file; no longer needed |
| **Delete** | `apps/app-laravel/resources/js/components/admin/DashboardMetricCard.vue` | Only used by the dashboard; replaced by `AdminStatCard` |

### Dashboard widget mapping

| Widget | Before (mock) | After (real) |
|---|---|---|
| Row 1 — 4 metric cards | `METRIC_CARDS` (hardcoded domain-specific counts) | 4 cards computed from `totals.all`, `totals.published`, `totals.processing`, `totals.failed` |
| Row 2 — completeness bars | `COMPLETENESS_ROWS` (hardcoded %) | **Removed** |
| Row 2 — urgent alerts | `URGENT_ALERTS` (2 hardcoded rows) | **Removed** |
| Row 3 — recent imports | `RECENT_IMPORTS` (2 hardcoded rows) | `summary.documents.slice(0, 5)` (sorted desc by `updated_at` server-side) |

### New AdminDashboardPage.vue

**Script setup imports:**
```ts
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchReportSummary } from '../../api/client';
import type { ReportSummary } from '../../types/document';
import AdminStatCard from '../../components/admin/AdminStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';
```

**Reactive state:**
```ts
const summary = ref<ReportSummary>({
  totals: { all: 0, published: 0, processing: 0, failed: 0, esign: 0 },
  by_type: [], by_group: [], by_agency: [], by_year: [], documents: [],
});

onMounted(async () => {
  summary.value = await fetchReportSummary();
});
```

**Stat cards computed (matches `AdminStatCard` props: `icon`, `iconColor`, `iconBg`, `number`, `label`):**
```ts
const statCards = computed(() => [
  { icon: 'mdi-file-document-multiple-outline', iconColor: '#2563eb', iconBg: '#dbeafe', number: summary.value.totals.all, label: 'เอกสารทั้งหมด' },
  { icon: 'mdi-check-circle-outline', iconColor: '#16a34a', iconBg: '#dcfce7', number: summary.value.totals.published, label: 'เผยแพร่แล้ว' },
  { icon: 'mdi-clock-outline', iconColor: '#d97706', iconBg: '#fef3c7', number: summary.value.totals.processing, label: 'รอประมวลผล' },
  { icon: 'mdi-alert-circle-outline', iconColor: '#dc2626', iconBg: '#fee2e2', number: summary.value.totals.failed, label: 'ล้มเหลว' },
]);
```

**Status chip color map:**
```ts
const STATUS_CHIP: Record<string, string> = {
  done: 'success', exported: 'success', ingested: 'success',
  processing: 'warning', queued: 'info', failed: 'error',
};
```

**Recent docs computed:**
```ts
const recentDocs = computed(() => summary.value.documents.slice(0, 5));
```

### Recent imports table columns

| Column | Source |
|---|---|
| ชื่อเอกสาร | `doc.title` (truncated with CSS ellipsis, max-width 320px) |
| สถานะ | `doc.status` mapped to chip color via `STATUS_CHIP` |
| วันที่นำเข้า | `(doc.date ?? '').slice(0, 10)` (YYYY-MM-DD) |
| การดำเนินการ | Row click → `router.push('/documents/' + doc.id + '/review')` |

Empty state: single row spanning all columns with "ยังไม่มีเอกสาร".

## Error handling

On API failure `summary` stays at its zero-default. No error toast — zero counts are honest and the report page is a click away.

## Testing

- No PHPUnit changes (backend is unchanged).
- Run `npm run typecheck` (TypeScript must compile clean).
- Manual: load the admin dashboard; confirm the counts and recent docs reflect real uploaded documents, not static numbers.

## Acceptance criteria

- `AdminDashboardPage.vue` shows real `totals` counts from MongoDB.
- `AdminDashboardPage.vue` shows the last 5 uploaded documents from MongoDB.
- Completeness bars and urgent alerts sections are absent from the page.
- `dashboardData.ts` and `DashboardMetricCard.vue` are deleted.
- `npm run typecheck` passes.
