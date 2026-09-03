# Fix: Title highlight missing in search results after submit

## Goal

Ensure the search results page shows highlighted title text (matching query terms wrapped in `<mark>`) after the user submits a search, not just during typing in the autocomplete dropdown.

## Current Context

### How title highlighting works today

1. **During typing** (autocomplete dropdown) — `ELawHeroSearch.vue` line 58-66: client-side `highlightTitle()` wraps query matches in `<mark>` tags using regex on the raw title string. This works correctly.

2. **After search submit** (results list) — `LawDatabasePage.vue` line 365:
   ```html
   v-html="law.title_highlighted ? sanitizeHighlight(law.title_highlighted) : (law.title || 'ไม่ระบุชื่อกฎหมาย')"
   ```
   The frontend checks `title_highlighted` from the API response. If absent/null, it falls back to plain `title` with no highlighting.

3. **Backend — file-based path** — `LawSearchController.php` line 170: Returns `title_highlighted` via `highlightTitle()` method that wraps query matches in `<mark>` tags. **This path works correctly.**

4. **Backend — ES path** — `LawSearchService::parse()` lines 374-391: Returns `title` but **NEVER** returns `title_highlighted`. ES IS configured to highlight the `title` field (line 304 of `buildBodyFromMust`), but the parse method only puts title highlight fragments into `snippets`, never into a dedicated `title_highlighted` field.

### The bug

When Elasticsearch is active and returns results (the production/primary path), `title_highlighted` is missing from the response. The frontend falls back to plain `title` → no highlight after search.

### Files involved

| File | Role | Bug? |
|------|------|------|
| `app/Services/Search/LawSearchService.php` | ES query builder + parser | YES — `parse()` doesn't extract title highlight |
| `app/Http/Controllers/Api/LawSearchController.php` | Controller; overlays ES + file-based | Partial — ES overlay doesn't add `title_highlighted` |
| `resources/js/pages/public/LawDatabasePage.vue` | Search results rendering | OK — correctly uses `title_highlighted` |
| `resources/js/components/shared/ELawHeroSearch.vue` | Autocomplete dropdown | OK — client-side highlighting |
| `resources/js/types/lawSearch.ts` | Type definition | OK — `title_highlighted` already in interface |

## Step-by-step Tasks

### Task 1: Add `title_highlighted` to ES parse output

**File**: `apps/app-laravel/app/Services/Search/LawSearchService.php`

In the `parse()` method (around line 346), extract title highlight from ES response and include it in the result. ES returns title highlights in `hit['highlight']['title']` (an array of fragments with `<mark>` tags).

After line 370 (`$restricted = ...`), add:

```php
// Extract title highlight from ES response
$titleHighlighted = null;
$titleHighlightFragments = $hit['highlight']['title'] ?? [];
if ($titleHighlightFragments !== []) {
    $titleHighlighted = $titleHighlightFragments[0];
}
// Also check inner_hits for title highlight
if ($titleHighlighted === null) {
    foreach ($hit['inner_hits']['snippets']['hits']['hits'] ?? [] as $innerHit) {
        $innerTitleFragments = $innerHit['highlight']['title'] ?? [];
        if ($innerTitleFragments !== []) {
            $titleHighlighted = $innerTitleFragments[0];
            break;
        }
    }
}
```

Then add to the result array (after line 376 `'title' => ...`):

```php
'title_highlighted' => $titleHighlighted,
```

**Verify**: `docker compose exec laravel-app php artisan test --filter=LawSearchTest`

---

### Task 2: Add `title_highlighted` in the ES overlay path in the controller

**File**: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php`

In `overlayCurrentAccessState()` (line 78-96), the method overlays file-based data onto ES results. When ES doesn't have `title_highlighted` but the file-based row does, carry it over.

Add after line 88 (`$row['issuer'] = ...`):

```php
if (! isset($row['title_highlighted']) && isset($fileRow['title_highlighted'])) {
    $row['title_highlighted'] = $fileRow['title_highlighted'];
}
```

Also, when there is NO file row match (line 81-83), the ES result should still have `title_highlighted` from the ES path (Task 1).

---

### Task 3: Write test for title highlight in file-based search

**File**: `apps/app-laravel/tests/Feature/LawSearchFuzzyTest.php`

Add test:

```php
public function test_search_results_include_title_highlighted(): void
{
    $s = app(ReviewStore::class);
    $id = 'hl_result_'.uniqid();
    $this->seedLaw($s, $id, 'ระเบียบว่าด้วยการเบิกจ่ายค่าเดินทาง');

    $res = $this->postJson('/api/laws/search', ['q' => 'เบิกจ่าย']);
    $res->assertOk();

    $hit = collect($res->json('results'))->firstWhere('law_id', $id);
    $this->assertNotNull($hit);
    $this->assertNotNull($hit['title_highlighted'], 'search results must include title_highlighted');
    $this->assertStringContainsString('<mark>', $hit['title_highlighted']);
    $this->assertStringContainsString('เบิกจ่าย', $hit['title_highlighted']);
}
```

**Verify**: `docker compose exec laravel-app php artisan test --filter=LawSearchFuzzyTest`

---

### Task 4: Write test for ES path title highlight (unit test with mocked ES)

**File**: `apps/app-laravel/tests/Unit/LawSearchServiceTest.php`

Add test that verifies `parse()` extracts `title_highlighted` from ES hit highlight:

```php
public function test_parse_extracts_title_highlighted_from_es_highlight(): void
{
    // ... (use reflection or make parse() testable)
    // Verify that when ES returns highlight.title fragments,
    // the parsed result includes title_highlighted with <mark> tags.
}
```

Alternatively, add to `LawSearchTest::test_search_endpoint_returns_results_and_facets` — ensure the mocked ES response includes `title_highlighted` and the endpoint passes it through.

---

### Task 5: Run full test suite

**Run**: `docker compose exec laravel-app php artisan test --filter=LawSearch`
**Expected**: All LawSearch* tests pass.

---

## Risks, Tradeoffs, and Open Questions

### Risks
1. **ES title highlight may not always be present**: If the query matches on `text` or `keywords` but not `title`, ES won't return a title highlight. In that case `title_highlighted` will be null and the frontend correctly falls back to plain `title`. This is expected behavior.

### Open Questions
1. **Should we also highlight title client-side as a fallback?** Currently, if the API returns `title_highlighted: null` (no title match), the frontend shows plain title. We could add a client-side fallback similar to the suggest dropdown. However, this would be inconsistent with the backend-driven highlight approach and could show inaccurate highlights (matching partial words that ES's Thai analyzer wouldn't match). Recommend: keep backend-only highlighting for accuracy.
