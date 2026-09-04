# Plan: Public Version-Relations Page with Download — /law/:id/versions

## Goal

Replace the inline `VersionHistoryTimeline` in the "ดำเนินการ" panel on `/law/:id` with a button that redirects to a new public page (`/law/:id/versions`) showing the full version timeline, document relations, and per-version download buttons with correct Thai filenames.

---

## Current Context

### What exists

- `LawInfoPanel.vue` (`resources/js/components/law/LawInfoPanel.vue`) — right-side info panel used by `LawDocumentView.vue`. Section "ดำเนินการ" at lines 73–87 renders:
  - `<VersionHistoryTimeline>` inline when `versions.length >= 2`
  - disabled `<v-btn>ความสัมพันธ์กฎหมาย</v-btn>` (disabled, does nothing)

- `VersionHistoryTimeline.vue` (`resources/js/components/law/VersionHistoryTimeline.vue`) — shows version cards with click→SweetAlert→navigate. No download buttons.

- `VersionChainItem` type (`resources/js/types/versionChain.ts`) — fields: `document_id`, `version_label`, `is_current`, `status`, `change_status`, `issuer`, `agency`, `promulgation_date`, `title`. No `source_file`, no `source_type`, no download info.

- `ReviewStore::getVersionChain()` (PHP, `app/Services/ReviewStore.php:273`) — returns `{current_document_id, versions[]}`. Does NOT include `source_file`, `source_type`, or `source_path` per version item.

- `GET /api/documents/{documentId}/versions` → `ReviewController::versions()` — returns the chain.

- `DocumentFileController::show()` — `GET /api/documents/{id}/file?download=1` → downloads original file (PDF/DOCX/DOC). **Already implemented** in previous plan.

- `documentFileDownloadUrl(documentId)` — already in `resources/js/api/client.ts`, returns `/api/documents/{id}/file?download=1`.

- Router: `/documents/:documentId/relations` = admin `LawRelationsPage` (edit, protected). No public relations page yet.

- `AdminShowRelationsPage.vue` — admin-only, shows relation tree. Source of the relation UI design to reference.

- `docRelations` and `sectionRelations` in `LawDocumentView.vue` — relations are already loaded via `documentStore.review.relations[]`.

### What's missing

1. `VersionChainItem` does not expose `source_type` or `has_file` — need to add to PHP response so the download button knows whether a file is available.
2. No public page for version timeline + relations + downloads.
3. Router route `/law/:id/versions` not registered.
4. "ดำเนินการ" section needs to replace inline timeline + disabled button with a single "ดูเวอร์ชันและความสัมพันธ์" redirect button.

---

## Architecture

Add `source_type` and `has_file` to each `VersionChainItem` in `ReviewStore::getVersionChain()` (one field lookup from `getStatus()`), expose them in the TS type, then create a new single-file Vue page `LawVersionsPage.vue` at `/law/:id/versions` that pulls data from the existing `versionStore` + a second `documentStore.fetch()` call per version for relations, and renders a timeline with download buttons and a grouped-relations panel. Finally, update `LawInfoPanel.vue` to replace the inline timeline with one redirect button.

---

## Step-by-step Tasks

### Task 1 — Add `source_type` and `has_file` to PHP version chain

**File:** `apps/app-laravel/app/Services/ReviewStore.php`

Find `getVersionChain()` (line 333–352). The `$versions[]` array-push currently has these fields:
```php
$versions[] = [
    'document_id'      => $row['document_id'],
    'version_label'    => 'v'.($index + 1).'.0',
    'is_current'       => $row['document_id'] === $currentId,
    'status'           => ...,
    'change_status'    => ...,
    'issuer'           => ...,
    'agency'           => ...,
    'promulgation_date'=> ...,
    'title'            => ...,
];
```

Replace the `$versions[]` push block with:
```php
$vStatus = $this->getStatus($row['document_id']) ?? [];
$sourceType = (string) ($vStatus['source_type'] ?? '');
$sourcePath = (string) ($vStatus['source_path'] ?? '');
$hasFile = $sourcePath !== '' && is_file($this->absolutePath($sourcePath));

$versions[] = [
    'document_id'      => $row['document_id'],
    'version_label'    => 'v'.($index + 1).'.0',
    'is_current'       => $row['document_id'] === $currentId,
    'status'           => ($row['meta_status'] ?? '') !== '' ? $row['meta_status'] : (string) ($row['status'] ?? ''),
    'change_status'    => (string) ($row['change_status'] ?? ''),
    'issuer'           => (string) ($row['issuer'] ?? ''),
    'agency'           => $row['agencies'][0] ?? '',
    'promulgation_date'=> (string) ($row['promulgation_date'] ?? ''),
    'title'            => (string) ($row['title'] ?? ''),
    'source_type'      => $sourceType,
    'has_file'         => $hasFile,
    'source_file'      => (string) ($vStatus['source_file'] ?? ''),
];
```

**Verify:**
```bash
docker compose exec laravel-app php artisan test --filter=ReviewControllerVersionsTest
```
Expected: existing version tests still pass (we only add fields, never remove).

---

### Task 2 — Update `VersionChainItem` TypeScript type

**File:** `apps/app-laravel/resources/js/types/versionChain.ts`

Replace entire file:
```typescript
export interface VersionChainItem {
  document_id: string;
  version_label: string;
  is_current: boolean;
  status: string;
  change_status: string;
  issuer: string;
  agency: string;
  promulgation_date: string;
  title: string;
  source_type: string;   // e.g. 'pdf', 'docx', 'pdf_scan'
  has_file: boolean;     // true when the physical file exists on storage
  source_file: string;   // original filename for download naming
}

export interface VersionChain {
  current_document_id: string;
  versions: VersionChainItem[];
}
```

**Verify:**
```bash
cd apps/app-laravel && npx vue-tsc --noEmit 2>&1 | head -20
```
Expected: no new type errors.

---

### Task 3 — Create the new public page `LawVersionsPage.vue`

**File:** `apps/app-laravel/resources/js/pages/law/LawVersionsPage.vue`

This page:
- Fetches version chain via `versionStore.fetch(documentId)`.
- For each version, shows: version label, title, dates, status chip, change_status.
- If `v.has_file` → shows a `<v-btn :href="documentFileDownloadUrl(v.document_id)">` download button labelled with the filename (`v.source_file || v.title || v.version_label`).
- Shows `review.relations[]` of the **root document** (the one whose `/law/:id` we came from) grouped by type using the same `groupRelations()` logic from `LawDocumentView.vue`.

```vue
<template>
  <div class="lvp">
    <ELawNavbar @go-admin="router.push('/admin')" />

    <div class="lvp-topbar">
      <v-container style="max-width:1200px" class="py-0">
        <div class="d-flex align-center ga-2 py-3">
          <v-btn variant="text" size="small" prepend-icon="mdi-arrow-left" class="text-none"
            @click="router.push(`/law/${props.documentId}`)">
            ย้อนกลับ
          </v-btn>
          <span class="text-body-2 text-medium-emphasis">เวอร์ชันและความสัมพันธ์</span>
        </div>
      </v-container>
    </div>

    <v-container style="max-width:1200px" class="py-6">
      <div v-if="versionStore.loading || documentStore.loading"
        class="d-flex justify-center align-center pa-16 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate />
        <span>กำลังโหลด...</span>
      </div>

      <template v-else>
        <!-- Doc title header -->
        <div class="lvp-title mb-6">
          <h1 class="text-h6 font-weight-bold">{{ meta.title || documentStore.review?.source_file }}</h1>
          <div v-if="meta.law_type" class="text-caption text-medium-emphasis mt-1">{{ meta.law_type }}</div>
        </div>

        <div class="lvp-grid">
          <!-- ─── VERSION TIMELINE ─── -->
          <v-card class="lvp-timeline" elevation="0" border>
            <v-card-title class="text-body-2 font-weight-bold d-flex align-center ga-2">
              <v-icon icon="mdi-history" size="18" />
              ประวัติเวอร์ชัน
            </v-card-title>
            <v-card-text class="px-3 pb-4">
              <div v-if="!versionStore.versions.length" class="text-body-2 text-medium-emphasis">
                ไม่พบข้อมูลเวอร์ชัน
              </div>
              <div v-for="v in orderedVersions" :key="v.document_id" class="lvp-ver-card"
                :class="{ 'is-current': v.is_current, 'is-viewing': v.document_id === props.documentId }">
                <div class="d-flex align-center justify-space-between ga-2 mb-1">
                  <span class="font-weight-bold text-body-2">{{ v.version_label }}</span>
                  <div class="d-flex ga-1">
                    <v-chip size="x-small" :color="v.is_current ? 'success' : 'default'" variant="tonal" rounded="pill">
                      {{ v.is_current ? (v.status || 'มีผลบังคับใช้') : 'ถูกแทนที่' }}
                    </v-chip>
                    <v-chip v-if="v.document_id === props.documentId" size="x-small" color="admin-primary"
                      variant="flat" rounded="pill">กำลังดู</v-chip>
                  </div>
                </div>

                <div v-if="v.title" class="text-body-2 mb-1 text-truncate">{{ v.title }}</div>
                <div class="text-caption text-medium-emphasis d-flex flex-column ga-1 mb-2">
                  <span v-if="v.promulgation_date">
                    <v-icon icon="mdi-calendar" size="11" /> ประกาศ {{ formatLawDate(v.promulgation_date) }}
                  </span>
                  <span v-if="v.issuer || v.agency">
                    <v-icon icon="mdi-office-building-outline" size="11" /> {{ v.issuer || v.agency }}
                  </span>
                  <span v-if="v.change_status" class="text-caption">{{ v.change_status }}</span>
                </div>

                <div class="d-flex ga-2 flex-wrap">
                  <v-btn
                    v-if="v.document_id !== props.documentId"
                    size="x-small"
                    variant="outlined"
                    prepend-icon="mdi-eye-outline"
                    class="text-none"
                    :to="`/law/${encodeURIComponent(v.document_id)}`"
                  >
                    ดูเอกสาร
                  </v-btn>
                  <v-btn
                    v-if="v.has_file"
                    size="x-small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-download"
                    class="text-none"
                    :href="documentFileDownloadUrl(v.document_id)"
                    :download="downloadName(v)"
                  >
                    ดาวน์โหลด
                  </v-btn>
                </div>
              </div>
            </v-card-text>
          </v-card>

          <!-- ─── RELATIONS PANEL ─── -->
          <v-card class="lvp-relations" elevation="0" border>
            <v-card-title class="text-body-2 font-weight-bold d-flex align-center ga-2">
              <v-icon icon="mdi-sitemap-outline" size="18" />
              ความสัมพันธ์กฎหมาย
            </v-card-title>
            <v-card-text class="px-3 pb-4">
              <div v-if="!allRelations.length" class="text-body-2 text-medium-emphasis">
                ไม่มีความสัมพันธ์ที่บันทึกไว้
              </div>
              <template v-else>
                <div v-for="group in groupedRelations" :key="group.type" class="lvp-rel-group mb-4">
                  <div class="text-caption font-weight-bold text-medium-emphasis mb-2 text-uppercase">
                    {{ group.label }}
                  </div>
                  <div v-for="rel in group.items" :key="rel.id" class="lvp-rel-row">
                    <span class="mdi lvp-rel-icon" :class="RELATION_TYPE_ICONS[rel.type] ?? 'mdi-link-variant'" />
                    <div class="flex-1 min-width-0">
                      <div class="text-body-2 text-truncate">{{ rel.target_title }}</div>
                      <div v-if="rel.target_section" class="text-caption text-medium-emphasis">
                        {{ rel.target_section }}
                      </div>
                      <div v-if="rel.note" class="text-caption text-medium-emphasis">— {{ rel.note }}</div>
                    </div>
                    <div class="d-flex ga-1 flex-shrink-0">
                      <v-btn
                        v-if="rel.target_document_id"
                        size="x-small"
                        variant="text"
                        prepend-icon="mdi-eye-outline"
                        class="text-none"
                        :to="`/law/${encodeURIComponent(rel.target_document_id)}`"
                      >
                        ดู
                      </v-btn>
                      <v-btn
                        v-if="rel.target_document_id"
                        size="x-small"
                        variant="text"
                        prepend-icon="mdi-download"
                        class="text-none"
                        :href="relatedDocumentFileUrl(props.documentId, rel.target_document_id)"
                        :download="rel.target_title || rel.target_document_id"
                      >
                        โหลด
                      </v-btn>
                      <v-btn
                        v-else-if="safeUrl(rel.url)"
                        size="x-small"
                        variant="text"
                        prepend-icon="mdi-open-in-new"
                        class="text-none"
                        :href="safeUrl(rel.url)!"
                        target="_blank"
                        rel="noopener"
                      >
                        เปิด
                      </v-btn>
                    </div>
                  </div>
                </div>
              </template>
            </v-card-text>
          </v-card>
        </div>
      </template>
    </v-container>

    <ELawFooter />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useVersionStore } from '../../stores/versionStore';
import { useDocumentStore } from '../../stores/documentStore';
import { documentFileDownloadUrl, relatedDocumentFileUrl } from '../../api/client';
import { RELATION_TYPE_ICONS } from '../../types/lawRelation';
import type { LawMeta, LawRelation, RelationType } from '../../types/document';
import type { VersionChainItem } from '../../types/versionChain';
import { formatThaiDate } from '../../utils/thaiDate';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import ELawFooter from '../../components/shared/ELawFooter.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const versionStore = useVersionStore();
const documentStore = useDocumentStore();

// Fetch on mount / param change — same pattern as LawDocumentView
import { watch } from 'vue';
watch(() => props.documentId, (id) => {
  void versionStore.fetch(id);
  if (documentStore.documentId !== id || !documentStore.review) {
    void documentStore.fetch(id);
  }
}, { immediate: true });

const EMPTY_META: LawMeta = {
  status: '', law_type: '', law_group: '', change_status: null, law_groups: [],
  agency: '', signer_group: null, agencies: [], keywords: [],
  promulgation_date: '', effective_date: '', published_date: '', expiry_date: null,
  section_count: null, title: '', gazette_reference: '', royal_command: '',
  repealed_laws: [], imported_by: '', parent_document_id: null,
  parent_document_ids: [], access_scope: 'public', permission_group_ids: [],
};
const meta = computed<LawMeta>(() => documentStore.review?.law_meta ?? EMPTY_META);
const orderedVersions = computed(() => [...versionStore.versions].reverse());

// Relations from the viewed document
const allRelations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);

const RELATION_GROUP_LABELS: Record<RelationType, string> = {
  repeals: 'กฎหมายที่ถูกยกเลิก',
  amends: 'กฎหมายที่แก้ไขเพิ่มเติม',
  supersedes: 'กฎหมายที่ถูกแทนที่',
  issued_under: 'ออกตามอำนาจของ',
  related: 'กฎหมายที่เกี่ยวข้อง',
};
const RELATION_GROUP_ORDER: RelationType[] = ['repeals', 'supersedes', 'amends', 'issued_under', 'related'];

const groupedRelations = computed(() =>
  RELATION_GROUP_ORDER
    .map((type) => ({
      type,
      label: RELATION_GROUP_LABELS[type],
      items: allRelations.value.filter((r) => r.type === type),
    }))
    .filter((g) => g.items.length > 0),
);

function formatLawDate(value: string): string {
  return formatThaiDate(value) || value || '';
}

function downloadName(v: VersionChainItem): string {
  const base = v.source_file || v.title || v.version_label || v.document_id;
  // Sanitize: keep only safe characters. If Thai title, browser will encode it.
  return base.trim() || 'document';
}

function safeUrl(url: string | null | undefined): string | null {
  if (!url) return null;
  const t = url.trim();
  return /^https?:\/\//i.test(t) ? t : null;
}
</script>

<style scoped>
.lvp { min-height: 100vh; background: #f8fafc; }
.lvp-topbar { background: #fff; border-bottom: 1px solid #e5e7eb; }
.lvp-title { }
.lvp-grid {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 20px;
  align-items: start;
}
@media (max-width: 700px) {
  .lvp-grid { grid-template-columns: 1fr; }
}
.lvp-ver-card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 12px 14px;
  margin-bottom: 10px;
  background: #fff;
  transition: border-color 0.15s;
}
.lvp-ver-card.is-viewing {
  border-color: rgb(var(--v-theme-admin-primary));
  box-shadow: 0 0 0 1px rgb(var(--v-theme-admin-primary));
}
.lvp-ver-card.is-current { background: #f0fdf4; }
.lvp-rel-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid #f1f5f9;
}
.lvp-rel-row:last-child { border-bottom: none; }
.lvp-rel-icon { font-size: 16px; margin-top: 2px; flex-shrink: 0; }
</style>
```

**Verify:** File created, no syntax errors.

---

### Task 4 — Register the new route in the router

**File:** `apps/app-laravel/resources/js/router/index.ts`

Add the import after line 27 (`const LawPage = ...`):
```typescript
const LawVersionsPage = () => import('../pages/law/LawVersionsPage.vue');
```

Add the route after the `/law/:documentId` route (line 53):
```typescript
{ path: '/law/:documentId/versions', name: 'law-versions', component: LawVersionsPage, props: true, meta: { bareLayout: true } },
```

**Verify:**
```bash
grep "law-versions" apps/app-laravel/resources/js/router/index.ts
```
Expected: one match showing the route.

---

### Task 5 — Update `LawInfoPanel.vue` "ดำเนินการ" section

**File:** `apps/app-laravel/resources/js/components/law/LawInfoPanel.vue`

Replace lines 79–84 (the entire content of `<v-card-text class="d-flex flex-column ga-2">`):

Current:
```html
<v-card-text class="d-flex flex-column ga-2">
  <template v-if="versions && versions.length >= 2">
    <div class="text-caption font-weight-bold text-medium-emphasis mb-1">ประวัติเวอร์ชัน</div>
    <VersionHistoryTimeline :versions="versions" :viewed-document-id="viewedDocumentId ?? ''" />
  </template>
  <v-btn v-else flat variant="outlined" disabled prepend-icon="mdi-history" class="justify-start text-none">ดูประวัติการแก้ไข</v-btn>
  <v-btn flat variant="outlined" disabled prepend-icon="mdi-sitemap-outline" class="justify-start text-none">ความสัมพันธ์กฎหมาย</v-btn>
</v-card-text>
```

Replace with:
```html
<v-card-text class="d-flex flex-column ga-2">
  <v-btn
    flat
    variant="outlined"
    prepend-icon="mdi-history"
    class="justify-start text-none"
    :disabled="!viewedDocumentId"
    :to="viewedDocumentId ? `/law/${encodeURIComponent(viewedDocumentId)}/versions` : undefined"
  >
    ดูเวอร์ชันและความสัมพันธ์
    <v-chip
      v-if="versions && versions.length"
      size="x-small"
      color="primary"
      variant="tonal"
      rounded="pill"
      class="ml-2"
    >{{ versions.length }}</v-chip>
  </v-btn>
</v-card-text>
```

Also remove the `VersionHistoryTimeline` import from `<script setup>` since it's no longer used in this component:

Remove lines:
```typescript
import VersionHistoryTimeline from './VersionHistoryTimeline.vue';
```

**Verify:**
```bash
cd apps/app-laravel && npx vue-tsc --noEmit 2>&1 | head -20
```
Expected: no new errors.

---

### Task 6 — Add `source_type`, `has_file`, `source_file` to versions API test

**File:** `apps/app-laravel/tests/Feature/ReviewControllerVersionsTest.php`

Find the existing versions test (if any) or add to `DocumentFileTest.php`. Create a new test file:

```php
<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VersionChainFileInfoTest extends TestCase
{
    public function test_version_chain_includes_file_info(): void
    {
        $store = app(ReviewStore::class);

        // v1 — has a real PDF file
        $v1 = $store->generateDocumentId();
        $stored = $store->storeUpload(
            UploadedFile::fake()->create('law_v1.pdf', 10, 'application/pdf'),
            $v1,
        );
        $store->setStatus($v1, [
            'status' => 'done',
            'source_path' => $stored['relative_path'],
            'source_file' => 'law_v1.pdf',
        ]);
        $store->writeReviewDocument($v1, [
            'document_id' => $v1, 'source_file' => 'law_v1.pdf', 'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => [
                'title' => 'กฎหมาย v1', 'law_type' => 'ประกาศ', 'status' => 'ถูกแทนที่',
                'change_status' => null, 'agency' => '', 'agencies' => [],
                'promulgation_date' => '2023-01-01',
            ],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        // v2 — child of v1, no file
        $v2 = $store->generateDocumentId();
        $store->setStatus($v2, ['status' => 'done', 'source_file' => 'law_v2.pdf']);
        $store->writeReviewDocument($v2, [
            'document_id' => $v2, 'source_file' => 'law_v2.pdf', 'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => [
                'title' => 'กฎหมาย v2', 'law_type' => 'ประกาศ', 'status' => 'มีผลบังคับใช้',
                'change_status' => 'ปรับปรุงรายข้อ', 'agency' => '', 'agencies' => [],
                'promulgation_date' => '2024-01-01',
                'parent_document_id' => $v1,
            ],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        $response = $this->getJson("/api/documents/{$v2}/versions");
        $response->assertOk();
        $response->assertJsonCount(2, 'versions');

        // v1 has file (physical file exists in storage)
        $v1Item = collect($response->json('versions'))->firstWhere('document_id', $v1);
        $this->assertTrue($v1Item['has_file'], 'v1 should have has_file=true');
        $this->assertSame('law_v1.pdf', $v1Item['source_file']);

        // v2 has no file (no source_path in status)
        $v2Item = collect($response->json('versions'))->firstWhere('document_id', $v2);
        $this->assertFalse($v2Item['has_file'], 'v2 should have has_file=false');

        $store->deleteDocument($v1);
        $store->deleteDocument($v2);
    }
}
```

**Run failing test first:**
```bash
docker compose exec laravel-app php artisan test --filter=VersionChainFileInfoTest
```
Expected: FAIL (fields missing before Task 1 is implemented).

**After Task 1:**
```bash
docker compose exec laravel-app php artisan test --filter=VersionChainFileInfoTest
```
Expected: PASS.

---

### Task 7 — Run full relevant test suite + TypeScript check

```bash
docker compose exec laravel-app php artisan test --filter=DocumentFileTest
docker compose exec laravel-app php artisan test --filter=VersionChainFileInfoTest
```
Both suites: all green.

```bash
cd apps/app-laravel && npx vue-tsc --noEmit 2>&1 | head -30
```
Expected: no new errors.

---

### Task 8 — Commit

```bash
git add -A
git commit -m "feat(law): version-relations page with per-version download and correct filenames"
```

---

## Tests / Validation

| What | Command | Expected |
|------|---------|----------|
| PHP version chain adds file fields | `docker compose exec laravel-app php artisan test --filter=VersionChainFileInfoTest` | PASS, 5 assertions |
| Existing file download tests unbroken | `docker compose exec laravel-app php artisan test --filter=DocumentFileTest` | 6/6 PASS |
| TypeScript types valid | `cd apps/app-laravel && npx vue-tsc --noEmit` | 0 errors |
| Route visible | `grep law-versions apps/app-laravel/resources/js/router/index.ts` | 1 match |
| Panel button | Manually: `/law/{id}` → click "ดูเวอร์ชันและความสัมพันธ์" → lands on `/law/{id}/versions` |

---

## Risks & Tradeoffs

| Risk | Mitigation |
|------|-----------|
| `getVersionChain()` calls `getStatus()` N times (one per version) — N = typical chain length 2–5, so acceptable | If chains grow to 50+, batch-load statuses with a `getStatusBatch()` method. Not needed now (YAGNI). |
| `VersionHistoryTimeline.vue` is removed from `LawInfoPanel` — any other callers? | `grep -r VersionHistoryTimeline apps/app-laravel/resources/js` — only imported by `LawInfoPanel.vue`. Safe to remove from there. Component file itself is kept for backward compatibility (admin may use it later). |
| Download `download` attribute hint is advisory — browser may ignore it for same-origin responses | `DocumentFileController` already sets `Content-Disposition: attachment; filename=...` server-side, so the correct filename is enforced regardless. The `download` attribute is a nice-to-have. |
| Relations shown on the new page are from the **viewed document** only, not all versions | This matches the user's intent: "เวอร์ชันและความสัมพันธ์ของเอกสารนี้". If cross-version relations are needed later, aggregate from all version IDs' `relations[]`. |

## Open Questions

1. Should the new page also link to `AdminShowRelationsPage` (admin relation tree) for admins who are logged in? → Assumption: No, keep this page fully public and minimal. Admin link can be added in a follow-up.
2. The `download` attribute on `<v-btn :href="...">` — Vuetify renders this as `<a>` so `download` attribute should pass through. Verify by inspecting DOM on first manual test.
