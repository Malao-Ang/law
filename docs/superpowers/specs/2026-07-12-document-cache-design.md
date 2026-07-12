# Document Load Caching — Design

**Date:** 2026-07-12
**Scope:** Speed up document loading across all wizard pages by caching the fetched review document on the client, de-duplicating concurrent fetches, and invalidating on mutation.

## Problem

The app is a wizard over a single document (upload → review → rag → law-info → relations → permissions → result). Every page calls `store.fetch(id)` on mount, which always issues a fresh network request:

- `documentStore.fetch` / `composeStore.fetch` → `GET /documents/{id}/review`
- `previewStore.fetch` → `GET /documents/{id}/preview`

Consequences:
- Navigating between steps of the *same* document re-fetches the full review JSON every time.
- The RAG page fetches the same document **twice** — `composeStore.fetch()` and `documentStore.fetch()` run in parallel, both hitting `/review` (2 requests + 2 server-side HTML rebuilds).
- No client cache and no HTTP caching, so every step and every post-mutation reload is a full round-trip; the server rebuilds HTML from blocks on each `getReviewDocument`.

## Goal

Navigating between wizard steps over the same document is a cache hit (no network). Concurrent fetches for the same id share one request. Data never goes stale because every mutation invalidates the cached entry.

## Non-goals (explicitly excluded)

- Server-side HTML caching (Laravel cache of rebuilt HTML).
- HTTP ETag / `304 Not Modified`.
- Cross-user / cross-tab cache coherence (single-user POC; a full browser reload clears the cache).
- Persisting the cache to localStorage/IndexedDB.

These can be added later if the *first* fetch itself proves slow; this design targets the repeated-fetch problem.

## Architecture

### A. Cache module — `resources/js/stores/reviewCache.ts`

Module-level (singleton) maps, framework-agnostic:

```ts
const reviewCache = new Map<string, ReviewDocument>();
const reviewInFlight = new Map<string, Promise<ReviewDocument>>();
const previewCache = new Map<string, PreviewData>();
const previewInFlight = new Map<string, Promise<PreviewData>>();

export async function getReviewCached(id: string, force = false): Promise<ReviewDocument> {
  if (!force && reviewCache.has(id)) return reviewCache.get(id)!;
  if (reviewInFlight.has(id)) return reviewInFlight.get(id)!;
  const p = fetchReview(id).then((doc) => { reviewCache.set(id, doc); reviewInFlight.delete(id); return doc; })
                           .catch((e) => { reviewInFlight.delete(id); throw e; });
  reviewInFlight.set(id, p);
  return p;
}

export function setReview(id: string, doc: ReviewDocument): void { reviewCache.set(id, doc); }
export function invalidateReview(id: string): void { reviewCache.delete(id); previewCache.delete(id); }
// getPreviewCached(id, force) mirrors getReviewCached using fetchPreview + previewCache/previewInFlight.
```

Invalidating a review also drops its preview (preview is derived from the same blocks).

### B. Stores delegate to the cache

- `documentStore.fetch(id)` → `review.value = await getReviewCached(id)`. Add an optional `force` param.
- `composeStore.fetch(id)` → same. Because both use `getReviewCached`, the RAG page's parallel double-fetch collapses to a single network request (the second caller awaits the same in-flight promise; if already cached, no request at all).
- `previewStore.fetch(id, force?)` → `getPreviewCached(id, force)`. The "โหลดใหม่" button passes `force: true`.

### C. Invalidation on mutation (freshness)

- `documentStore.saveReview / saveLawMeta / saveRelations`: on success, patch the cached doc from the server response (`setReview` with the updated fields already applied to `review.value`, which is the cached object) — or `invalidateReview(id)` if the response is partial. Net effect: cache reflects the save.
- `blockStore` ops (`patch`, `patchLayout`, `merge`, `remove`, `split`, `create`, `reorderBlocks`, `patchChunkType`, `restore`, `reprocess`, `reprocessPage`): call `invalidateReview(documentId)` after success. The existing post-mutation reload (e.g. `RagManageWorkspace.reloadBlocks`) then repopulates from the server — and because both its calls go through the cache, that reload is a single request.

### D. Lifetime

Module-level cache lives for the SPA session. Cleared per-entry on mutation/`force`; entirely gone on a full browser reload. No expiry timer (single-user session; invalidation is mutation-driven).

## Files touched

- Create: `resources/js/stores/reviewCache.ts`
- Modify: `resources/js/stores/documentStore.ts` — `fetch` via cache (+ `force`); save methods write cache.
- Modify: `resources/js/stores/composeStore.ts` — `fetch` via cache.
- Modify: `resources/js/stores/previewStore.ts` — `fetch` via preview cache (+ `force`).
- Modify: `resources/js/stores/blockStore.ts` — invalidate after each mutation.
- Modify: `resources/js/pages/preview/PreviewPage.vue` — reload button passes `force: true`.

## Testing

No frontend test runner is configured (no vitest). Verification:

- `npm run typecheck` — no type errors.
- Manual (network tab):
  1. Navigate review → rag → law-info → back to review: after the first load, no new `/review` request fires.
  2. Open the RAG page: exactly **one** `/review` request (was two).
  3. Edit a block / save law-meta, then reload the list: the view shows the updated data (cache invalidated and refetched once).
  4. Preview page "โหลดใหม่" issues a fresh `/preview` request.
