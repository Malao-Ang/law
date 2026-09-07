# Plan: Public visibility + search snippet highlight + relations graph download

## Goal
Fix three public/admin issues: (1) private-scope cards must require login before showing, (2) search-result cards must show highlighted content snippets from inside the document, and (3) relations download must be available on the admin side and let the user pick any file+version across the entire graph (not just levels 1–2).

## Current context / assumptions
- Frontend: Vue 3 + Vuetify + Pinia in `apps/app-laravel/resources/js/`.
- Backend search: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php`.
- **Login is mock-only** — `stores/authStore.ts` exposes `isAuthenticated` (reads `localStorage['elaw_mock_auth']`). There is no real login backend yet. Task 1 uses this mock flag; when a real login exists it will drop in behind the same `auth.isAuthenticated`.
- `utils/lawAccess.ts` already has `canDisplayLawResult(law, isAuthenticated)` but **it is not applied** to the card list in `LawDatabasePage.vue` (`sortedResults` returns all rows; only `openLaw()` redirects to `/login`).
- Snippet machinery **already exists** end-to-end but is gated off: `makeFileBasedSnippets()` runs only when `config('search.file_heavy_details')` is true, and that config defaults to `false` (`config/search.php:10`, env `LAW_SEARCH_FILE_HEAVY_DETAILS`). The card template in `LawDatabasePage.vue:399-406` already renders `law.snippets` with `v-html` + `sanitizeHighlight`.
- Relations download dialog **already exists** in `pages/public/PublicShowRelationsPage.vue` (`downloadSelected`, `allDownloadItems`, `downloadSelectionOpen`). It flattens `rootNode` via `flattenTree`. The graph is built by `buildRelationTree` (`composables/useShowRelations.ts`) with `MAX_DEPTH = 6`. Descendant relations are fetched by `collectDescendantIds` (parent→child edges only). Admin page `pages/admin/AdminShowRelationsPage.vue` has **no download UI at all**.

## Architecture / proposed approach
Task 1: filter the rendered card list through the existing `canDisplayLawResult` helper so private+permission-restricted rows disappear entirely until `auth.isAuthenticated`. Task 2: enable the already-built snippet path (flip the config default to `true`) and verify snippets flow into the card. Task 3: extract the public relations download dialog into a shared component and mount it on the admin relations page, and confirm the graph/download already spans all depths (fix `collectDescendantIds` to also follow same-level version chains if a gap is found).

---

## Task 1 — Private cards require login

### 1a. Apply `canDisplayLawResult` to the card list
File: `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`

Find the `sortedResults` computed (starts line 645). It currently does:
```ts
const sortedResults = computed(() => {
  const items = [...searchStore.results];
  ...
```
Change the first line of the body to filter out hidden rows first:
```ts
const sortedResults = computed(() => {
  const items = searchStore.results.filter((law) => canDisplayLawResult(law, auth.isAuthenticated));
  ...
```
Leave the rest of the `switch (sortBy.value)` block unchanged.

Add the import near the other util imports (there is already an import block around line 445–454; add this line adjacent to the `lawAccess` sibling utils):
```ts
import { canDisplayLawResult } from '../../utils/lawAccess';
```
Verify no duplicate import exists first (`grep -n "canDisplayLawResult" LawDatabasePage.vue` should show 0 before you add it).

### 1b. Verify the "results count" label reflects the filtered list
File: same. Search for where the total/`ผลลัพธ์` count is rendered (grep `sortedResults.length` and any `searchStore.total`). If a count is shown from `searchStore.total` (server total) while the list is client-filtered, change that specific count binding to `sortedResults.length` so the number matches the visible cards. If only `sortedResults.length` is already used, no change.

Command:
```bash
grep -n "searchStore.total\|sortedResults.length\|totalResults" apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue
```
Expected: identify each count binding; adjust only those that display the visible-card count.

### 1c. Verify
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: exits 0.

Manual check: with `localStorage` cleared (logged out), a document whose `law_meta.access_scope = 'private'` **and** which has permission groups (`requires_permission = true`) must NOT appear as a card. After `authStore.login()` (mock login button), it appears. Public docs always appear.

> Note: rows that are `restricted` but have no permission groups still show (per existing `canDisplayLawResult` rule: `if (!law.requires_permission) return true`). This is intentional — do not change that rule in this task. If the requirement is "any private hides when logged out", that is an open question (see Risks).

Commit: `fix(search): hide permission-restricted cards until authenticated`

---

## Task 2 — Show highlighted content snippets on search cards

### 2a. Enable the file-based heavy details (snippets) by default
File: `apps/app-laravel/config/search.php`

Change line 10 from:
```php
    'file_heavy_details' => (bool) env('LAW_SEARCH_FILE_HEAVY_DETAILS', false),
```
to:
```php
    'file_heavy_details' => (bool) env('LAW_SEARCH_FILE_HEAVY_DETAILS', true),
```
This turns on `makeFileBasedSnippets()` (first-line excerpt with `<mark>` highlight) and `relatedLawSummaries()` for content queries. The excerpt window is already ±60/+100 chars around the first needle match and caps at 2 snippets (`LawSearchController.php:731-737`) — matches "แสดงแค่ content ที่เจอบรรทัดแรก".

### 2b. Confirm the card renders snippets (no code change expected)
File: `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue:399-406` already renders:
```html
<div class="law-list-card__snippets" ...>
  <div v-for="(snippet, index) in law.snippets.slice(0, 2)" ... v-html="sanitizeHighlight(snippet)" />
</div>
```
No change needed. Just confirm `LawSearchResult.snippets` type exists (grep in `types/lawSearch.ts`); if `snippets` is typed as optional/absent, ensure `law.snippets.length` guards don't crash (the template already uses `law.snippets.length === 0`, so the field must be a non-optional array — verify).

Command:
```bash
grep -n "snippets" apps/app-laravel/resources/js/types/lawSearch.ts
```
Expected: `snippets: string[];` present. If missing, add it to the `LawSearchResult` interface.

### 2c. Verify (backend behaviour)
The extraction chunks are read via `loadExportChunks(documentId)`. Snippets only appear for documents that have export chunks on disk. Verify with a real query against a document known to contain the term:
```bash
docker compose exec laravel-app php artisan tinker --execute="
  \$c = app(App\Http\Controllers\Api\LawSearchController::class);
  \$req = App\Http\Requests\LawSearchRequest::create('/api/laws/search','POST',['q'=>'ประกาศ','page'=>1,'per_page'=>5]);
  \$req->setContainer(app())->validateResolved();
  \$res = \$c->search(\$req, app(App\Services\Search\LawSearchService::class), app(App\Services\Search\LawSuggestService::class), app(App\Services\ReviewStore::class));
  \$data = \$res->getData(true);
  echo 'results: '.count(\$data['results']).PHP_EOL;
  foreach(\$data['results'] as \$r){ echo \$r['title'].' | snippets='.count(\$r['snippets']).PHP_EOL; }
"
```
Expected: at least one result row prints `snippets=1` or `snippets=2` and the snippet string contains `<mark>ประกาศ</mark>`. If all rows show `snippets=0`, the matched documents have no export chunks — check `loadExportChunks` path (open question).

Frontend typecheck:
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: exits 0.

Commit: `feat(search): enable content snippet highlighting on result cards`

---

## Task 3 — Relations download: admin side + full-graph version selection

### 3a. Extract the download dialog into a shared component
Create `apps/app-laravel/resources/js/components/shared/RelationDownloadDialog.vue`.

Copy the dialog block currently inlined in `pages/public/PublicShowRelationsPage.vue` (the `<v-dialog v-model="downloadSelectionOpen">` … `</v-dialog>` around lines 380–420, including the checkbox list of `allDownloadItems` and the `selectAllDownload` master checkbox at lines 405–418). Props/emits:
```ts
const props = defineProps<{
  modelValue: boolean;
  items: Array<{ id: string; title: string; version?: string }>;
  selectedIds: string[];
  loading: boolean;
}>();
const emit = defineEmits<{
  'update:modelValue': [boolean];
  'update:selectedIds': [string[]];
  confirm: [];
}>();
```
Move `selectAllDownload` logic into the component (derive from `props.items` + `props.selectedIds`, emit `update:selectedIds`). Render each item's `version` label next to the title when present (e.g. `<span class="text-caption">ฉบับ {{ item.version }}</span>`), satisfying "เลือก version 1 2 3 ตาม ui". File name stays title-based (never id) — the parent already passes titles.

### 3b. Wire the shared dialog back into the public page
File: `apps/app-laravel/resources/js/pages/public/PublicShowRelationsPage.vue`
- Replace the inlined `<v-dialog>` with `<RelationDownloadDialog v-model="downloadSelectionOpen" :items="allDownloadItems" v-model:selected-ids="selectedDownloadIds" :loading="downloadAllLoading" @confirm="downloadSelected" />`.
- Import it. Remove the now-unused inline `selectAllDownload` computed only if nothing else references it (grep first).
- Keep `allDownloadItems`, `downloadSelected`, `downloadRowPdf` as-is.

### 3c. Add version labels to `allDownloadItems`
File: same public page. `allDownloadItems` (line 641) currently emits `{ id, title }`. Extend to include a version when the row is part of a same-level version chain. Each tree node already carries `sameLevelVersions: ShowRelRow[]` (`useShowRelations.ts:586`). Map a node's position in its chain to a 1-based version number:
```ts
for (const node of flattenTree(rootNode.value)) {
  if (!seen.has(node.row.id)) {
    seen.add(node.row.id);
    const chain = node.sameLevelVersions;
    const idx = chain.findIndex((r) => r.id === node.row.id);
    const version = chain.length > 1 && idx >= 0 ? String(idx + 1) : undefined;
    result.push({ id: node.row.id, title: node.row.title, version });
  }
}
```
Apply the same shape to the `selectedRow` entry (version `undefined`).

### 3d. Mount download on the admin relations page
File: `apps/app-laravel/resources/js/pages/admin/AdminShowRelationsPage.vue`

This page mirrors the public one (same `useShowRelations` composable, same `rootNode`/`flattenTree`). Add:
- A "ดาวน์โหลดทั้งหมด" button in the tree card header/actions (near the `viewMode` toggle around line 286) that sets `selectedDownloadIds = allDownloadItems.map(i => i.id)` and opens the dialog.
- The same `allDownloadItems`, `selectedDownloadIds`, `downloadSelectionOpen`, `downloadAllLoading`, `downloadSelected`, `downloadRowPdf`, `safePdfName` state/functions as the public page (copy them; they depend only on `rootNode`, `rows`, and the api-client download helpers already importable here).
- `<RelationDownloadDialog ... />` at the end of the template, same bindings as 3b.

Verify admin page already imports `flattenTree`, `buildRelationTree` (grep); if not, add from `../../composables/useShowRelations` and the download helpers from `../../api/client` (`documentFileDownloadUrl`, `downloadPdfExport`).

### 3e. Ensure the graph spans all depths (the "only levels 1–2" bug)
Investigate before editing:
```bash
grep -n "MAX_DEPTH\|collectDescendantIds" apps/app-laravel/resources/js/composables/useShowRelations.ts
```
`MAX_DEPTH = 6` (line 7) already allows 6 levels, so depth is likely not the limiter. The real limiter is data: `loadDetail()` only fetches relations for `collectDescendantIds(id)` which follows **parent→child (`parentIds`) edges only** (`useShowRelations.ts:409-431`). Grandchildren reachable only through non-parent relation edges (e.g. `issued_under`/`amends` stored in `relations`, not `parentIds`) never get their relations fetched, so their children never load — the tree stops at level 2.

Fix: after building the initial descendant id set in `PublicShowRelationsPage.vue::loadDetail` (and the admin equivalent), also fetch relations for any target ids referenced inside already-fetched relation bags, iterating until no new ids appear (bounded by `MAX_DEPTH` hops). Minimal implementation — add a helper in `useShowRelations.ts`:
```ts
export function collectRelatedIds(rootId: string, bag: Record<string, LawRelation[]>): string[] {
  const out = new Set<string>();
  for (const rels of Object.values(bag)) {
    for (const rel of rels) {
      const t = rel.target_document_id?.trim();
      if (t && t !== rootId) out.add(t);
    }
  }
  return [...out];
}
```
Then in `loadDetail`, after the first `Promise.all` populates `bag`, loop up to `MAX_DEPTH` times: compute `collectRelatedIds` ∪ `collectDescendantIds`, fetch any id not already in `bag`, merge, repeat until no new ids. Keep a `fetched` Set to avoid refetching. This guarantees every node the tree can render also has its relations loaded, so `allDownloadItems` covers the full graph.

### 3f. Verify
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: exits 0.

Manual check on a document with a 3+ level relation chain:
1. Open `/law/relations/<rootId>` → tree shows level 3+ nodes.
2. Click "ดาวน์โหลดทั้งหมด" → dialog lists every node in the graph (count matches `stats` "กฎหมายลำดับรองทั้งหมด").
3. Version chains show `ฉบับ 1 / 2 / 3` labels; unchecking one excludes it.
4. Repeat on `/admin` relations page — download button now present and downloads the selected PDFs (filenames = titles, not ids).

Commits (one per sub-area):
- `refactor(relations): extract RelationDownloadDialog shared component`
- `feat(relations): add version labels to download items`
- `feat(admin/relations): add graph download dialog`
- `fix(relations): fetch full-depth relations so graph download covers all levels`

---

## Tests / validation
- No unit-test harness exists for these Vue components; validation is `npm run typecheck` (exit 0) plus the manual/tinker checks above. For task 2's backend, the tinker snippet is the deterministic check (asserts `snippets` count and `<mark>` presence).
- Commit after each task once its verify step passes.

## Risks, tradeoffs, open questions
1. **Task 1 semantics** — `canDisplayLawResult` hides only rows that are `restricted` AND `requires_permission`. If the intended rule is "every `access_scope=private` doc hidden when logged out" regardless of permission groups, change `lawAccess.ts` to `return !law.restricted || isAuthenticated;`. **Confirm which rule you want.**
2. **Task 2 cost** — enabling `file_heavy_details` makes each content query read export chunks from disk for every result page (up to `per_page` docs). For large corpora this adds latency. Mitigation already in place: only runs for non-empty, non-negated queries and caps 2 snippets. If latency is a problem, gate it to the first N results only (follow-up).
3. **Task 2 data dependency** — snippets need export chunks on disk (`loadExportChunks`). Old/never-exported docs will show no snippet even when the title matches (falls back to title highlight). Acceptable per requirement ("ถ้าเจอ...แสดงแค่ content ที่เจอ").
4. **Task 3e depth loop** — the iterative fetch could issue many `fetchReview` calls on a wide graph. Bound it by `MAX_DEPTH` iterations and a `fetched` Set (already specified). If graphs are huge, consider a dedicated backend endpoint returning the whole subtree in one call (follow-up, not this plan).
5. **Login is mock** — Task 1 rides on `authStore` mock. When a real auth backend lands, the same `auth.isAuthenticated` binding should work unchanged, but server-side enforcement (don't return private docs to unauthenticated API callers) is a separate hardening task not covered here.
