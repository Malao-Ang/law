# Plan: Unify "published" filter across graph (admin+user) and homepage search

## Goal
Make the relations graph (both admin and public) hide any non-published document entirely, and make the homepage search behave identically to the database page — both driven by one shared "is published" predicate so the rule can never drift between surfaces.

## Current context / assumptions
- **Two data sources today, with different visibility rules:**
  - Database page (`LawDatabasePage.vue`) → `searchLaws()` → `LawSearchController::fileBasedSearch()`. Already filters to published: `status === 'ingested'` AND `published_date !== ''` AND `meta_status !== 'ร่าง'` (`LawSearchController.php:189-197`).
  - Relations pages (`PublicShowRelationsPage.vue`, `AdminShowRelationsPage.vue`) → `fetchReportSummary()` → `ReportController::documents()`. Returns **all** docs with only `workflow_completed_step >= 4` filtering (in `mapShowRelRows`, `useShowRelations.ts:138`). No published filter → drafts/unpublished/status-changed docs appear as graph nodes.
- **Homepage** (`PublicHomePage.vue`): the "latest / by-type" card sections already call `searchLaws()` + `canDisplayLawResult` (correct). But its search BOX (`ELawHeroSearch` `@search="onSearch"`) only routes to `/database` with query params — it does not itself render results. **Need to confirm what "search หน้า main" means** (see Open Questions) — likely the requirement is that the hero search's autocomplete/suggest and any inline result preview use the same published filter as `/database`.
- User decisions (from clarify):
  1. Graph hides a node when it is NOT currently published — same criteria as search: `status === 'ingested'` AND has `published_date` AND `meta_status !== 'ร่าง'`. Covers both "never published" and "unpublished after being published".
  2. Hidden nodes disappear completely: not in tree, not counted in stats, not selectable for download.
- `USER.md`: never push without permission; no AI/co-author markers in code or commits.

## Architecture / proposed approach
Introduce ONE shared predicate `isPublishedLaw(...)` as the single source of truth for "should the public see this document". Apply it in `mapShowRelRows` (so every relations consumer — tree, stats, download — only ever sees published rows) and reuse the existing search filter logic by extracting it to the same predicate shape on the backend. For the homepage, route the shared predicate through whatever the hero search renders so it matches `/database`.

---

## Task 1 — Add published fields to the report document payload

The relations graph is built from `ReportController::documents()`. `mapShowRelRows` already receives `status` implicitly? No — check: `ShowRelRow` has `metaStatus` and `workflowStage` but NOT the pipeline `status` or `published_date`. We need both to apply the published rule.

### 1a. Confirm current ReportDocument fields
File: `apps/app-laravel/app/Http/Controllers/Api/ReportController.php::documents()` (lines 160-181) already emits `status`, `meta_status`, `published_date`. Good — the data is already in the payload.

### 1b. Confirm the TS type carries them
File: `apps/app-laravel/resources/js/types/document.ts` — find `ReportDocument` interface. Verify it declares `status: string`, `meta_status: string`, `published_date: string`. 
```bash
grep -n "interface ReportDocument" -A 30 apps/app-laravel/resources/js/types/document.ts
```
Expected: `status`, `meta_status`, `published_date` all present. If any is missing, add it (string). No commit yet — this is a read/verify step folded into Task 2's commit.

---

## Task 2 — Shared published predicate + apply to relations graph

### 2a. Add `isPublishedLaw` helper
File: `apps/app-laravel/resources/js/composables/useShowRelations.ts`

Add near the other exported predicates (after `isCancelledStatus`, ~line 124):
```ts
/**
 * Single source of truth for "the public may see this document".
 * Mirrors LawSearchController::fileBasedSearch published gate:
 * pipeline status ingested + has a published date + not a draft.
 */
export function isPublishedLaw(doc: {
  status?: string | null;
  published_date?: string | null;
  meta_status?: string | null;
}): boolean {
  const status = (doc.status ?? '').trim();
  const publishedDate = (doc.published_date ?? '').trim();
  const metaStatus = (doc.meta_status ?? '').trim();
  return status === 'ingested' && publishedDate !== '' && metaStatus !== 'ร่าง';
}
```

### 2b. Filter unpublished rows out of the graph at the source
File: same, `mapShowRelRows` (line 137-169). Change the first filter line from:
```ts
  const completed = documents.filter((doc) => (doc.workflow_completed_step ?? 0) >= 4);
```
to:
```ts
  const completed = documents.filter(
    (doc) => (doc.workflow_completed_step ?? 0) >= 4 && isPublishedLaw(doc),
  );
```
Because `mapShowRelRows` is the single entry that builds `rows` for BOTH `PublicShowRelationsPage.vue` and `AdminShowRelationsPage.vue`, and `rootNode`/`flattenTree`/`stats`/`allDownloadItems` all derive from `rows`, this one change makes unpublished nodes vanish from tree, stats, and download everywhere at once (satisfies decision #2). No per-page edits needed.

### 2c. Guard the selected root itself
If a user opens `/law/relations/<id>` for a now-unpublished root, `rows` will no longer contain it and `rootNode` returns null (tree shows the existing empty state "ไม่พบกฎหมายลำดับรอง..."). Verify this path does not throw:
- `PublicShowRelationsPage.vue` `rootNode` computed already returns null when `!selectedId` or `buildRelationTree` returns null. Confirm `loadDetail` tolerates a root missing from `rows` (it fetches review by id independently — fine). No code change expected; just verify no `.value!` non-null assertion crashes.

### 2d. Verify
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: exit 0.

Manual: open `/law/relations` and `/admin` relations for a parent that has a draft/unpublished child — the child no longer appears in tree, the "กฎหมายลำดับรองทั้งหมด" stat count drops accordingly, and the download dialog list omits it.

Commit: `fix(relations): hide non-published documents from graph, stats, and download`

---

## Task 3 — Homepage search parity with database page

> **RESOLVED (code-confirmed): Case A1.** `ELawHeroSearch.vue` uses `searchStore.suggest()` for the autocomplete dropdown and `emit('search', ...)` → `PublicHomePage.onSearch` routes to `/database`. No inline results view on the homepage. Task 3 = make the SUGGEST endpoint apply the same published filter as search.

### Case A1 — Hero search only routes to /database (CONFIRMED)
File: `apps/app-laravel/resources/js/pages/public/PublicHomePage.vue::onSearch` (line 292) already does `router.push({ path: '/database', query: {...} })`. Since `/database` now applies published filter + `canDisplayLawResult` (done in previous batch), parity is already achieved for the routed results. 

The only gap: the hero search **autocomplete/suggestions** dropdown. Check `ELawHeroSearch.vue` — it imports `LawSuggestion` and calls a suggest endpoint. Verify the suggest endpoint (`LawSuggestController` / `/api/laws/suggest`) also excludes unpublished docs.
```bash
grep -n "published_date\|meta_status\|ingested\|status" apps/app-laravel/app/Http/Controllers/Api/LawSuggestController.php apps/app-laravel/app/Services/Search/LawSuggestService.php
```
If suggestions are NOT filtered to published, apply the same published gate there (mirror `LawSearchController::fileBasedSearch` lines 189-197 in the file-based suggest path). Add a backend helper if needed so search + suggest share the filter.

### Case A2 — Homepage renders inline search results
If the hero search shows result cards inline on `/` (not just routing), apply `canDisplayLawResult(law, auth.isAuthenticated)` to that inline list exactly like `LawDatabasePage.vue::sortedResults`, and reuse the same card component if one exists.

### 3b. Verify
```bash
cd apps/app-laravel && npm run typecheck   # exit 0
```
Manual: type a term on the homepage hero that matches only a draft/unpublished doc → no suggestion appears and, after Enter, `/database` shows "ไม่พบเอกสาร" (never a private/draft leak). A published match behaves identically on both `/` and `/database`.

Commit: `fix(search): apply published filter to homepage search/suggest for parity with database`

---

## Tests / validation
- No component test harness; validation is `npm run typecheck` (exit 0) + the manual checks above.
- Backend published-filter parity can be spot-checked with tinker against `/api/laws/suggest` if Case A1 needs a suggest fix (mirror the Task 2 tinker style from the prior plan).
- Commit after each task once its verify passes.

## Risks, tradeoffs, open questions
- **Open Question A (blocks Task 3 scope):** Does "search หน้า main" mean (a) the hero search box that routes to `/database` — in which case Task 3 is just the suggest-endpoint filter — or (b) an inline results view on the homepage itself? Confirm before implementing Task 3. Task 2 (graph) is unblocked and can proceed regardless.
- **`isPublishedLaw` duplication FE/BE:** the predicate now lives in TS (`useShowRelations.ts`) and PHP (`LawSearchController`). They must stay in sync. Acceptable (different runtimes); the plan documents both so future edits touch both. A shared config constant is overkill (YAGNI).
- **Root-open of an unpublished doc:** a deep link to `/law/relations/<unpublishedId>` will show the empty tree state rather than a "not found". If the requirement is a hard 404/redirect for unpublished roots, that is a follow-up (not requested here).
- **`workflow_completed_step >= 4` retained:** kept alongside `isPublishedLaw` because published implies completed, but the belt-and-suspenders guard is harmless. If any published doc legitimately has step < 4, remove the step guard — but that would be a data anomaly worth investigating first.
