# Plan — Admin upload & laws tables: e-Sign status column, clearer statuses, dd/mm/yyyy dates, matching filter

> Project: `docling-thai-poc/apps/app-laravel` (Laravel 11 API + Vue 3 / Vuetify SPA).
> Frontend "tests": `*.check.ts` run with `npx tsx` (no vitest). Verify with `npm run typecheck` + `npm run build`.
> Base branch: `main` (create `feat/admin-esign-status-columns` off current main — the old esign branch is stale).

## Goal
Make e-Sign progress visible and consistent in both admin tables: `admin/upload` (the `DocumentPipelineTable` queue) and `admin/laws` (`AdminLawListPage`) each gain a clear e-Sign status, законฃ status, publish status, a numeric `dd/mm/yyyy` (พ.ศ.) "แก้ไขล่าสุด" date, unchanged action buttons, and a matching e-Sign filter.

## Current context / findings
- **`admin/upload`** renders `components/admin/DocumentPipelineTable.vue`. It already has columns: ลำดับ, เอกสาร, ประเภท (`lawType`), สถานะกฎหมาย (`lawStatus`), สถานะเผยแพร่ (`publishedDate`→เผยแพร่แล้ว/ยังไม่เผยแพร่), ขั้นตอน (`stage` via `PipelineStageChip`), อัปเดตล่าสุด (`updatedAt`), การดำเนินการ (actions). Filters: search + `filterType` + `filterStatus` (pipeline status).
  - The **ขั้นตอน (stage)** column already shows `รอลงนาม` (stage `wait_esign`) and `เผยแพร่แล้ว` (stage `public`) — but there is **no dedicated e-Sign status** distinguishing ลงนามแล้ว / รอลงนาม / ยกเลิก / ถูกปฏิเสธ. Stage caps at `wait_esign` because `deriveStageFromWorkflow(6) → 'wait_esign'` and nothing advances it to `public` until `published_date`/status changes. So "ถ้าลงนามเรียบร้อยแล้วก็ควรแสดงสถานะ esign ได้อย่างถูกต้อง" = add an explicit e-Sign column driven by real esign fields.
  - Date shown via `formatThaiDate(doc.updated_at)` → "7 กันยายน 2569" (long Thai). User wants numeric `dd/mm/yyyy`.
- **`admin/laws`** renders `pages/admin/AdminLawListPage.vue`. Columns: #, ชื่อกฎหมาย, ประเภท, สถานะ (`effectiveStatusLabel` = ร่าง or meta_status), แก้ไขล่าสุด (`formatThaiDate(doc.date)`), จัดการ (edit/eye/menu). Filters: search + `filterType` + `filterStatus` (workflowStage) + sort. Data source: `GET /api/reports/summary` → `ReportController::documents()`.
  - No publish/e-Sign columns; user wants ประเภทสถานะกฎหมาย + สถานะเผยแพร่ + สถานะ e-Sign shown clearly, old docs → `–` in e-Sign, numeric date, unchanged action buttons, + e-Sign filter.
- **e-Sign status codes** (authoritative, from `EsignCallbackController` + `EsignSubmitService`):
  | Condition (on document status blob) | Meaning |
  |---|---|
  | `document_type === 'old'` | e-Sign not applicable → show `–` |
  | `esign_sign_status === 'Y'` | ลงนามแล้ว |
  | `esign_sign_status === 'N'` | ถูกปฏิเสธการลงนาม |
  | `esign_sign_status === 'C'` | ยกเลิกการส่งลงนาม |
  | `esign_submitted_at` set, code empty/other | รอลงนาม |
  | none of the above | ยังไม่ส่งลงนาม |
- **Backend gap:** neither `ReviewStore::listDocuments()` (feeds pipeline table) nor `ReviewStore::listLawMeta()` (feeds ReportController) currently expose `esign_sign_status` / `esign_submitted_at`. Both must add these two fields. `ReportController::documents()` must pass them through.
- **Date util:** `resources/js/utils/thaiDate.ts` has `formatThaiDate` (long) / `formatThaiDateShort` / `formatThaiDateTime` — none numeric. Add `formatThaiDateNumeric` (dd/mm/yyyy พ.ศ.).

### Decisions locked with the user
1. Date = **dd/mm/yyyy พ.ศ.** (Buddhist year, +543), e.g. `07/09/2569`, on both tables' "แก้ไขล่าสุด".
2. e-Sign column shows **all real states**: ลงนามแล้ว / รอลงนาม / ยกเลิกการส่ง / ถูกปฏิเสธ / ยังไม่ส่งลงนาม; **old docs → `–`**.
3. `admin/laws` gains a **"สถานะ e-Sign" filter** alongside existing search/ประเภท/สถานะ. Keep existing สถานะ (workflowStage) filter as-is.
4. Action buttons unchanged on both tables.

## Architecture / proposed approach
One shared derivation, reused on both tables: add `esignStatusLabel(...)` + `esignStatusColor(...)` to the existing `resources/js/utils/esignStatus.ts` (single source of truth, matching the backend table above). Backend surfaces `esign_sign_status` + `esign_submitted_at` in both list endpoints; the two Vue tables map those into a new column + filter and swap the date formatter to the new numeric one. No new endpoints, no new components (reuse `v-chip`).

Order: backend field exposure first (Tasks 1-3), shared util (Tasks 4-5), then each table (Tasks 6-7, 8-9), then verify (Task 10).

---

## Step-by-step tasks

### Task 1 — Backend: expose esign fields in `listDocuments()`
**File:** `app/Services/ReviewStore.php`, inside `listDocuments()`'s `$documents[] = [ ... ]` array (currently ends ~line 188 with `workflow_updated_at`). Add two lines before the closing `];`:

```php
                'workflow_updated_at' => $status['workflow_updated_at'] ?? null,
                'esign_sign_status' => isset($status['esign_sign_status']) ? (string) $status['esign_sign_status'] : null,
                'esign_submitted_at' => $status['esign_submitted_at'] ?? null,
```
(The first line already exists — match it and append the two new lines.)

**Verify:** `cd apps/app-laravel && ./vendor/bin/pint app/Services/ReviewStore.php --test` → PASS/no diff.

---

### Task 2 — Backend: expose esign fields in `listLawMeta()`
**File:** `app/Services/ReviewStore.php`, inside `listLawMeta()`'s `$rows[] = [ ... ]` (ends ~line 272 with `workflow_completed_step`). Append before the closing `];`:

```php
                    'workflow_completed_step' => isset($status['workflow_completed_step']) ? (int) $status['workflow_completed_step'] : null,
                    'esign_sign_status' => isset($status['esign_sign_status']) ? (string) $status['esign_sign_status'] : null,
                    'esign_submitted_at' => $status['esign_submitted_at'] ?? null,
```
(First line exists — match and append the two.)

> Note: `listLawMeta` is cached 180s via `Cache::remember('law-meta-list', ...)`. After deploy the column appears within 3 min or on cache flush; acceptable. Mention in verify to run `php artisan cache:clear` before manual check.

**Verify:** same pint check passes.

---

### Task 3 — Backend: pass esign fields through `ReportController::documents()`
**File:** `app/Http/Controllers/Api/ReportController.php`, in the `documents()` map (~line 179 ends with `workflow_completed_step`). Append:

```php
            'workflow_completed_step' => isset($r['workflow_completed_step']) ? (int) $r['workflow_completed_step'] : null,
            'esign_sign_status' => $r['esign_sign_status'] ?? null,
            'esign_submitted_at' => $r['esign_submitted_at'] ?? null,
```
(First line exists — match and append the two.)

**Verify:** `docker compose exec -T laravel-app php artisan test --filter=ReportSummaryTest` → passes (no assertion on these new optional keys should break; if a strict-shape assertion fails, it is revealing an over-strict test — report it, do not weaken the feature).

---

### Task 4 — Frontend types: add esign fields to the two list DTOs
**File:** `resources/js/types/document.ts`.

4a. In `interface DocumentListItem` (ends ~line 264 with `source_file`), add:
```ts
  source_file?: string | null;
  esign_sign_status?: string | null;
  esign_submitted_at?: string | null;
```
(Match `source_file?` line, append the two.)

4b. In `interface ReportDocument` (ends ~line 431 with `workflow_completed_step`), add:
```ts
  workflow_completed_step: number | null;
  esign_sign_status?: string | null;
  esign_submitted_at?: string | null;
```
(Match the line, append the two.)

**Verify:** `npm run typecheck` still passes.

---

### Task 5 (TDD RED→GREEN) — Frontend: shared esign status label/color + numeric date
5a. **RED check** — create `resources/js/utils/esignStatus.check.ts`:
```ts
// dev-only assertion — no JS test runner in this app. Run:
//   cd apps/app-laravel && npx tsx resources/js/utils/esignStatus.check.ts
import { esignStatusLabel, esignStatusColor } from './esignStatus';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error('FAIL: ' + msg);
}

// Old docs → dash, no e-sign
assert(esignStatusLabel({ document_type: 'old' }) === '–', 'old doc shows dash');
assert(esignStatusColor({ document_type: 'old' }) === 'grey', 'old doc dash is grey');

// New doc states
assert(esignStatusLabel({ esign_sign_status: 'Y' }) === 'ลงนามแล้ว', 'Y = signed');
assert(esignStatusLabel({ esign_sign_status: 'N' }) === 'ถูกปฏิเสธ', 'N = rejected');
assert(esignStatusLabel({ esign_sign_status: 'C' }) === 'ยกเลิกการส่ง', 'C = cancelled');
assert(esignStatusLabel({ esign_submitted_at: '2025-01-01T00:00:00Z' }) === 'รอลงนาม', 'submitted no code = waiting');
assert(esignStatusLabel({}) === 'ยังไม่ส่งลงนาม', 'nothing = not sent');

assert(esignStatusColor({ esign_sign_status: 'Y' }) === 'success', 'signed green');
assert(esignStatusColor({ esign_sign_status: 'N' }) === 'error', 'rejected red');
assert(esignStatusColor({ esign_sign_status: 'C' }) === 'warning', 'cancelled warning');
assert(esignStatusColor({ esign_submitted_at: 'x' }) === 'admin-primary', 'waiting admin-primary');
assert(esignStatusColor({}) === 'grey', 'not-sent grey');

console.log('esignStatus.check.ts: all passed');
```
Run it → **fails** (`esignStatusLabel` not exported yet).

5b. **GREEN** — append to `resources/js/utils/esignStatus.ts`:
```ts
type EsignStatusInput = {
  document_type?: string | null;
  esign_sign_status?: string | null;
  esign_submitted_at?: string | null;
};

/** Human e-Sign status for admin tables. Old docs → '–' (no e-Sign). */
export function esignStatusLabel(row: EsignStatusInput | null | undefined): string {
  if (!row) return 'ยังไม่ส่งลงนาม';
  if (row.document_type === 'old') return '–';
  const code = String(row.esign_sign_status ?? '').trim().toUpperCase();
  if (code === 'Y') return 'ลงนามแล้ว';
  if (code === 'N') return 'ถูกปฏิเสธ';
  if (code === 'C') return 'ยกเลิกการส่ง';
  if (row.esign_submitted_at) return 'รอลงนาม';
  return 'ยังไม่ส่งลงนาม';
}

export function esignStatusColor(row: EsignStatusInput | null | undefined): string {
  if (!row || row.document_type === 'old') return 'grey';
  const code = String(row.esign_sign_status ?? '').trim().toUpperCase();
  if (code === 'Y') return 'success';
  if (code === 'N') return 'error';
  if (code === 'C') return 'warning';
  if (row.esign_submitted_at) return 'admin-primary';
  return 'grey';
}
```
Run the check → `esignStatus.check.ts: all passed`.

5c. **Numeric date** — append to `resources/js/utils/thaiDate.ts`:
```ts
export function formatThaiDateNumeric(value: DateInput): string {
  const date = toDate(value);
  if (!date) return '';

  const day = pad(date.getDate());
  const month = pad(date.getMonth() + 1);
  const year = date.getFullYear() > 2400 ? date.getFullYear() : date.getFullYear() + 543;
  return `${day}/${month}/${year}`;
}
```

**Verify:** `npx tsx resources/js/utils/esignStatus.check.ts` passes; `npm run typecheck` passes.

---

### Task 6 — `DocumentPipelineTable.vue`: add e-Sign column + data
**File:** `components/admin/DocumentPipelineTable.vue`.

6a. Import helpers — update the imports block (~line 154):
```ts
import { formatThaiDateNumeric } from '../../utils/thaiDate';
import { esignStatusLabel, esignStatusColor } from '../../utils/esignStatus';
```
(Replace the existing `import { formatThaiDate } from '../../utils/thaiDate';` line.)

6b. Change `formatDate` (~line 321) to numeric:
```ts
function formatDate(iso?: string | null): string {
  if (!iso) return '—';
  return formatThaiDateNumeric(iso) || '—';
}
```

6c. Extend `interface Row` (~line 161) with esign fields:
```ts
  documentType: 'new' | 'old';
  esignLabel: string;
  esignColor: string;
```
(Append after `documentType`.)

6d. In the `rows` computed (~line 243), add per-row:
```ts
    documentType: doc.document_type ?? 'new',
    esignLabel: esignStatusLabel(doc),
    esignColor: esignStatusColor(doc),
```
(Append inside the mapped object, after `documentType`.)

6e. Add a header between สถานะเผยแพร่ and ขั้นตอน (~line 217):
```ts
  { title: 'สถานะเผยแพร่', key: 'publishedDate', sortable: false, align: 'center' as const, width: 120 },
  { title: 'e-Sign', key: 'esign', sortable: false, align: 'center' as const, width: 130 },
  { title: 'ขั้นตอน', key: 'stage', sortable: false, align: 'center' as const, width: 140 },
```

6f. Add the cell template after the `#item.publishedDate` template (~line 97):
```vue
      <template #item.esign="{ item }">
        <v-chip size="small" :color="item.esignColor" variant="tonal" rounded="pill">
          {{ item.esignLabel }}
        </v-chip>
      </template>
```

**Verify:** `npm run typecheck` passes.

---

### Task 7 — `DocumentPipelineTable.vue`: add e-Sign filter
**File:** same. Keep existing search + type + status filters; add a 4th select.

7a. Add reactive state near `filterStatus` (~line 183):
```ts
const filterEsign = ref<string | null>(null);
```

7b. Add options after `statusOptions` (~line 194):
```ts
const esignOptions = [
  { title: 'ลงนามแล้ว', value: 'signed' },
  { title: 'รอลงนาม', value: 'waiting' },
  { title: 'ยังไม่ส่งลงนาม', value: 'not_sent' },
  { title: 'ยกเลิกการส่ง', value: 'cancelled' },
  { title: 'ถูกปฏิเสธ', value: 'rejected' },
];

function esignBucket(doc: DocumentListItem): string {
  if (doc.document_type === 'old') return 'old';
  const code = String(doc.esign_sign_status ?? '').trim().toUpperCase();
  if (code === 'Y') return 'signed';
  if (code === 'N') return 'rejected';
  if (code === 'C') return 'cancelled';
  if (doc.esign_submitted_at) return 'waiting';
  return 'not_sent';
}
```

7c. Extend `filteredDocs` (~line 196) to apply the esign filter:
```ts
const filteredDocs = computed(() => {
  const needle = (filterText.value ?? '').trim().toLowerCase();

  return docs.value.filter((d) => {
    const searchableTitle = (d.title || d.document_id || d.source_file || '').toLowerCase();
    return (!needle || searchableTitle.includes(needle)) &&
    (!filterType.value || d.law_type === filterType.value) &&
    (!filterStatus.value || d.status === filterStatus.value) &&
    (!filterEsign.value || esignBucket(d) === filterEsign.value);
  });
});
```

7d. Add the select in the `.pipeline-filter-selects` div, after the status `v-select` (~line 44):
```vue
        <v-select
          v-model="filterEsign"
          class="pipeline-filter-select"
          :items="esignOptions"
          item-title="title"
          item-value="value"
          clearable
          density="compact"
          variant="outlined"
          hide-details
          label="e-Sign: ทั้งหมด"
        />
```

**Verify:** `npm run typecheck` passes; `npm run build` succeeds.

---

### Task 8 — `AdminLawListPage.vue`: add publish + e-Sign columns, numeric date
**File:** `pages/admin/AdminLawListPage.vue`.

8a. Update imports (~line 219):
```ts
import { formatThaiDateNumeric } from '../../utils/thaiDate';
import { esignStatusLabel, esignStatusColor } from '../../utils/esignStatus';
```
(Replace the `import { formatThaiDate } ...` line. If `formatThaiDate` is still referenced elsewhere in the file, keep both — grep first: `search_files "formatThaiDate\b" AdminLawListPage.vue`. Currently the only use is line 363.)

8b. Extend `interface LawRow` (~line 330) with:
```ts
  editedAt: string;
  rawDate: string;
  publishStatus: string;
  esignLabel: string;
  esignColor: string;
```
(Append after `rawDate`.)

8c. In the `laws` computed (~line 348), add per-row and switch the date:
```ts
    editedAt: formatThaiDateNumeric(doc.date) || '-',
    rawDate: doc.date ?? '',
    publishStatus: doc.published_date ? 'เผยแพร่แล้ว' : 'ยังไม่เผยแพร่',
    esignLabel: esignStatusLabel(doc),
    esignColor: esignStatusColor(doc),
```
(Replace the existing `editedAt: formatThaiDate(doc.date) || '-',` line and append the three new fields.)

8d. Add table headers between สถานะ and แก้ไขล่าสุด (~line 101-102):
```vue
            <th>สถานะกฎหมาย</th>
            <th>เผยแพร่</th>
            <th>e-Sign</th>
            <th>แก้ไขล่าสุด</th>
```
(Rename the existing `<th>สถานะ</th>` to `<th>สถานะกฎหมาย</th>` and insert เผยแพร่ + e-Sign before แก้ไขล่าสุด.)

8e. Add the matching cells. The current สถานะ cell is the `<td>` with `effectiveStatusLabel(law)` (~lines 149-159). Immediately after that `</td>`, insert two new `<td>`:
```vue
            <td>
              <v-chip size="x-small" :color="law.publishStatus === 'เผยแพร่แล้ว' ? 'success' : 'grey'" variant="tonal" rounded="pill">
                {{ law.publishStatus }}
              </v-chip>
            </td>
            <td>
              <v-chip size="x-small" :color="law.esignColor" variant="tonal" rounded="pill">
                {{ law.esignLabel }}
              </v-chip>
            </td>
```
(The empty-colspan row at line 108 `colspan="6"` must become `colspan="8"` — two columns added.)

**Verify:** `npm run typecheck` passes.

---

### Task 9 — `AdminLawListPage.vue`: add e-Sign filter
**File:** same.

9a. Add reactive state near `filterStatus` (~line 238):
```ts
const filterEsign = ref<string | null>(null);
```

9b. Add options after `statusOptions` (~line 392):
```ts
const esignFilterOptions = [
  { label: 'ทุกสถานะ e-Sign', value: null },
  { label: 'ลงนามแล้ว', value: 'signed' },
  { label: 'รอลงนาม', value: 'waiting' },
  { label: 'ยังไม่ส่งลงนาม', value: 'not_sent' },
  { label: 'ยกเลิกการส่ง', value: 'cancelled' },
  { label: 'ถูกปฏิเสธ', value: 'rejected' },
];

function esignBucketOf(law: LawRow): string {
  return law.esignBucket;
}
```
> Simpler: store the bucket on the row instead of recomputing. In Task 8c also add `esignBucket:` to the row (see below) and drop `esignBucketOf`. Add to `interface LawRow`: `esignBucket: string;` and in the map:
> ```ts
>     esignBucket: (() => {
>       if (doc.document_type === 'old') return 'old';
>       const code = String(doc.esign_sign_status ?? '').trim().toUpperCase();
>       if (code === 'Y') return 'signed';
>       if (code === 'N') return 'rejected';
>       if (code === 'C') return 'cancelled';
>       if (doc.esign_submitted_at) return 'waiting';
>       return 'not_sent';
>     })(),
> ```

9c. Add reset-to-page-1 on change — extend the `watch` (~line 259):
```ts
watch([search, filterType, filterStatus, filterEsign, sortOrder], () => {
  page.value = 1;
});
```

9d. Extend `filteredLaws` (~line 400) — add after the `filterStatus` filter line:
```ts
  if (filterEsign.value) result = result.filter((l) => l.esignBucket === filterEsign.value);
```

9e. Add the select in the filter bar, after the สถานะ select (~line 78, before the sort select):
```vue
      <v-select
        v-model="filterEsign"
        :items="esignFilterOptions"
        item-title="label"
        item-value="value"
        label="สถานะ e-Sign"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width: 170px"
      />
```

**Verify:** `npm run typecheck` passes; `npm run build` succeeds.

---

### Task 10 — Verify + smoke
```bash
cd apps/app-laravel
npx tsx resources/js/utils/esignStatus.check.ts     # esignStatus.check.ts: all passed
npm run typecheck                                    # no errors
npm run build                                        # build success
docker compose exec -T laravel-app php artisan cache:clear   # flush law-meta-list cache
docker compose exec -T laravel-app php artisan test --filter=ReportSummaryTest   # passes
```
**Manual (dev):**
1. `/admin/upload` — queue table shows a new **e-Sign** column: new doc waiting = "รอลงนาม" (admin-primary), signed = "ลงนามแล้ว" (green), old doc = "–". Date column is `dd/mm/yyyy` พ.ศ. e-Sign filter narrows rows.
2. `/admin/laws` — table shows สถานะกฎหมาย + เผยแพร่ + e-Sign columns, old docs "–" in e-Sign, numeric date, edit/eye/menu actions unchanged, "สถานะ e-Sign" filter works.

Commit each logical group (backend / util / pipeline table / laws page) with descriptive messages. Do NOT push. No AI/co-author attribution.

---

## Tests / validation summary
| Layer | Command | Expected |
|---|---|---|
| Shared util | `npx tsx resources/js/utils/esignStatus.check.ts` | all passed |
| Types/build | `npm run typecheck && npm run build` | no errors / success |
| Backend report | `php artisan test --filter=ReportSummaryTest` | passes |

## Risks, tradeoffs, open questions
- **`listLawMeta` 180s cache:** admin/laws e-Sign column may lag up to 3 min after a sign event until cache expiry. Acceptable; `cache:clear` forces immediate. If unacceptable, a follow-up can drop the cache TTL — out of scope.
- **Column width on narrow screens:** admin/laws goes 6→8 columns; on <1200px it may need horizontal scroll. `v-table` scrolls by default; if it looks cramped, wrap in `.v-table--fixed` or reduce padding — flag visually, do not block.
- **e-Sign vs ขั้นตอน overlap (pipeline table):** the existing ขั้นตอน column already says "รอลงนาม" for `wait_esign`. The new e-Sign column is more precise (adds ลงนามแล้ว/ยกเลิก/ถูกปฏิเสธ). Keeping both is intentional per the request ("แสดงสถานะ esign ได้อย่างถูกต้อง"); they answer different questions (workflow position vs signature outcome). If the user later finds it redundant, the ขั้นตอน column can cap at `wait_esign` and defer signature detail to e-Sign — not now.
- **`admin/laws` only lists info-completed docs** (`workflow_completed_step >= 4`). Docs still in early pipeline never appear here — correct, unchanged; e-Sign column is only meaningful for docs that reached the sign step anyway.
- **`old` doc with a stray `esign_*` field:** guard returns `–` first on `document_type === 'old'`, so no leakage. Confirmed in check test.
