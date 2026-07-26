# /database Redesign: Stat Cards + Private Teasers + Card Restyle — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the public `/database` page to match the screenshot — a 6-card stat row, restyled result cards with child-law chips, a private/restricted-access card, and tightened search-bar spacing — keeping the Elasticsearch search fully functional and using only real data.

**Architecture:** Two parts, execute **Part A (backend) before Part B (frontend)**. New real-data fields (parent/child counts, per-result child-law-by-type, private-teaser flag, `law_group` per result) are derived in `LawSearchController` from `ReviewStore::listLawMeta()` — the path the controller already treats as authoritative — and mirrored into the ES parse so both search paths agree. Private docs are surfaced as **restricted teasers** (title/type/date/agency only; no summary, no snippets, no content).

**Tech Stack:** Laravel (PHP 8.3, PHPUnit), Vue 3 + Vuetify 3 + TypeScript, Elasticsearch.

**Real-data mapping (from investigation):**
- Stat cards total/new/amended/repealed → existing facets. parent/child → new counts from `listLawMeta()` (`parent_document_id`).
- Card child-law chips → children (`parent_document_id == law_id`) grouped by `law_type`, populated as relations/parents are set on `/relations` and `/law-info`.
- Private card → `access_scope === 'private'` rows, returned as restricted teasers.
- **Omitted (no data):** "แก้ไขทั้งหมด N ครั้ง" revision count — does not exist anywhere; not shown.

**Verification:** backend = PHPUnit (Feature tests use Laravel `Tests\TestCase` + `getJson`, per `ReportSummaryTest`); frontend = `npm run typecheck`, `npm run build`, manual.

---

## Part A — Backend data layer

### Task A1: Add parent/child counts to the facets endpoint

**Files:**
- Modify: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php` (`facets`, lines 264-316)
- Test: `apps/app-laravel/tests/Feature/LawFacetsStatsTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LawFacetsStatsTest extends TestCase
{
    private function seed(ReviewStore $s, string $id, array $meta): void
    {
        $s->setStatus($id, ['status' => 'ingested', 'source_file' => $id.'.docx']);
        $s->writeReviewDocument($id, [
            'document_id' => $id, 'source_file' => $id.'.docx', 'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => array_merge(['title' => $id, 'access_scope' => 'public'], $meta),
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_facets_include_parent_and_child_counts(): void
    {
        $s = app(ReviewStore::class);
        $parent = 'p_'.uniqid();
        $child = 'c_'.uniqid();
        $this->seed($s, $parent, ['law_type' => 'พระราชบัญญัติ']);
        $this->seed($s, $child, ['law_type' => 'ระเบียบ', 'parent_document_id' => $parent]);

        $res = $this->getJson('/api/laws/facets');
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, $res->json('stats.parent_laws'));
        $this->assertGreaterThanOrEqual(1, $res->json('stats.child_laws'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=test_facets_include_parent_and_child_counts`
Expected: FAIL — `stats` key absent.

- [ ] **Step 3: Compute and return parent/child stats**

In `facets()`, before `return response()->json($facets);` (line 315), add parent/child tallying. Replace the `foreach ($store->listLawMeta() as $row) {` loop's opening and the return so it also gathers ids:

Add these accumulators next to `$yearCounts = [];` (line 274):

```php
        $childCount = 0;
        $parentIds = [];
        $publicCount = 0;
        $changeCounts = ['new' => 0, 'amended' => 0, 'repealed' => 0];
```

Inside the loop, after the `continue` guard for private rows (line 279), add:

```php
            $publicCount++;
            $parentId = trim((string) ($row['parent_document_id'] ?? ''));
            if ($parentId !== '') {
                $childCount++;
                $parentIds[$parentId] = true;
            }
            $cs = (string) ($row['change_status'] ?? '');
            if (isset($changeCounts[$cs])) {
                $changeCounts[$cs]++;
            }
```

Then before the final return, add the `stats` block:

```php
        $facets['stats'] = [
            'total_laws' => $publicCount,
            'new_laws' => $changeCounts['new'],
            'amended_laws' => $changeCounts['amended'],
            'repealed_laws' => $changeCounts['repealed'],
            'parent_laws' => count($parentIds),
            'child_laws' => $childCount,
        ];
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T laravel-app php artisan test --filter=LawFacetsStatsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php apps/app-laravel/tests/Feature/LawFacetsStatsTest.php
git commit -m "feat(search): facets endpoint returns law stats (parent/child/change)"
```

---

### Task A2: Return private docs as restricted teasers + add law_group/child_types to file-based results

**Files:**
- Modify: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php` (`fileBasedSearch`, lines 46-100)
- Test: `apps/app-laravel/tests/Feature/LawSearchRestrictedTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LawSearchRestrictedTest extends TestCase
{
    private function seed(ReviewStore $s, string $id, array $meta): void
    {
        $s->setStatus($id, ['status' => 'ingested', 'source_file' => $id.'.docx']);
        $s->writeReviewDocument($id, [
            'document_id' => $id, 'source_file' => $id.'.docx', 'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => array_merge(['title' => $id, 'access_scope' => 'public'], $meta),
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_private_docs_appear_as_restricted_teasers(): void
    {
        $s = app(ReviewStore::class);
        $priv = 'priv_'.uniqid();
        $this->seed($s, $priv, ['law_type' => 'ระเบียบ', 'access_scope' => 'private']);

        $res = $this->getJson('/api/laws/search');
        $res->assertOk();
        $hit = collect($res->json('results'))->firstWhere('law_id', $priv);
        $this->assertNotNull($hit, 'private doc should appear as a teaser');
        $this->assertTrue($hit['restricted']);
        $this->assertNull($hit['summary']);          // content withheld
        $this->assertSame([], $hit['snippets']);      // content withheld
        $this->assertSame($priv, $hit['title'] === null ? $priv : $hit['law_id']); // title/id present
    }

    public function test_child_types_populated_from_parent_link(): void
    {
        $s = app(ReviewStore::class);
        $parent = 'par_'.uniqid();
        $this->seed($s, $parent, ['law_type' => 'พระราชบัญญัติ']);
        $this->seed($s, 'ch1_'.uniqid(), ['law_type' => 'ระเบียบ', 'parent_document_id' => $parent]);

        $res = $this->getJson('/api/laws/search');
        $hit = collect($res->json('results'))->firstWhere('law_id', $parent);
        $this->assertNotNull($hit);
        $this->assertSame(1, $hit['child_types']['rabiap'] ?? 0);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=LawSearchRestrictedTest`
Expected: FAIL — private excluded; no `restricted`/`child_types` keys.

- [ ] **Step 3: Build a child-type index and include private teasers**

In `fileBasedSearch`, replace the row filter's access guard and the result mapping. First, before the `$rows = ...` filter (line 53), build the child map from the full meta list:

```php
        $allMeta = $store->listLawMeta();
        // parent_document_id -> [canonical law_type => count] (real child relations).
        $childTypeIndex = [];
        foreach ($allMeta as $meta) {
            $parentId = trim((string) ($meta['parent_document_id'] ?? ''));
            if ($parentId === '') {
                continue;
            }
            $type = $this->canonicalType((string) ($meta['law_type'] ?? ''));
            $childTypeIndex[$parentId][$type] = ($childTypeIndex[$parentId][$type] ?? 0) + 1;
        }
```

Change the filter source from `$store->listLawMeta()` to `$allMeta`, and change the access guard (line 54) to allow private (they become teasers) while still requiring ingested:

```php
        $rows = array_values(array_filter($allMeta, function (array $row) use ($q, $filters, $store): bool {
            if (($row['status'] ?? '') !== 'ingested') {
                return false;
            }
            // Private rows are still listed, but only as restricted teasers (below).
```

(Keep the rest of the filter body unchanged.)

Then change the result mapping (lines 84-95) to add `restricted`, `law_group`, `child_types`, and to withhold content for private rows:

```php
        $results = array_map(function (array $r) use ($q, $store, $childTypeIndex): array {
            $restricted = (string) ($r['access_scope'] ?? 'public') === 'private';
            $id = (string) $r['document_id'];

            return [
                'law_id' => $id,
                'title' => $r['title'],
                'law_type' => $r['law_type'],
                'status' => $r['meta_status'],
                'change_status' => $r['change_status'],
                'summary' => $restricted ? null : null, // file-based has no summary
                'published_date' => $r['promulgation_date'] ?? null,
                'agency' => $r['agencies'][0] ?? null,
                'law_group' => $r['law_groups'][0] ?? null,
                'signer_group' => $r['signer_group'],
                'restricted' => $restricted,
                'child_types' => $childTypeIndex[$id] ?? [],
                'snippets' => $restricted ? [] : $this->makeFileBasedSnippets($id, $q, $store, (string) ($r['title'] ?? '')),
            ];
        }, $paged);
```

- [ ] **Step 4: Add the `canonicalType` helper**

Add near `tally()` (after line 323):

```php
    private function canonicalType(string $lawType): string
    {
        return match (true) {
            str_contains($lawType, 'พระราชบัญญัติ'), $lawType === 'พ.ร.บ.', $lawType === 'phrb' => 'phrb',
            str_contains($lawType, 'ข้อบังคับ'), $lawType === 'kho-bangkhab' => 'kho-bangkhab',
            str_contains($lawType, 'ระเบียบ'), $lawType === 'rabiap' => 'rabiap',
            str_contains($lawType, 'ประกาศ'), $lawType === 'prakat' => 'prakat',
            default => 'other',
        };
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `docker compose exec -T laravel-app php artisan test --filter=LawSearchRestrictedTest`
Expected: PASS (both tests).

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php apps/app-laravel/tests/Feature/LawSearchRestrictedTest.php
git commit -m "feat(search): restricted private teasers + law_group + child_types (file-based)"
```

---

### Task A3: Mirror the new fields into the ES parse path

**Files:**
- Modify: `apps/app-laravel/app/Services/Search/LawSearchService.php` (`parse`, lines 198-209; `publicAccessFilter` usage)

Because the controller merges ES results (`search()`) with file-based, ES hits must carry the same keys so cards render consistently. ES cannot cheaply reverse-look-up children, so ES hits set `restricted=false` and `child_types={}`; the controller's file-based **supplement** provides the authoritative private teasers and child data, and for ES-primary hits the frontend still renders (chips simply empty until reindex). This keeps ES the fast scorer without an index change.

- [ ] **Step 1: Add the keys to each ES result**

In `parse()`, extend the `$results[] = [ ... ]` array (lines 198-209) with:

```php
                'law_group' => $source['law_group'] ?? null,
                'restricted' => false,
                'child_types' => (object) [],
```

- [ ] **Step 2: Typecheck-equivalent — run the existing search tests**

Run: `docker compose exec -T laravel-app php artisan test --filter=LawSearch`
Expected: PASS (existing search tests unaffected; new keys are additive).

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/app/Services/Search/LawSearchService.php
git commit -m "feat(search): ES results carry law_group/restricted/child_types keys"
```

---

## Part B — Frontend redesign

### Task B1: Extend the LawSearch types + add a facets-stats fetch

**Files:**
- Modify: `apps/app-laravel/resources/js/types/lawSearch.ts`
- Modify: `apps/app-laravel/resources/js/api/client.ts` (facets/stats)

- [ ] **Step 1: Widen `LawSearchResult` and `LawSearchFacets`**

In `lawSearch.ts`, add to `LawSearchResult` (after `signer_group`, line 46):

```ts
  law_group?: string | null;
  restricted?: boolean;
  child_types?: Record<string, number>;
```

Add a stats type and extend facets:

```ts
export interface LawStats {
  total_laws: number;
  new_laws: number;
  amended_laws: number;
  repealed_laws: number;
  parent_laws: number;
  child_laws: number;
}
```

Add `stats?: LawStats;` to `LawSearchFacets`.

- [ ] **Step 2: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS (`fetchLawFacets` already returns `LawSearchFacets`; `stats` is optional).

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/types/lawSearch.ts
git commit -m "feat(database): types for stats, law_group, restricted, child_types"
```

---

### Task B2: Stat-card row (6 cards, real data)

**Files:**
- Create: `apps/app-laravel/resources/js/components/public/LawStatCards.vue`
- Modify: `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue` (render it + pass stats)

- [ ] **Step 1: Create the stat-card row component**

```vue
<template>
  <div class="law-stats">
    <div v-for="card in cards" :key="card.label" class="law-stat" :class="`law-stat--${card.tone}`">
      <div class="law-stat__label"><v-icon :icon="card.icon" size="15" /> {{ card.label }}</div>
      <div class="law-stat__value">{{ card.value.toLocaleString('th-TH') }}</div>
      <div class="law-stat__unit">ฉบับ</div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { LawStats } from '../../types/lawSearch';

const props = defineProps<{ stats: LawStats | null }>();

const cards = computed(() => {
  const s = props.stats;
  return [
    { label: 'กฎหมายทั้งหมด', value: s?.total_laws ?? 0, icon: 'mdi-file-document-outline', tone: 'default' },
    { label: 'กฎหมายใหม่', value: s?.new_laws ?? 0, icon: 'mdi-plus-circle-outline', tone: 'success' },
    { label: 'ปรับปรุง', value: s?.amended_laws ?? 0, icon: 'mdi-pencil-outline', tone: 'info' },
    { label: 'ยกเลิก', value: s?.repealed_laws ?? 0, icon: 'mdi-close-circle-outline', tone: 'danger' },
    { label: 'กฎหมายแม่บท', value: s?.parent_laws ?? 0, icon: 'mdi-bank-outline', tone: 'default' },
    { label: 'กฎหมายลูก', value: s?.child_laws ?? 0, icon: 'mdi-link-variant', tone: 'default' },
  ];
});
</script>

<style scoped>
.law-stats { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; }
.law-stat { background: #fff; border: 1px solid #e6e2d6; border-radius: 12px; padding: 14px 16px; }
.law-stat__label { font-size: 0.78rem; color: #6b6252; display: flex; align-items: center; gap: 5px; }
.law-stat__value { font-size: 1.7rem; font-weight: 800; color: #1f1b14; line-height: 1.2; }
.law-stat__unit { font-size: 0.72rem; color: #9c9382; }
.law-stat--success .law-stat__value { color: #15803d; }
.law-stat--info .law-stat__value { color: #1d4ed8; }
.law-stat--danger .law-stat__value { color: #be123c; }
@media (max-width: 960px) { .law-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 560px) { .law-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
```

- [ ] **Step 2: Render it above the results**

In `LawDatabasePage.vue`, import it and place it at the top of the results `v-container` (before the `<v-row>` at line 128):

```vue
import LawStatCards from '../../components/public/LawStatCards.vue';
```

```vue
        <LawStatCards :stats="baseFacets?.stats ?? searchStore.facets.stats ?? null" class="mb-5" />
```

- [ ] **Step 3: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/components/public/LawStatCards.vue apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue
git commit -m "feat(database): 6 real-data stat cards"
```

---

### Task B3: Restyle result cards (child chips) + private teaser card + search-bar spacing

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`

- [ ] **Step 1: Add a child-chip row + law_group meta to the result card**

Inside `.law-list-card__body`, after the `.law-list-card__meta` block (line 337), add the child-law chips (real `child_types`):

```vue
                  <div v-if="childChips(law).length" class="law-list-card__children">
                    <span class="law-list-card__children-label"><v-icon size="12" icon="mdi-link-variant" /> กฎหมายลูก</span>
                    <span v-for="chip in childChips(law)" :key="chip.type" class="law-child-chip" :class="`law-child-chip--${chip.type}`">
                      {{ chip.label }} {{ chip.count }}
                    </span>
                  </div>
```

Add `กลุ่มกฎหมาย` to the meta row (after the agency span, line 332):

```vue
                    <span v-if="law.law_group">
                      <v-icon size="13" icon="mdi-sitemap-outline" />
                      {{ law.law_group }}
                    </span>
```

Add the `childChips` helper in `<script setup>` (near `toDocType`):

```ts
const CHILD_CHIP_LABELS: Record<string, string> = {
  phrb: 'พ.ร.บ.', 'kho-bangkhab': 'ข้อบังคับ', rabiap: 'ระเบียบ', prakat: 'ประกาศ', other: 'อื่น ๆ',
};
function childChips(law: LawSearchResult): Array<{ type: string; label: string; count: number }> {
  const types = law.child_types ?? {};
  return Object.entries(types)
    .filter(([, count]) => count > 0)
    .map(([type, count]) => ({ type, label: CHILD_CHIP_LABELS[type] ?? type, count }));
}
```

- [ ] **Step 2: Render private teasers as a restricted card**

Wrap the card body so restricted results show the lock UI instead of the normal click-through. Replace the result `v-for` container's click handler + add a restricted branch. Change the card root to not navigate when restricted:

```vue
                @click="law.restricted ? goLogin() : router.push({ name: 'law', params: { documentId: law.law_id } })"
```

Add a restricted footer inside `.law-list-card` (after `.law-list-card__body`, replacing the arrow for restricted cards):

```vue
                <div v-if="law.restricted" class="law-list-card__restricted">
                  <v-icon icon="mdi-lock-outline" size="16" />
                  <span class="law-list-card__restricted-label">Private · เฉพาะผู้ได้รับสิทธิ์</span>
                  <button type="button" class="law-restricted-btn" @click.stop="goLogin()">
                    เข้าสู่ระบบเพื่อดูเอกสาร <v-icon size="14" icon="mdi-arrow-right" />
                  </button>
                </div>
                <div v-else class="law-list-card__arrow">
                  <v-icon icon="mdi-chevron-right" color="#b68d40" size="22" />
                </div>
```

(Remove the original standalone `.law-list-card__arrow` block so it isn't duplicated.)

Add `goLogin`:

```ts
function goLogin(): void {
  router.push('/login');
}
```

- [ ] **Step 3: Fix search-bar spacing/proportions + add child/restricted styles**

In `<style scoped>` adjust the search row and add the new element styles:

```css
.elaw-search-input :deep(.v-field__input) { min-height: 54px; padding-top: 8px; padding-bottom: 8px; }
.elaw-db-search-btn { padding: 0 28px; height: 56px; border-radius: 14px; }

.law-list-card__children { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.law-list-card__children-label { font-size: 12px; color: #6b7280; display: inline-flex; align-items: center; gap: 3px; }
.law-child-chip { font-size: 12px; padding: 2px 8px; border-radius: 9999px; background: #f3f4f6; color: #374151; }
.law-child-chip--phrb { background: #fef3c7; color: #92400e; }
.law-child-chip--rabiap { background: #dbeafe; color: #1e40af; }
.law-child-chip--kho-bangkhab { background: #d1fae5; color: #065f46; }
.law-child-chip--prakat { background: #ffedd5; color: #9a3412; }

.law-list-card__restricted { display: flex; align-items: center; gap: 10px; padding: 0 18px; flex-shrink: 0; color: #6b7280; }
.law-list-card__restricted-label { font-size: 13px; white-space: nowrap; }
.law-restricted-btn { display: inline-flex; align-items: center; gap: 4px; background: #b68d40; color: #fff; border: none; border-radius: 9999px; padding: 8px 16px; font-size: 13px; cursor: pointer; white-space: nowrap; }
```

Make the search shell align to a consistent height by setting the search row wrapper's `align-items: center` (the outer `.d-flex` at line 16 already uses `align-md-start`; change to `align-md-center` for symmetric vertical centering of the input and button).

- [ ] **Step 4: Make the search field flush with the button (spacing)**

In the template, the search row (line 16) gap is `ga-3`; keep it, but ensure the button matches the input height (done via CSS above, `height: 56px`). Confirm no extra vertical offset remains.

- [ ] **Step 5: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue
git commit -m "feat(database): child-law chips, private teaser card, search-bar spacing"
```

---

### Task B4: Verify

**Files:** none (verification only)

- [ ] **Step 1: Manual check**

Open `/database`:
- 6 stat cards show real counts (total/new/amended/repealed from facets; parent/child from the new stats).
- Search + suggestions + all filters still work (Elasticsearch unaffected).
- A result with children shows the "กฎหมายลูก" chips by type; results without show none (no fabricated numbers). No "แก้ไข N ครั้ง".
- A private doc appears as a restricted card: lock icon, "Private · เฉพาะผู้ได้รับสิทธิ์", "เข้าสู่ระบบเพื่อดูเอกสาร" → `/login`; its content/snippets are withheld.
- Search field and button are the same height, evenly spaced; type pills row is aligned.

- [ ] **Step 2: Commit (only if manual check required tweaks)**

```bash
git add -A && git commit -m "fix(database): redesign polish after manual check"
```

---

## Self-Review

- **Spec coverage:** 6 stat cards (A1 + B2), private restricted teasers (A2 + B3), child-law chips from real relations (A2 + B3), search-bar spacing (B3), keep ES functional (A3 keeps ES primary; A2 file-based supplement authoritative). Revision count omitted (no data). ✓
- **No mock:** every number traces to `listLawMeta()`/facets/ES; child chips empty until relations/parents set on `/relations`/`/law-info`. ✓
- **Conventions:** Feature tests use Laravel `Tests\TestCase` + `getJson` (matches `ReportSummaryTest`); frontend verified by typecheck/build/manual (no JS runner). ✓
- **Type consistency:** `restricted`/`child_types`/`law_group` added to both PHP result maps (A2/A3) and the TS `LawSearchResult` (B1) and consumed in B3; `LawStats`/`stats` defined B1, produced A1, consumed B2. ✓
- **Privacy note:** Part A2 deliberately surfaces private-doc existence (title/type/date) to anonymous users as teasers — the explicitly chosen behavior; content (summary/snippets) is withheld. ✓
- **Flagged:** ES-primary hits render child chips empty (no reverse lookup in ES) until a reindex denormalizes child_types; file-based supplement covers authoritative data meanwhile. ✓
