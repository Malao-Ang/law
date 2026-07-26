# Fuzzy Near-Word Search + Highlight (main page + database) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When exact search finds nothing, fall back to near-word (typo-tolerant) matching — e.g. "มหาวิทยาลับ" still finds "มหาวิทยาลัย" — and highlight the matched term, on both the main page hero search and the `/database` page.

**Architecture / key finding:** The controller's `fileBasedSearch()` is authoritative and runs first; Elasticsearch results are only used when ES returns >0. In this environment **ES is empty (0 indices)**, so every query uses the file-based path — which does plain `str_contains` with **no fuzzy**. The ES fuzzy code (`LawSearchService`/`LawSuggestService`) is dormant. The fix therefore adds near-word matching to the **file-based** path (works with or without ES), returns a server-side highlighted title + a `fuzzy` flag, and wires the main-page hero to a live suggest dropdown so both pages benefit.

**Tech Stack:** Laravel (PHP 8.3, PHPUnit), Vue 3 + Vuetify 3 + TypeScript, Elasticsearch.

**Near-word algorithm:** multibyte-aware Levenshtein (PHP's `levenshtein()` is byte-based and wrong for Thai). Split both strings into UTF-8 characters (`preg_split('//u')`) and compute edit distance on the char arrays. A title/keyword matches when its best distance to the query ≤ `max(1, ceil(mb_strlen(q) / 4))`.

**Verification:** backend = PHPUnit (Feature tests use `Tests\TestCase` + `getJson`; Unit for the pure helper). Frontend = `npm run typecheck`, `npm run build`, manual.

---

## Part A — Backend

### Task A1: Multibyte near-word helper (pure, unit-tested)

**Files:**
- Create: `apps/app-laravel/app/Support/ThaiFuzzy.php`
- Test: `apps/app-laravel/tests/Unit/ThaiFuzzyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Support\ThaiFuzzy;
use PHPUnit\Framework\TestCase;

class ThaiFuzzyTest extends TestCase
{
    public function test_mb_levenshtein_counts_character_edits(): void
    {
        $this->assertSame(0, ThaiFuzzy::distance('มหาวิทยาลัย', 'มหาวิทยาลัย'));
        $this->assertSame(1, ThaiFuzzy::distance('มหาวิทยาลับ', 'มหาวิทยาลัย')); // 1 char substituted
    }

    public function test_matches_within_typo_threshold(): void
    {
        $this->assertTrue(ThaiFuzzy::isNearMatch('มหาวิทยาลับ', 'มหาวิทยาลัย'));
        $this->assertFalse(ThaiFuzzy::isNearMatch('แมว', 'มหาวิทยาลัย'));
    }

    public function test_near_match_scans_substrings_of_longer_text(): void
    {
        // Query is a near-typo of a word embedded in a longer title.
        $this->assertTrue(ThaiFuzzy::isNearMatch('มหาวิทยาลับ', 'ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยการเงิน'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=ThaiFuzzyTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the helper**

```php
<?php

namespace App\Support;

final class ThaiFuzzy
{
    /** Character-level (UTF-8 aware) Levenshtein distance. */
    public static function distance(string $a, string $b): int
    {
        $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($x);
        $m = count($y);
        if ($n === 0) {
            return $m;
        }
        if ($m === 0) {
            return $n;
        }

        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $curr = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $curr[$j] = min($prev[$j] + 1, $curr[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $curr;
        }

        return $prev[$m];
    }

    /**
     * True when $query is a typo-distance match of $text, or of any
     * query-length window inside a longer $text (Thai has no word spaces).
     */
    public static function isNearMatch(string $query, string $text): bool
    {
        $q = trim($query);
        if (mb_strlen($q) < 3) {
            return false;
        }
        $threshold = max(1, (int) ceil(mb_strlen($q) / 4));

        if (self::distance($q, $text) <= $threshold) {
            return true;
        }

        // Slide a query-length (± threshold) window across the longer text.
        $qLen = mb_strlen($q);
        $tChars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tLen = count($tChars);
        for ($start = 0; $start + $qLen - $threshold <= $tLen; $start++) {
            $window = implode('', array_slice($tChars, $start, $qLen + $threshold));
            if ($window !== '' && self::distance($q, $window) <= $threshold) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T laravel-app php artisan test --filter=ThaiFuzzyTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/app/Support/ThaiFuzzy.php apps/app-laravel/tests/Unit/ThaiFuzzyTest.php
git commit -m "feat(search): multibyte-aware near-word (Thai typo) helper"
```

---

### Task A2: File-based near-word fallback + highlighted title + fuzzy flag

**Files:**
- Modify: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php` (`fileBasedSearch`, `search`)
- Test: `apps/app-laravel/tests/Feature/LawSearchFuzzyTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class LawSearchFuzzyTest extends TestCase
{
    private function seed(ReviewStore $s, string $id, string $title): void
    {
        $s->setStatus($id, ['status' => 'ingested', 'source_file' => $id.'.docx']);
        $s->writeReviewDocument($id, [
            'document_id' => $id, 'source_file' => $id.'.docx', 'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => ['title' => $title, 'access_scope' => 'public', 'law_type' => 'ระเบียบ'],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_typo_query_finds_near_word_and_flags_fuzzy(): void
    {
        $s = app(ReviewStore::class);
        $id = 'fz_'.uniqid();
        $this->seed($s, $id, 'ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยการเงิน');

        $res = $this->getJson('/api/laws/search?q='.rawurlencode('มหาวิทยาลับ'));
        $res->assertOk();
        $hit = collect($res->json('results'))->firstWhere('law_id', $id);
        $this->assertNotNull($hit, 'typo query should still find the near-word title');
        $this->assertTrue($res->json('fuzzy'), 'response should flag fuzzy results');
    }

    public function test_exact_query_highlights_the_matched_term_in_title(): void
    {
        $s = app(ReviewStore::class);
        $id = 'hl_'.uniqid();
        $this->seed($s, $id, 'ระเบียบว่าด้วยการเบิกจ่ายค่าเดินทาง');

        $res = $this->getJson('/api/laws/search?q='.rawurlencode('เบิกจ่าย'));
        $hit = collect($res->json('results'))->firstWhere('law_id', $id);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('<mark>เบิกจ่าย</mark>', (string) $hit['title_highlighted']);
        $this->assertFalse($res->json('fuzzy'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=LawSearchFuzzyTest`
Expected: FAIL — typo returns nothing; no `fuzzy`/`title_highlighted` keys.

- [ ] **Step 3: Add near-word fallback + highlight to `fileBasedSearch`**

In `LawSearchController::fileBasedSearch`, after the exact `$rows = array_filter(...)` block (line 79), add a fuzzy fallback when exact found nothing:

```php
        $usedFuzzy = false;
        if ($rows === [] && $q !== '' && mb_strlen($q) >= 4) {
            $rows = array_values(array_filter($store->listLawMeta(), function (array $row) use ($q, $filters): bool {
                if (($row['status'] ?? '') !== 'ingested' || ($row['access_scope'] ?? '') !== 'public') {
                    return false;
                }
                if (! \App\Support\ThaiFuzzy::isNearMatch($q, (string) ($row['title'] ?? ''))) {
                    return false;
                }
                foreach (['law_type', 'change_status', 'signer_group'] as $field) {
                    $want = $filters[$field] ?? null;
                    if (! empty($want) && ! in_array($row[$field] ?? '', (array) $want, true)) {
                        return false;
                    }
                }

                return true;
            }));
            $usedFuzzy = $rows !== [];
        }
```

Then change the `$results = array_map(...)` mapping (line 84) to add a highlighted title (exact substring wrapped in `<mark>`; fuzzy → plain), and thread `$usedFuzzy` out. Change the mapping's per-row array to include:

```php
            'title_highlighted' => $q !== '' ? $this->highlightTitle((string) ($r['title'] ?? ''), $q) : ($r['title'] ?? null),
```

and change the `return compact('total', 'results', 'facets');` line to:

```php
        return ['total' => $total, 'results' => $results, 'facets' => $facets, 'fuzzy' => $usedFuzzy];
```

- [ ] **Step 4: Add the `highlightTitle` helper**

Add near `tally()`:

```php
    /** Wrap the exact (case-insensitive) query substring in <mark>; plain otherwise. */
    private function highlightTitle(string $title, string $query): string
    {
        $q = trim($query);
        if ($q === '' || mb_stripos($title, $q) === false) {
            return $title;
        }

        return (string) preg_replace('/('.preg_quote($q, '/').')/iu', '<mark>$1</mark>', $title);
    }
```

- [ ] **Step 5: Surface `fuzzy` in the controller response**

In `search()`, the file-based return path (`return response()->json($fileBased);`, line 42) already carries `fuzzy` from Step 3. In the ES-merge branch (lines 32-36), add `'fuzzy' => false,` to the returned JSON (ES exact/merge is not fuzzy here) and pass through `title_highlighted` from `$fileBased`/ES results (ES results gain it in Task A3).

- [ ] **Step 6: Run to verify it passes**

Run: `docker compose exec -T laravel-app php artisan test --filter=LawSearchFuzzyTest`
Expected: PASS (both tests).

- [ ] **Step 7: Commit**

```bash
git add apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php apps/app-laravel/tests/Feature/LawSearchFuzzyTest.php
git commit -m "feat(search): file-based near-word fallback + highlighted title + fuzzy flag"
```

---

### Task A3: Add title_highlighted to the ES parse (parity)

**Files:**
- Modify: `apps/app-laravel/app/Services/Search/LawSearchService.php` (`parse`, lines 198-209 + highlight config)

- [ ] **Step 1: Return a highlighted title from ES**

In `parse()`, extract the title highlight fragment (ES already highlights `title` — line 137 in the highlight config). Add to each result:

```php
                'title_highlighted' => $hit['highlight']['title'][0] ?? ($source['title'] ?? null),
```

- [ ] **Step 2: Run the existing search tests**

Run: `docker compose exec -T laravel-app php artisan test --filter=LawSearch`
Expected: PASS (additive key).

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/app/Services/Search/LawSearchService.php
git commit -m "feat(search): ES results carry title_highlighted"
```

---

## Part B — Frontend

### Task B1: Types

**Files:**
- Modify: `apps/app-laravel/resources/js/types/lawSearch.ts`

- [ ] **Step 1: Add fields**

Add to `LawSearchResult` (after `title`): `title_highlighted?: string | null;`
Add to `LawSearchResponse`: `fuzzy?: boolean;`

- [ ] **Step 2: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/types/lawSearch.ts
git commit -m "feat(search): types for title_highlighted + fuzzy"
```

---

### Task B2: Database page — highlighted title + "near match" banner

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`
- Modify: `apps/app-laravel/resources/js/stores/lawSearchStore.ts` (expose `fuzzy`)

- [ ] **Step 1: Expose `fuzzy` from the store**

In `lawSearchStore.ts`, add a `fuzzy` ref (default `false`), set it from the search response (`this.fuzzy = res.fuzzy ?? false`), and return it from the store.

- [ ] **Step 2: Render the highlighted title**

In `LawDatabasePage.vue`, replace the card title (line 322):

```vue
                  <h3 class="law-list-card__title">{{ law.title || 'ไม่ระบุชื่อกฎหมาย' }}</h3>
```

with (reuse the existing `sanitizeHighlight`):

```vue
                  <h3
                    class="law-list-card__title"
                    v-html="law.title_highlighted ? sanitizeHighlight(law.title_highlighted) : (law.title || 'ไม่ระบุชื่อกฎหมาย')"
                  />
```

- [ ] **Step 3: Show a near-match banner when fuzzy**

Above the results list (before the `<div v-else class="d-flex flex-column ga-3">` at line 303), add:

```vue
            <v-alert
              v-if="searchStore.fuzzy && sortedResults.length"
              type="info"
              variant="tonal"
              density="compact"
              class="mb-3"
              icon="mdi-magnify-scan"
            >
              ไม่พบผลลัพธ์ที่ตรงกับ "{{ query }}" — แสดงผลใกล้เคียงที่พบแทน
            </v-alert>
```

- [ ] **Step 4: Highlight the suggest dropdown title too**

In the suggest dropdown item (line 52-54), if the suggestion carries a highlighted title use it; otherwise fall back. (Requires the suggest payload to include a highlight — deferred: for now keep plain title; the banner + result highlight cover the main ask. Flag.)

- [ ] **Step 5: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue apps/app-laravel/resources/js/stores/lawSearchStore.ts
git commit -m "feat(database): highlighted result title + near-match banner"
```

---

### Task B3: Main page hero — live suggest dropdown with fuzzy + highlight

**Files:**
- Modify: `apps/app-laravel/resources/js/components/shared/ELawHeroSearch.vue`

The hero currently only navigates on Enter. Add a live suggest dropdown (reusing `useLawSearchStore().suggest`, which already has the fuzzy fallback) so typos surface near-word suggestions on the landing page; clicking one navigates to `/database` with that query.

- [ ] **Step 1: Wire the suggest store into the hero**

In `<script setup>`, import and use the search store; debounce-call `suggest(query)` on input (≥2 chars); show a dropdown of `searchStore.suggestions` under the field; on click, set `query` and `emitSearch()`. Mirror the debounce/hide pattern from `LawDatabasePage.vue` (`queueSuggest`, `queueHideSuggestions`). Render each suggestion's title; if a keyword contains the query, wrap it in `<mark>` via a small local highlight of the plain title against `query` (client-side, `sanitizeHighlight` of a locally-marked string).

(Full component code: follow the `elaw-suggest-card` / `elaw-suggest-item` markup already in `LawDatabasePage.vue` lines 31-75 as the reference implementation, adapted to the hero's styling.)

- [ ] **Step 2: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/components/shared/ELawHeroSearch.vue
git commit -m "feat(home): hero live suggest dropdown with fuzzy + highlight"
```

---

### Task B4: Verify

**Files:** none (verification only)

- [ ] **Step 1: Manual check (database)**

On `/database`, search an exact term → matched term is highlighted in result titles. Search a typo (e.g. "มหาวิทยาลับ") → near-word results appear with the "แสดงผลใกล้เคียง" banner.

- [ ] **Step 2: Manual check (main page)**

On the landing page, type a typo in the hero → a suggest dropdown shows near-word laws; pressing Enter / clicking a suggestion lands on `/database` with results.

- [ ] **Step 3: Commit (only if manual check required tweaks)**

```bash
git add -A && git commit -m "fix(search): fuzzy/highlight polish after manual check"
```

---

## Self-Review

- **Root cause addressed:** typos failed because ES is empty → file-based path (no fuzzy) runs; Part A adds file-based near-word matching so it works without ES. ✓
- **Both pages:** database (B2) + main-page hero suggest (B3). ✓
- **Highlight:** server-side `title_highlighted` for exact matches (A2/A3), rendered on the database page (B2); near-match banner communicates fuzzy results; hero suggest highlights client-side (B3). ✓
- **Flagged/deferred:** suggest-payload highlight (B2 Step 4) kept plain for now; fuzzy highlighting marks the whole exact substring only (fuzzy hits show the banner rather than an in-title mark, since the matched span is approximate). ✓
- **Conventions:** Unit test for the pure helper (plain PHPUnit), Feature tests via `getJson` (Laravel `Tests\TestCase`); frontend typecheck/build/manual. ✓
