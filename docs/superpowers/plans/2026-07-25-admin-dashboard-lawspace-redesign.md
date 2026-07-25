# Admin Dashboard (LAWSPACE) Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the `/admin` dashboard to match the LAWSPACE design screenshot (4 stat cards, a distribution panel, an attention panel, and a recent-imports table) — wired entirely to real data, nothing mocked.

**Architecture:** The sidebar/topbar shell (`AppShell.vue`) already matches the design and is untouched. Work is the dashboard content plus two cheap backend aggregations added to the existing `/api/reports/summary` endpoint (relations total + legacy-link total), which are free because `listLawMeta()` already loads each review blob. Screenshot metrics with no backing data are honestly repurposed to real metrics (see mapping).

**Tech Stack:** Laravel (PHP 8.3, PHPUnit), Vue 3 + Vuetify 3 + TypeScript, Vite.

**Data mapping (design → real):**
| Design element | Real data source |
|---|---|
| Card 1 "จุดเสี่ยง" | `totals.failed` → relabelled "เอกสารที่ต้องตรวจสอบ" (failed) |
| Card 2 "LEGACY LINK" | `totals.legacy_links` (NEW aggregation) |
| Card 3 "คิวประมวลผล OCR" | `totals.processing` (existing) |
| Card 4 "Linkage" (navy) | `totals.relations` (NEW aggregation) |
| Panel 1 "ความสมบูรณ์" | `by_type` distribution bars (existing) |
| Panel 2 "ตรวจสอบเร่งด่วน" | `documents[]` filtered to failed + processing (existing) |
| Table "นำเข้าล่าสุด" | `documents[]` recent (existing) + status badge + `section_count` + status-driven action |

**Verification note:** The frontend has no JS test runner (only `npm run typecheck` + `npm run build`). Frontend tasks are verified by typecheck + build + a manual screenshot check. Only the backend task is TDD with PHPUnit.

---

### Task 1: Backend — add `relations` and `legacy_links` totals to the report summary

**Files:**
- Modify: `apps/app-laravel/app/Services/ReviewStore.php` (the `listLawMeta()` row literal, around lines 168-183)
- Modify: `apps/app-laravel/app/Http/Controllers/Api/ReportController.php:62-76` (`totals()`)
- Test: `apps/app-laravel/tests/Feature/ReportSummaryTest.php`

- [ ] **Step 1: Write the failing test**

Add this method to `ReportSummaryTest`:

```php
public function test_summary_totals_include_relations_and_legacy_links(): void
{
    $store = app(ReviewStore::class);
    $agency = 'AGENCY_'.uniqid();

    // Doc A: 2 relations, 1 repealed reference.
    $a = 'd_rel_'.uniqid();
    $store->setStatus($a, ['status' => 'done', 'source_file' => 'a.docx']);
    $store->writeReviewDocument($a, [
        'document_id' => $a, 'source_file' => 'a.docx', 'source_type' => 'docx',
        'language' => 'th',
        'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
        'law_meta' => ['law_type' => 'ประกาศ', 'agencies' => [$agency], 'repealed_laws' => ['พ.ร.บ. เก่า ๒๕๔๐']],
        'relations' => [
            ['id' => 'r1', 'scope' => 'document', 'type' => 'related', 'target_title' => 'X'],
            ['id' => 'r2', 'scope' => 'document', 'type' => 'amends', 'target_title' => 'Y'],
        ],
        'pages' => [],
    ]);

    // Doc B: 1 relation, no repealed references.
    $b = 'd_rel_'.uniqid();
    $store->setStatus($b, ['status' => 'done', 'source_file' => 'b.docx']);
    $store->writeReviewDocument($b, [
        'document_id' => $b, 'source_file' => 'b.docx', 'source_type' => 'docx',
        'language' => 'th',
        'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
        'law_meta' => ['law_type' => 'ประกาศ', 'agencies' => [$agency]],
        'relations' => [
            ['id' => 'r3', 'scope' => 'document', 'type' => 'related', 'target_title' => 'Z'],
        ],
        'pages' => [],
    ]);

    $res = $this->getJson('/api/reports/summary?agency[]='.rawurlencode($agency));
    $res->assertOk();
    $res->assertJsonPath('totals.relations', 3);
    $res->assertJsonPath('totals.legacy_links', 1);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=test_summary_totals_include_relations_and_legacy_links`
Expected: FAIL — `totals.relations` missing (JSON path not found).

- [ ] **Step 3: Add per-row counts in `listLawMeta()`**

In `ReviewStore.php`, inside the `$rows[] = [ ... ]` array literal (the block beginning near line 168), add these two keys (place them alongside `'section_count'`):

```php
                    'relations_count' => is_array($review['relations'] ?? null)
                        ? count($review['relations'])
                        : 0,
                    'legacy_link_count' => is_array($meta['repealed_laws'] ?? null)
                        ? count(array_filter(
                            $meta['repealed_laws'],
                            static fn ($x): bool => trim((string) $x) !== '',
                        ))
                        : 0,
```

- [ ] **Step 4: Sum them in `ReportController::totals()`**

Replace the returned array in `totals()` (lines 69-75) with:

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T laravel-app php artisan test --filter=ReportSummaryTest`
Expected: PASS (all methods, including the new one).

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/app/Services/ReviewStore.php apps/app-laravel/app/Http/Controllers/Api/ReportController.php apps/app-laravel/tests/Feature/ReportSummaryTest.php
git commit -m "feat(reports): add relations + legacy-link totals to summary"
```

---

### Task 2: Frontend — extend the `ReportSummary` type

**Files:**
- Modify: `apps/app-laravel/resources/js/types/document.ts:394`

- [ ] **Step 1: Widen the `totals` shape**

Replace line 394:

```ts
  totals: { all: number; published: number; processing: number; failed: number; esign: number };
```

with:

```ts
  totals: {
    all: number; published: number; processing: number; failed: number; esign: number;
    relations: number; legacy_links: number;
  };
```

- [ ] **Step 2: Update the default in AdminDashboardPage**

In `apps/app-laravel/resources/js/pages/admin/AdminDashboardPage.vue`, the `summary` ref default `totals` object (currently `{ all: 0, published: 0, processing: 0, failed: 0, esign: 0 }`) must gain the two new keys: `relations: 0, legacy_links: 0`. (This edit is folded into Task 5's full rewrite of the file; if doing tasks out of order, apply it here to keep typecheck green.)

- [ ] **Step 3: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS (no errors in `document.ts`).

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/types/document.ts
git commit -m "feat(types): add relations + legacy_links to ReportSummary totals"
```

---

### Task 3: Frontend — `ReportStatCard` component

**Files:**
- Create: `apps/app-laravel/resources/js/components/admin/ReportStatCard.vue`

A card with a small coloured title, a large number, and an italic footnote. Supports a `filled` navy variant (card 4) and a `tone` for the title colour. Built once, placed 4×.

- [ ] **Step 1: Create the component**

```vue
<template>
  <v-card
    rounded="lg"
    :class="['report-stat-card', filled ? 'report-stat-card--filled' : '']"
    :elevation="filled ? 4 : 1"
  >
    <div class="report-stat-card__title" :class="`tone-${tone}`">{{ title }}</div>
    <div class="report-stat-card__value">{{ value.toLocaleString('th-TH') }}</div>
    <div v-if="footnote" class="report-stat-card__footnote">{{ footnote }}</div>
  </v-card>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    title: string;
    value: number;
    footnote?: string;
    tone?: 'default' | 'danger' | 'primary';
    filled?: boolean;
  }>(),
  { footnote: '', tone: 'default', filled: false },
);
</script>

<style scoped>
.report-stat-card {
  padding: 20px 22px;
  min-height: 132px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.report-stat-card__title {
  font-size: 0.8rem;
  font-weight: 700;
}
.report-stat-card__title.tone-default { color: #475569; }
.report-stat-card__title.tone-danger { color: #b91c1c; }
.report-stat-card__title.tone-primary { color: #2563eb; }
.report-stat-card__value {
  font-size: 2.6rem;
  font-weight: 800;
  line-height: 1.1;
  color: #0f172a;
}
.report-stat-card__footnote {
  font-size: 0.72rem;
  font-style: italic;
  color: #94a3b8;
  margin-top: auto;
}
.report-stat-card--filled {
  background: #1e2a52;
}
.report-stat-card--filled .report-stat-card__title { color: #cbd5e1; }
.report-stat-card--filled .report-stat-card__value { color: #fff; }
.report-stat-card--filled .report-stat-card__footnote { color: #94a3b8; }
</style>
```

- [ ] **Step 2: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/components/admin/ReportStatCard.vue
git commit -m "feat(admin): add ReportStatCard component"
```

---

### Task 4: Frontend — rewrite `AdminDashboardPage.vue` (cards + panels + table)

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/admin/AdminDashboardPage.vue` (full `<template>` + `<script setup>` replacement)

Two panels (distribution + attention) are kept inline — they are not reused anywhere else, so extracting them would only add files.

- [ ] **Step 1: Replace the file contents**

```vue
<template>
  <AppShell :breadcrumbs="['เมนูหลัก', 'หน้าแรก']" title="" hide-top-bar show-bell>
    <!-- Stat cards -->
    <v-row class="mb-2">
      <v-col cols="12" sm="6" md="3">
        <ReportStatCard tone="danger" title="เอกสารที่ต้องตรวจสอบ"
          :value="summary.totals.failed"
          footnote="* เอกสารที่ประมวลผลไม่สำเร็จ ต้องตรวจสอบ" />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <ReportStatCard title="รอปรับปรุง (LEGACY LINK)"
          :value="summary.totals.legacy_links"
          footnote="* จำนวนการอ้างอิงกฎหมายฉบับที่ยกเลิกแล้ว" />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <ReportStatCard tone="primary" title="คิวประมวลผล OCR"
          :value="summary.totals.processing"
          footnote="* เอกสารที่รอประมวลผล/ยืนยันความถูกต้อง" />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <ReportStatCard filled title="ความสัมพันธ์ที่บันทึกแล้ว"
          :value="summary.totals.relations"
          footnote="เส้นเชื่อมโยง (Linkage)" />
      </v-col>
    </v-row>

    <!-- Middle panels -->
    <v-row class="mb-2">
      <v-col cols="12" md="6">
        <v-card border rounded="lg" class="pa-6" style="min-height: 260px">
          <div class="d-flex align-baseline mb-4">
            <span class="text-h6 font-weight-bold">การกระจายตามประเภทกฎหมาย</span>
            <span class="text-caption text-medium-emphasis ml-2">(จำนวนเอกสารต่อประเภท)</span>
          </div>
          <div v-if="typeBars.length === 0" class="text-medium-emphasis">ยังไม่มีข้อมูล</div>
          <div v-for="bar in typeBars" :key="bar.key" class="mb-4">
            <div class="d-flex justify-space-between mb-1">
              <span class="text-body-2 font-weight-medium">{{ bar.label }}</span>
              <span class="text-body-2 font-weight-bold" style="color:#2563eb">{{ bar.count.toLocaleString('th-TH') }} ฉบับ</span>
            </div>
            <div class="type-bar-track">
              <div class="type-bar-fill" :style="{ width: bar.pct + '%' }" />
            </div>
          </div>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card border rounded="lg" class="pa-6" style="min-height: 260px">
          <div class="d-flex align-center mb-4">
            <v-icon icon="mdi-alert-outline" color="warning" class="me-2" />
            <span class="text-h6 font-weight-bold">รายการที่ต้องตรวจสอบเร่งด่วน</span>
          </div>
          <div v-if="attentionItems.length === 0" class="text-medium-emphasis">ไม่มีรายการที่ต้องตรวจสอบ</div>
          <div
            v-for="item in attentionItems"
            :key="item.id"
            class="attention-item"
            :class="item.tone === 'error' ? 'attention-item--error' : 'attention-item--warn'"
          >
            <div class="min-width-0">
              <div class="text-body-2 font-weight-bold attention-item__title">{{ item.title }}</div>
              <div class="text-caption text-medium-emphasis">{{ item.reason }}</div>
            </div>
            <v-btn size="small" variant="tonal" :color="item.tone" class="text-none flex-shrink-0"
              @click="router.push(`/documents/${item.id}/review`)">ตรวจสอบ</v-btn>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Recent imports table -->
    <v-card border rounded="lg">
      <div class="d-flex align-center pa-6 pb-4">
        <v-icon icon="mdi-history" class="me-2" />
        <span class="text-h6 font-weight-bold flex-grow-1">รายการกฎหมายที่เพิ่งนำเข้าล่าสุด</span>
        <v-btn variant="text" color="admin-primary" append-icon="mdi-arrow-right" class="text-none"
          @click="router.push('/admin/reports')">ดูทั้งหมด</v-btn>
      </div>
      <v-divider />
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table class="recent-table">
        <thead>
          <tr>
            <th>ชื่อกฎหมาย</th>
            <th>สถานะ</th>
            <th>ความซับซ้อน</th>
            <th>การดำเนินการ</th>
          </tr>
        </thead>
        <tbody>
          <template v-if="loading">
            <tr v-for="n in 3" :key="n">
              <td><v-skeleton-loader type="text" width="260" /></td>
              <td><v-skeleton-loader type="chip" width="80" /></td>
              <td><v-skeleton-loader type="text" width="90" /></td>
              <td><v-skeleton-loader type="text" width="60" /></td>
            </tr>
          </template>
          <template v-else>
            <tr v-if="recentDocs.length === 0">
              <td colspan="4" class="text-center pa-6 text-medium-emphasis">ยังไม่มีเอกสาร</td>
            </tr>
            <tr v-for="doc in recentDocs" v-else :key="doc.id" class="recent-row"
              @click="router.push(`/documents/${doc.id}/review`)">
              <td class="py-4 text-body-2 font-weight-medium" style="max-width: 360px">
                <span class="recent-row__title">{{ doc.title }}</span>
              </td>
              <td>
                <v-chip size="small" :color="STATUS_CHIP[doc.status] ?? 'default'" rounded="pill" variant="tonal">
                  {{ doc.meta_status || doc.status }}
                </v-chip>
              </td>
              <td class="text-body-2">{{ complexityLabel(doc.section_count) }}</td>
              <td>
                <v-btn variant="text" size="small" color="admin-primary"
                  :prepend-icon="isPublished(doc.status) ? 'mdi-graph-outline' : 'mdi-file-search-outline'"
                  class="text-none" @click.stop="openAction(doc)">
                  {{ isPublished(doc.status) ? 'เปิดดูผัง' : 'ตรวจสอบเอกสาร' }}
                </v-btn>
              </td>
            </tr>
          </template>
        </tbody>
      </v-table>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchReportSummary } from '../../api/client';
import type { ReportSummary, ReportDocument } from '../../types/document';
import ReportStatCard from '../../components/admin/ReportStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';

const router = useRouter();

const STATUS_CHIP: Record<string, string> = {
  done: 'success', exported: 'success', ingested: 'success',
  processing: 'warning', queued: 'info', ingesting: 'info', failed: 'error',
};
const PUBLISHED = ['done', 'exported', 'ingested'];
const PROCESSING = ['queued', 'processing', 'ingesting'];

const loading = ref(false);
const summary = ref<ReportSummary>({
  totals: { all: 0, published: 0, processing: 0, failed: 0, esign: 0, relations: 0, legacy_links: 0 },
  by_type: [], by_group: [], by_agency: [], by_year: [], documents: [],
});

onMounted(async () => {
  loading.value = true;
  try {
    summary.value = await fetchReportSummary();
  } finally {
    loading.value = false;
  }
});

function isPublished(status: string): boolean {
  return PUBLISHED.includes(status);
}

function complexityLabel(sectionCount: number | null): string {
  if (sectionCount === null || sectionCount <= 0) return '—';
  const level = sectionCount >= 10 ? 'สูง' : 'ต่ำ';
  return `${level} (${sectionCount} มาตรา)`;
}

function openAction(doc: ReportDocument): void {
  router.push(isPublished(doc.status) ? `/documents/${doc.id}/relations` : `/documents/${doc.id}/review`);
}

const typeBars = computed(() => {
  const max = Math.max(1, ...summary.value.by_type.map((b) => b.count));
  return summary.value.by_type.slice(0, 6).map((b) => ({
    key: b.key, label: b.label, count: b.count, pct: Math.round((b.count / max) * 100),
  }));
});

const attentionItems = computed(() =>
  summary.value.documents
    .filter((d) => d.status === 'failed' || PROCESSING.includes(d.status))
    .slice(0, 5)
    .map((d) => ({
      id: d.id,
      title: d.title,
      tone: d.status === 'failed' ? 'error' : 'warning',
      reason: d.status === 'failed' ? 'ประมวลผลไม่สำเร็จ ต้องตรวจสอบ' : 'อยู่ระหว่างประมวลผล/รอยืนยัน',
    })),
);

const recentDocs = computed(() => summary.value.documents.slice(0, 5));
</script>

<style scoped>
.type-bar-track {
  height: 12px;
  background: #eef2f7;
  border-radius: 999px;
  overflow: hidden;
}
.type-bar-fill {
  height: 100%;
  background: #2563eb;
  border-radius: 999px;
}
.attention-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 10px;
  margin-bottom: 10px;
}
.attention-item--error { background: #fef2f2; }
.attention-item--warn { background: #fffbeb; }
.attention-item__title {
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.recent-table :deep(thead th) {
  color: rgba(var(--v-theme-secondary), 0.6) !important;
  font-size: 0.75rem !important; font-weight: 600 !important; letter-spacing: 0.05em;
}
.recent-row { cursor: pointer; }
.recent-row:hover { background: rgba(0, 0, 0, 0.03); }
.recent-row__title { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.min-width-0 { min-width: 0; }
</style>
```

- [ ] **Step 2: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS. (`ReportDocument` is already exported from `types/document.ts`.)

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/pages/admin/AdminDashboardPage.vue
git commit -m "feat(admin): redesign dashboard to LAWSPACE layout with real data"
```

---

### Task 5: Verify build + manual screenshot check

**Files:** none (verification only)

- [ ] **Step 1: Production build**

Run (on host): `cd apps/app-laravel && npm run build`
Expected: build succeeds, no TS errors.

- [ ] **Step 2: Manual check**

Start the stack (`docker-compose up -d` if not running), open `http://localhost:8000/admin`. Confirm:
- 4 stat cards render, card 4 is navy-filled, numbers are real (0 is fine on an empty dataset).
- Distribution bars reflect `by_type`; empty state shows "ยังไม่มีข้อมูล".
- Attention panel lists only failed/processing docs, or the empty state.
- Recent table shows status badge, complexity label, and the status-driven action button.

- [ ] **Step 3: Commit (only if manual check required tweaks)**

```bash
git add -A && git commit -m "fix(admin): dashboard polish after manual check"
```

---

## Self-Review

- **Spec coverage:** All 7 design elements have a task (cards → Tasks 3-4, panels → Task 4, table → Task 4, aggregations → Task 1, types → Task 2). ✓
- **Placeholders:** none — every step has concrete code/commands. ✓
- **Type consistency:** `totals.relations`/`totals.legacy_links` defined in Task 1 (backend) and Task 2 (TS type), consumed in Task 4. `ReportDocument` reused as-is. `isPublished`/`complexityLabel`/`openAction` defined and used within Task 4. ✓
- **No-mock rule:** every rendered value traces to `/api/reports/summary` real data. ✓
