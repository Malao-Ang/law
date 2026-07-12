# SP3 — AdminDashboardPage Real Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the static mock `dashboardData.ts` in `AdminDashboardPage.vue` with real MongoDB data fetched from the existing `/api/reports/summary` endpoint.

**Architecture:** No backend changes. The dashboard calls `fetchReportSummary()` (already defined in `api/client.ts`) on mount and populates 4 metric cards (from `totals`) and a recent-uploads table (from `documents.slice(0, 5)`). The completeness bars and urgent alerts sections are removed because they require law-relationship data that doesn't exist yet. `dashboardData.ts` and the now-unused `DashboardMetricCard.vue` are deleted.

**Tech Stack:** Vue 3 (Composition API, `<script setup>`), TypeScript, Vuetify 3. No new dependencies.

---

## File structure

| Action | Path |
|---|---|
| **Modify** | `apps/app-laravel/resources/js/pages/admin/AdminDashboardPage.vue` |
| **Delete** | `apps/app-laravel/resources/js/data/dashboardData.ts` |
| **Delete** | `apps/app-laravel/resources/js/components/admin/DashboardMetricCard.vue` |

---

### Task 1: Rewrite AdminDashboardPage.vue and delete dead files

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/admin/AdminDashboardPage.vue`
- Delete: `apps/app-laravel/resources/js/data/dashboardData.ts`
- Delete: `apps/app-laravel/resources/js/components/admin/DashboardMetricCard.vue`

**Background for the implementer:**
- `fetchReportSummary(filters = {})` is defined in `apps/app-laravel/resources/js/api/client.ts` and returns `Promise<ReportSummary>`.
- `ReportSummary` is defined in `apps/app-laravel/resources/js/types/document.ts`:
  ```ts
  interface ReportSummary {
    totals: { all: number; published: number; processing: number; failed: number; esign: number };
    by_type: ReportBucket[];
    by_group: ReportBucket[];
    by_agency: ReportBucket[];
    by_year: ReportBucket[];
    documents: ReportDocument[];  // sorted desc by updated_at server-side
  }
  interface ReportDocument { id: string; title: string; type: string; group: string; agency: string; status: string; date: string | null; }
  ```
- `AdminStatCard.vue` is in `apps/app-laravel/resources/js/components/admin/AdminStatCard.vue` and accepts props: `icon: string`, `iconColor: string`, `iconBg: string`, `number: number`, `label: string`.
- `AppShell.vue` is in `apps/app-laravel/resources/js/components/shared/AppShell.vue`.

- [x] **Step 1: Delete dashboardData.ts**

Delete the file `apps/app-laravel/resources/js/data/dashboardData.ts`.

On Windows (PowerShell):
```powershell
Remove-Item "apps/app-laravel/resources/js/data/dashboardData.ts"
```

On Unix (Bash):
```bash
rm apps/app-laravel/resources/js/data/dashboardData.ts
```

- [x] **Step 2: Delete DashboardMetricCard.vue**

Delete the file `apps/app-laravel/resources/js/components/admin/DashboardMetricCard.vue`.

On Windows (PowerShell):
```powershell
Remove-Item "apps/app-laravel/resources/js/components/admin/DashboardMetricCard.vue"
```

On Unix (Bash):
```bash
rm apps/app-laravel/resources/js/components/admin/DashboardMetricCard.vue
```

- [x] **Step 3: Rewrite AdminDashboardPage.vue**

Replace the entire contents of `apps/app-laravel/resources/js/pages/admin/AdminDashboardPage.vue` with:

```vue
<template>
  <AppShell :breadcrumbs="['เมนูหลัก', 'หน้าแรก']" title="" hide-top-bar show-bell>
    <!-- Row 1: metric cards -->
    <v-row class="mb-6">
      <v-col v-for="card in statCards" :key="card.label" cols="12" sm="6" md="3">
        <AdminStatCard
          :icon="card.icon"
          :icon-color="card.iconColor"
          :icon-bg="card.iconBg"
          :number="card.number"
          :label="card.label"
        />
      </v-col>
    </v-row>

    <!-- Row 2: recent imports -->
    <v-card border rounded="lg">
      <div class="d-flex align-center pa-6 pb-4">
        <v-icon icon="mdi-history" class="me-2" />
        <span class="text-h6 font-weight-bold flex-grow-1">รายการกฎหมายที่เพิ่งนำเข้าล่าสุด</span>
        <v-btn variant="text" color="admin-primary" append-icon="mdi-arrow-right" class="text-none" @click="router.push('/admin/report')">ดูทั้งหมด</v-btn>
      </div>
      <v-divider />
      <v-table class="recent-table">
        <thead>
          <tr>
            <th>ชื่อเอกสาร</th>
            <th>สถานะ</th>
            <th>วันที่นำเข้า</th>
            <th>การดำเนินการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="recentDocs.length === 0">
            <td colspan="4" class="text-center pa-6 text-medium-emphasis">ยังไม่มีเอกสาร</td>
          </tr>
          <tr
            v-for="doc in recentDocs"
            :key="doc.id"
            class="recent-row"
            @click="router.push(`/documents/${doc.id}/review`)"
          >
            <td class="text-body-2 font-weight-medium py-4" style="max-width:320px">
              <span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ doc.title }}</span>
            </td>
            <td>
              <v-chip size="small" :color="STATUS_CHIP[doc.status] ?? 'default'" rounded="pill" variant="tonal">
                {{ doc.status }}
              </v-chip>
            </td>
            <td class="text-body-2">{{ (doc.date ?? '').slice(0, 10) }}</td>
            <td>
              <v-btn variant="text" size="small" color="admin-primary" prepend-icon="mdi-eye-outline" class="text-none">
                เปิดดู
              </v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchReportSummary } from '../../api/client';
import type { ReportSummary } from '../../types/document';
import AdminStatCard from '../../components/admin/AdminStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';

const router = useRouter();

const STATUS_CHIP: Record<string, string> = {
  done: 'success',
  exported: 'success',
  ingested: 'success',
  processing: 'warning',
  queued: 'info',
  failed: 'error',
};

const summary = ref<ReportSummary>({
  totals: { all: 0, published: 0, processing: 0, failed: 0, esign: 0 },
  by_type: [],
  by_group: [],
  by_agency: [],
  by_year: [],
  documents: [],
});

onMounted(async () => {
  summary.value = await fetchReportSummary();
});

const statCards = computed(() => [
  { icon: 'mdi-file-document-multiple-outline', iconColor: '#2563eb', iconBg: '#dbeafe', number: summary.value.totals.all, label: 'เอกสารทั้งหมด' },
  { icon: 'mdi-check-circle-outline', iconColor: '#16a34a', iconBg: '#dcfce7', number: summary.value.totals.published, label: 'เผยแพร่แล้ว' },
  { icon: 'mdi-clock-outline', iconColor: '#d97706', iconBg: '#fef3c7', number: summary.value.totals.processing, label: 'รอประมวลผล' },
  { icon: 'mdi-alert-circle-outline', iconColor: '#dc2626', iconBg: '#fee2e2', number: summary.value.totals.failed, label: 'ล้มเหลว' },
]);

const recentDocs = computed(() => summary.value.documents.slice(0, 5));
</script>

<style scoped>
.recent-table :deep(thead th) {
  color: rgba(var(--v-theme-secondary), 0.6) !important;
  font-size: 0.75rem !important;
  font-weight: 600 !important;
  letter-spacing: 0.05em;
}
.recent-row { cursor: pointer; }
.recent-row:hover { background: rgba(0, 0, 0, 0.03); }
</style>
```

- [x] **Step 4: Run TypeScript typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Observed on 2026-07-11: `npm run typecheck` passed with no errors.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/pages/admin/AdminDashboardPage.vue
git rm apps/app-laravel/resources/js/data/dashboardData.ts
git rm apps/app-laravel/resources/js/components/admin/DashboardMetricCard.vue
git commit -m "feat(dashboard): wire AdminDashboardPage to real MongoDB data via /api/reports/summary

- Remove static dashboardData.ts mock
- Remove now-unused DashboardMetricCard.vue
- Dashboard shows real totals (all/published/processing/failed) and last 5 uploads
- Completeness bars and urgent alerts removed (require law-relationship data)"
```

---

### Task 2: Full verification

**Files:** No changes — run and verify only.

- [x] **Step 1: Run TypeScript typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Observed on 2026-07-11: `npm run typecheck` passed with zero errors.

- [x] **Step 2: Verify no remaining references to deleted files**

```bash
grep -r "dashboardData\|DashboardMetricCard" apps/app-laravel/resources/js/
```

Observed on 2026-07-11: no matches for `dashboardData` or `DashboardMetricCard` under `apps/app-laravel/resources/js`.

- [x] **Step 3: Run PHP test suite**

```bash
docker compose exec laravel-app php artisan test
```

Observed on 2026-07-11: `149 passed, 1 failed, 1 warning`. The same pre-existing backend failure remained:
`DocumentApiTest > upload rejects unsupported scan extraction mode`.

- [x] **Step 4: Run pint on the PHP side (no-op check)**

```bash
docker compose exec laravel-app vendor/bin/pint --test
```

Observed on 2026-07-11: `vendor/bin/pint --test` failed due to 10 pre-existing style issues in unrelated PHP files. No PHP files were changed for this dashboard task.

- [ ] **Step 5: Manual smoke test**

Navigate to `http://localhost:8000/admin` in a browser while the stack is running. Confirm:
- 4 metric cards are visible and show numbers (not static "84", "216", "12", "12,402").
Not performed in this run because no browser automation was used here.
- The recent imports table shows real document rows (or the "ยังไม่มีเอกสาร" empty state if no docs exist).
- No completeness bars or urgent alerts sections appear.
- Clicking a row in the recent imports table navigates to `/documents/{id}/review`.
