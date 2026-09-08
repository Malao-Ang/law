# Plan: Fix MongoBlobStore commit-retry exhaustion mislabeled as 404 on block save

## Goal
Stop concurrent block saves (and the RAG-skip flow's first save) from failing with a misleading `404 Not Found` by distinguishing optimistic-lock contention from genuine "not found", and by making `withLock` survive short write bursts with backoff + more retries.

## Current context / assumptions

### The two symptoms are ONE root cause
Error seen:
```
PATCH /api/documents/{id}/blocks/{blockId}
404 Not Found
{"message":"MongoBlobStore: failed to commit after 3 retries (review/{id})"}
```

1. **`MongoBlobStore::withLock`** (`app/Services/Storage/MongoBlobStore.php:60-105`) does optimistic concurrency: read `_version`, run callback, `updateOne` guarded by `['_id'=>$id,'_version'=>$version]`, `$inc _version`. If the matched count is 0 (someone else bumped `_version` first), it retries. After **3 attempts with NO delay/backoff**, it throws `RuntimeException("MongoBlobStore: failed to commit after 3 retries (...)")`.

2. **`ReviewController::update`** (`app/Http/Controllers/Api/ReviewController.php:144-163`) wraps `patchApprovedBlock` in `catch (RuntimeException) → 404`. That catch was designed for the *legit* not-found `RuntimeException`s thrown by `findBlockReference` (`ReviewStore.php:1556-1579`: `'Block not found.'`, `'Invalid review document format.'`). But the **commit-retry-exhausted** exception is the SAME class → it is wrongly returned as **404** when it is really a transient **409/500 concurrency** error.

### Why it fires on "first save" / RAG skip
The whole-document editor (`DocumentEditorShell` / review page) autosaves multiple blocks; on the first save after extraction the client PATCHes several blocks near-simultaneously, and `updateRagSkipped` / `setStatus` also writes the same Mongo `_id` (different sub-kind, but `withLock` reads the whole doc and guards on the shared `_version`). Three retries with zero backoff is easily exhausted by a burst of concurrent writes to the same `_id`. Result: a transient contention error surfaces as a permanent-looking 404, confusing the user ("not found the rag first time").

> Note: `status` and `review` are different sub-keys but share the same Mongo document `_id` and the same `_version` counter (`withLock` increments one global `_version` per `_id`). So a `setStatus` (status kind) and a `patchApprovedBlock` (review kind) on the same document DO contend on `_version`. This is the concurrency source.

### Assumptions
- Mongo is a single-document blob per `documentId`; the fix must not change the storage schema.
- Local dev may not run Mongo the same way as prod; tests should target the `MongoBlobStore` logic with a mocked/fake collection or the existing test harness.
- `USER.md`: no `git push` without permission; no AI/co-author markers.

## Architecture / proposed approach
Introduce a dedicated `ConcurrencyException` thrown by `withLock` when (and only when) retries are exhausted, so callers can tell contention apart from "not found". Add bounded exponential backoff + more attempts so normal bursts succeed. Update `ReviewController` (and any other `withLock` caller that maps `RuntimeException`→404) to return **409 Conflict** for `ConcurrencyException` while still returning 404 for genuine not-found.

---

## Task 1 — Dedicated exception for commit contention

### 1a. Create `ConcurrencyException`
File: `apps/app-laravel/app/Services/Storage/ConcurrencyException.php` (new)
```php
<?php

namespace App\Services\Storage;

use RuntimeException;

/**
 * Thrown when MongoBlobStore::withLock exhausts its optimistic-lock retries.
 * Distinct from "not found" RuntimeExceptions so callers can map it to 409,
 * not 404.
 */
final class ConcurrencyException extends RuntimeException {}
```

### 1b. Throw it from `withLock`
File: `apps/app-laravel/app/Services/Storage/MongoBlobStore.php`
- Replace the final throw (line 104):
```php
        throw new RuntimeException("MongoBlobStore: failed to commit after 3 retries ({$kind}/{$id})");
```
with:
```php
        throw new ConcurrencyException("MongoBlobStore: commit contention, retries exhausted ({$kind}/{$id})");
```
- Add the import at top (after `use RuntimeException;`):
```php
use App\Services\Storage\ConcurrencyException;
```
> `ConcurrencyException extends RuntimeException`, so any existing `catch (RuntimeException)` still catches it (backward-compatible) — but new catches can target the subclass first.

### 1c. Verify
```bash
docker compose exec laravel-app php -l app/Services/Storage/ConcurrencyException.php
docker compose exec laravel-app php -l app/Services/Storage/MongoBlobStore.php
```
Expected: `No syntax errors detected` for both.

Commit: `feat(storage): add ConcurrencyException for withLock retry exhaustion`

---

## Task 2 — Backoff + more retries in `withLock`

### 2a. Add bounded exponential backoff and raise attempts
File: `apps/app-laravel/app/Services/Storage/MongoBlobStore.php`
- Change the loop bound (line 62) from `$attempt < 3` to a named constant. Add near the top of the class:
```php
    private const MAX_ATTEMPTS = 6;
```
- Update the `for` (line 62):
```php
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
```
- Inside the loop, when a retry is needed (both the `insertOne` `BulkWriteException catch` at line 82-84 and the `updateOne` miss at the end of the loop where `getMatchedCount() === 0`), sleep with jittered backoff BEFORE the next iteration. Concretely, replace `continue;` (line 83) with:
```php
                    $this->backoff($attempt);
                    continue;
```
and after the `if ($result->getMatchedCount() > 0) { ... return; }` block (i.e. just before the loop closes at line 101-102), add:
```php
            $this->backoff($attempt);
```
- Add the helper method (below `withLock`):
```php
    /** Jittered exponential backoff: ~5ms, 10ms, 20ms, 40ms … capped, + random jitter. */
    private function backoff(int $attempt): void
    {
        $baseMicros = 5000 * (2 ** min($attempt, 5)); // 5ms → capped ~160ms
        $jitter = random_int(0, 4000); // up to 4ms
        usleep(min($baseMicros + $jitter, 200000)); // hard cap 200ms
    }
```

### 2b. Verify
```bash
docker compose exec laravel-app php -l app/Services/Storage/MongoBlobStore.php
```
Expected: `No syntax errors detected`.

Commit: `fix(storage): add jittered backoff and raise withLock retries to 6`

---

## Task 3 — Map contention to 409, keep 404 for genuine not-found

### 3a. Catch `ConcurrencyException` first in `ReviewController::update`
File: `apps/app-laravel/app/Http/Controllers/Api/ReviewController.php`
- Add import near the other `use` lines at top:
```php
use App\Services\Storage\ConcurrencyException;
```
- In `update` (lines 146-155), change the single catch into two, contention first:
```php
        try {
            $updatedBlock = $this->reviewStore->patchApprovedBlock(
                documentId: $documentId,
                pageNo: (int) $request->validated('page_no'),
                blockId: $blockId,
                patch: $request->validated(),
            );
        } catch (ConcurrencyException $exception) {
            return response()->json([
                'message' => 'The document is being saved by another change. Please retry.',
            ], 409);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
```
> Order matters: `ConcurrencyException` is a `RuntimeException`, so its catch MUST come first.

### 3b. Apply the same split to other block-mutating endpoints that map RuntimeException→404
File: `apps/app-laravel/app/Http/Controllers/Api/ReviewController.php`
- Search every `catch (RuntimeException $exception)` that returns 404 in this controller and add a preceding `catch (ConcurrencyException …) → 409`. Command to enumerate:
```bash
grep -n "catch (RuntimeException" apps/app-laravel/app/Http/Controllers/Api/ReviewController.php
```
Expected hits include `update` (done in 3a), `updateDocumentReview` (line ~178), and likely `updateLayout`, `mergeBlocks`, `deleteBlock`, `splitBlock`, `createBlock`, `reorderBlocks`, `reprocess`. For EACH, insert the same `catch (ConcurrencyException) → 409` immediately before the existing `catch (RuntimeException) → 404`. Keep messages identical to 3a for DRY (consider a small private helper `private function concurrencyResponse(): JsonResponse` returning the 409 to avoid repetition).

### 3c. DRY helper (optional but preferred)
File: same controller — add:
```php
    private function concurrencyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The document is being saved by another change. Please retry.',
        ], 409);
    }
```
and use `return $this->concurrencyResponse();` in every `catch (ConcurrencyException)`.

### 3d. Verify
```bash
docker compose exec laravel-app php -l app/Http/Controllers/Api/ReviewController.php
grep -c "ConcurrencyException" apps/app-laravel/app/Http/Controllers/Api/ReviewController.php
```
Expected: `No syntax errors detected`; the grep count equals (number of RuntimeException→404 catches) + 1 (the import).

Commit: `fix(review): return 409 (not 404) on save contention`

---

## Task 4 — Frontend: retry on 409 instead of surfacing an error

### 4a. Auto-retry block PATCH once on 409
File: `apps/app-laravel/resources/js/stores/blockStore.ts` (the `patch` action calls the PATCH endpoint)
- Find the `patch` (and `patchLayout`) request. Wrap the call so a `409` response triggers ONE delayed retry (e.g. 250ms) before rejecting. Pseudocode to implement concretely against the existing api client:
```ts
async function patchWithRetry(fn: () => Promise<Response>, retries = 1): Promise<Response> {
  const res = await fn();
  if (res.status === 409 && retries > 0) {
    await new Promise((r) => setTimeout(r, 250));
    return patchWithRetry(fn, retries - 1);
  }
  return res;
}
```
Apply it to the block patch/patchLayout requests. Read the actual store first:
```bash
grep -n "blocks/\|PATCH\|patchLayout\|async function patch" apps/app-laravel/resources/js/stores/blockStore.ts
```
Implement against whatever HTTP helper is already used (fetch/axios/api client). Keep the SweetAlert error dialog (from the earlier MongoBlobStore→Swal work) as the final fallback if the retry also 409s.

### 4b. Verify
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: exit 0.

Commit: `fix(review): retry block save once on 409 before surfacing error`

---

## Tests / validation (TDD)

### T1. `withLock` throws `ConcurrencyException` (not bare RuntimeException) on exhaustion
File: `apps/app-laravel/tests/Unit/MongoBlobStoreConcurrencyTest.php` (new)

Approach: construct `MongoBlobStore` with a mocked `MongoDB\Collection` whose `findOne` always returns a doc with a fixed `_version`, and whose `updateOne` always returns a result with `getMatchedCount() === 0` (simulating perpetual contention). Assert `withLock` throws `ConcurrencyException`.
```php
<?php

namespace Tests\Unit;

use App\Services\Storage\ConcurrencyException;
use App\Services\Storage\MongoBlobStore;
use MongoDB\Collection;
use MongoDB\UpdateResult;
use Tests\TestCase;

class MongoBlobStoreConcurrencyTest extends TestCase
{
    public function test_withlock_throws_concurrency_exception_when_retries_exhausted(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('findOne')->willReturn(['_id' => 'doc_x', '_version' => 1, 'review' => []]);

        $missResult = $this->createMock(UpdateResult::class);
        $missResult->method('getMatchedCount')->willReturn(0);
        $collection->method('updateOne')->willReturn($missResult);

        $store = new MongoBlobStore($collection);

        $this->expectException(ConcurrencyException::class);
        $store->withLock('review', 'doc_x', function (array &$d): void { $d['x'] = 1; });
    }
}
```
Run (RED before Task 1b lands, GREEN after):
```bash
docker compose exec laravel-app php artisan test --filter=MongoBlobStoreConcurrencyTest
```
Expected after Task 1+2: `OK (1 test)`. (Note: with backoff, the test sleeps ~6× up to ~200ms — acceptable, < 1.5s total.)

### T2. `ReviewController::update` returns 409 on contention, 404 on not-found
File: `apps/app-laravel/tests/Feature/BlockUpdateConflictTest.php` (new)
- Bind a `ReviewStore` (or its `MongoBlobStore`) test double: one case makes `patchApprovedBlock` throw `ConcurrencyException` → assert response 409; another throws `RuntimeException('Block not found.')` → assert 404.
```bash
docker compose exec laravel-app php artisan test --filter=BlockUpdateConflictTest
```
Expected: `OK (2 tests)`.

### T3. Regression
```bash
docker compose exec laravel-app php artisan test
```
Expected: no new failures vs baseline.

Commit tests alongside the task they cover (T1 with Task 1/2, T2 with Task 3).

## Risks, tradeoffs, and open questions
- **Backoff adds latency to genuinely contended saves** (up to ~200ms/attempt, ~6 attempts). Acceptable: only the contended path pays it; the happy path returns on attempt 0 with no sleep. If p99 save latency matters, lower `MAX_ATTEMPTS` or the cap.
- **409 is the correct HTTP semantics** for optimistic-lock contention; the frontend retry (Task 4) hides it from users in the common case. If the client cannot be changed in this cycle, Tasks 1-3 alone still fix the *mislabeling* (409 instead of a scary 404) — Task 4 is the UX polish.
- **Deeper root cause (open):** the editor may be firing N concurrent PATCHes for N blocks on first save. A more thorough fix is a single batch-save endpoint (one `withLock` transaction for all blocks) instead of N racing writes. That is a larger change — recommend as a follow-up if 409s persist after backoff. Not in scope here.
- **`status` vs `review` share `_version`:** `setStatus` (e.g. `updateRagSkipped`) contends with block saves. Backoff mitigates it; a schema split (separate `_version` per sub-kind) would eliminate it but is a storage-format change — out of scope, note as follow-up.
- **Mongo availability in CI/local:** T1 mocks the collection so it needs no live Mongo. T2 mocks the store. T3 (full suite) may need the normal test DB — run in the container where Mongo is available.
- **Message wording** — user-facing message is Thai: `กำลังบันทึกอยู่ กรุณาลองใหม่อีกครั้ง` (confirmed).
