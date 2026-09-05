# Plan: Fix database facet counts and show referenced law names

## Goal
Fix `/database` filter counts so they reflect real visible/searchable documents, and replace the “เอกสารที่อ้างถึง อื่น ๆ 1” chip with actual referenced law title chips.

## Current context / assumptions
- Current bug from real API check:
  - `GET /api/laws/facets` returns real current file-store counts, e.g. `change_status: กฎหมายใหม่ = 1`.
  - `POST /api/laws/search` returns `total = 2` but stale Elasticsearch facet counts, e.g. `กฎหมายใหม่ = 741`, because `LawSearchController.php` returns `$esResult['facets']` after filtering/merging results.
- The UI at `/database` currently renders referenced docs from `childChips(law)` using `law.child_types`, so unknown types become `อื่น ๆ 1`. User wants actual law names, not type buckets.
- Existing relations carry `target_title` in review JSON. Expose a lightweight `related_laws` array on search results and render title chips.

## Files
- `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php`
- `apps/app-laravel/app/Services/Search/LawSearchService.php`
- `apps/app-laravel/resources/js/types/lawSearch.ts`
- `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`

## Task Q4 — Fix stale `/database` filter counts

### Q4.1 Recompute facets from final visible results in mixed/elastic branch
File: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php`

In `search()` when ES returns results, currently returns:
```php
'facets' => $esResult['facets'] ?? $fileBased['facets'],
```
This is wrong because ES may contain stale indexed docs not in current file store.

Change to compute facets from the final `$results` or the file-backed rows after filtering. Preferred minimal safe fix:
```php
'facets' => $this->computeResultFacets($results),
```

Add private helper near `computeFileBasedFacets()`:
```php
/**
 * @param array<int, array<string,mixed>> $results
 * @return array<string,mixed>
 */
private function computeResultFacets(array $results): array
{
    $rows = array_map(static fn (array $r): array => [
        'law_type' => (string) ($r['law_type'] ?? ''),
        'meta_status' => (string) ($r['status'] ?? ''),
        'change_status' => (string) ($r['change_status'] ?? ''),
        'signer_group' => (string) ($r['signer_group'] ?? ''),
        'agencies' => array_values(array_filter([(string) ($r['agency'] ?? '')])),
        'law_groups' => array_values(array_filter([(string) ($r['law_group'] ?? '')])),
        'promulgation_date' => (string) ($r['published_date'] ?? ''),
    ], $results);

    return $this->computeFileBasedFacets($rows);
}
```

This makes facet counts match the actual returned result set for mixed/elastic branch, preventing 741-style stale numbers.

### Q4.2 Verify API count
Run:
```bash
curl -s -X POST http://localhost:8500/api/laws/search \
  -H 'Content-Type: application/json' \
  -d '{"q":"","filters":{},"page":1,"per_page":10}'
```
Expected:
- `total` equals currently visible public docs.
- `facets.change_status[*].count` must not show stale 741/276/etc unless there are truly that many current docs.

## Task Q5 — Show referenced law names, not “อื่น ๆ 1”

### Q5.1 Add `related_laws` to backend file-based search result
File: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php`

In `fileBasedSearch()`, before mapping `$paged`, build an index from `$allMeta` by id:
```php
$metaById = array_column($allMeta, null, 'document_id');
```

For each result `$r`, read its review relations and build up to 3 referenced law title rows:
```php
'related_laws' => $this->relatedLawSummaries((string) ($r['document_id'] ?? ''), $store, $metaById),
```

Add helper:
```php
/**
 * @param array<string,array<string,mixed>> $metaById
 * @return list<array{document_id:string,title:string,type:string}>
 */
private function relatedLawSummaries(string $documentId, ReviewStore $store, array $metaById): array
{
    $review = $store->getReviewDocument($documentId);
    $relations = is_array($review['relations'] ?? null) ? $review['relations'] : [];
    $items = [];
    foreach ($relations as $rel) {
        if (! is_array($rel) || (($rel['scope'] ?? 'document') !== 'document')) {
            continue;
        }
        $targetId = trim((string) ($rel['target_document_id'] ?? ''));
        $title = trim((string) ($rel['target_title'] ?? ''));
        if ($title === '' && $targetId !== '' && isset($metaById[$targetId])) {
            $title = trim((string) ($metaById[$targetId]['title'] ?? ''));
        }
        if ($title === '') {
            continue;
        }
        $items[$targetId !== '' ? $targetId : $title] = [
            'document_id' => $targetId,
            'title' => $title,
            'type' => (string) ($rel['type'] ?? 'related'),
        ];
    }
    return array_slice(array_values($items), 0, 3);
}
```

If `getReviewDocument()` does not exist or is not public, use the existing read method available in `ReviewStore`; inspect before implementing.

### Q5.2 Preserve `related_laws` when overlaying ES results
File: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php`

In `overlayCurrentAccessState()`, copy `related_laws` from `$fileRow` to `$row`:
```php
$row['related_laws'] = $fileRow['related_laws'] ?? [];
```

### Q5.3 Add type field
File: `apps/app-laravel/resources/js/types/lawSearch.ts`

Add:
```ts
export interface RelatedLawSummary {
  document_id: string;
  title: string;
  type: string;
}
```

In `LawSearchResult` add:
```ts
related_laws?: RelatedLawSummary[];
```

### Q5.4 Render actual law title chips on `/database`
File: `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`

Replace current referenced-doc block:
```vue
<div class="law-list-card__children" :class="{ 'law-list-card__children--empty': childChips(law).length === 0 }">
  <span v-if="childChips(law).length" class="law-list-card__children-label">
    <v-icon size="12" icon="mdi-link-variant" />
    เอกสารที่อ้างถึง
  </span>
  <span v-for="chip in childChips(law)" ...>
    {{ chip.label }} {{ chip.count }}
  </span>
</div>
```

With:
```vue
<div class="law-list-card__children" :class="{ 'law-list-card__children--empty': referencedLawChips(law).length === 0 }">
  <span v-if="referencedLawChips(law).length" class="law-list-card__children-label">
    <v-icon size="12" icon="mdi-link-variant" />
    เอกสารที่อ้างถึง
  </span>
  <v-tooltip
    v-for="chip in referencedLawChips(law)"
    :key="chip.document_id || chip.title"
    :text="chip.title"
    location="top"
  >
    <template #activator="{ props: tooltipProps }">
      <span v-bind="tooltipProps" class="law-child-chip law-child-chip--reference">
        {{ chip.title }}
      </span>
    </template>
  </v-tooltip>
</div>
```

Add function:
```ts
function referencedLawChips(law: LawSearchResult): Array<{ document_id: string; title: string; type: string }> {
  return (law.related_laws ?? []).filter((item) => item.title?.trim()).slice(0, 3);
}
```

Stop using `childChips(law)` for this area. Do not render “อื่น ๆ”.

Add CSS for truncation:
```css
.law-child-chip--reference {
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
```

## Verification
Run:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
```
Expected: exit 0.

Run API check:
```bash
cd D:/workspace/outside/docling-thai-poc
curl -s -X POST http://localhost:8500/api/laws/search -H 'Content-Type: application/json' -d '{"q":"","filters":{},"page":1,"per_page":10}'
```
Expected:
- facet counts reflect returned/current docs, not stale 741.
- each result may include `related_laws` with `title`; frontend displays title chips.

## Commit
```bash
git add apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php apps/app-laravel/resources/js/types/lawSearch.ts apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue
git commit -m "fix(database): count current facets and show referenced law names"
```
