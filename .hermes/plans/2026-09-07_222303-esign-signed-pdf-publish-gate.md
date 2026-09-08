# Plan — e-Sign "signed = Y" gate + serve signed PDF everywhere

> Project: `docling-thai-poc/apps/app-laravel` (Laravel 11 API + Vue 3 / Vuetify SPA).
> Backend tests: PHPUnit Feature tests. Frontend "tests": `*.check.ts` files run with `npx tsx` (no vitest/jest in this repo).

## Goal
A new-document law is only publishable once e-Sign has actually returned `sign_status = 'Y'`, and after that every preview/download surface (admin + public) serves the **signed MinIO PDF** instead of the freshly-generated one; old documents keep their current behaviour.

## Current context / assumptions
- e-Sign lifecycle stores flags on the document status blob (`ReviewStore::setStatus`): `esign_submitted_at`, `esign_sign_status` (`'Y'`/`'N'`/`'C'`/null), `esign_confirmed_at`, `esign_signed_filename`, `esign_signed_bucket`, `esign_doc_filename`, `esign_send_response`.
- The Kong callback (`EsignCallbackController::receive`) is the **only** legitimate place that sets `esign_sign_status = 'Y'` + `esign_confirmed_at` + `esign_signed_filename` (lines 95-108). This is correct and stays untouched.
- **VULNERABILITY (confirmed, user chose fix-at-source):** `ReviewController::updateWorkflowProgress` (`app/Http/Controllers/Api/ReviewController.php:256-261`) fires on `PATCH /documents/{id}/workflow-progress` and, when `completed_step >= 6`, unconditionally sets `esign_confirmed_at = now()` **and** `status = 'ingested'` **without checking `esign_sign_status === 'Y'`**. A new document can therefore be marked published/confirmed even though nobody signed. This is the hole.
- Publish gate single source of truth: `resources/js/composables/usePublishGates.ts` → `evaluatePublishGates()`. Gate 1 ("e-Sign") currently passes on `esign_confirmed_at` truthy + not rejected + send not failed — it does **not** require `esign_sign_status === 'Y'`, so the forged `confirmed_at` above would satisfy it. Used by `EditHubWorkspace.vue`, `PublishConfirmDialog.vue`, `ESignStatusWorkspace.vue`.
- Signed-PDF serving already exists end-to-end for the **admin e-Sign status page only**:
  - `EsignSubmitService::signedPdfObject()` (returns MinIO object only when `esign_sign_status` is `Y`/signed, null for `N`/`C`).
  - `EsignController::signedPdf()` → `GET /documents/{id}/esign/signed-pdf` (JSON links, `?download=1` redirect, `?redirect=1` view redirect).
  - Frontend: `esignStatus.ts` (`isEsignApproved`, `hasSignedEsignPdf`), `ESignStatusWorkspace.vue` (`showingSignedPdf`), `DocumentScrollPreviewDialog.vue`.
- **Public download does NOT use signed PDF yet.** `PublicShowRelationsPage.vue::downloadRowPdf()` (line ~667/719) branches only on `row.documentType === 'old'`: old → `documentFileDownloadUrl` (original upload); new → `downloadPdfExport` (**regenerates** a fresh PDF, not the signed one). Bulk download (`downloadSelected`) calls the same `downloadRowPdf`.
- `ShowRelRow` (`resources/js/composables/useShowRelations.ts:29-48`) carries `documentType` and `metaStatus` but **not** any e-sign flag. Public rows come from published laws only.
- Public visibility gate: `LawSearchController::fileBasedSearch()` filters on pipeline `status === 'ingested'`. Because the source fix stops `status='ingested'` until `Y`, unsigned new docs stay out of public automatically — matching the user's "public ต้องซ้อนไว้จนกว่าจะกดเผยแพร่".

### Decisions locked with the user
1. **Fix at source AND at the gate.** Block `updateWorkflowProgress` from setting `esign_confirmed_at`/`status=ingested` for a new doc unless `esign_sign_status === 'Y'`; also harden the publish gate to require `Y`.
2. **After signing, every PDF surface serves the signed file** — public single + public bulk + admin. Old docs (`document_type === 'old'`) keep original-file behaviour, unsigned.
3. **Public visibility unchanged for unsigned new docs** (they must not appear until published). Admin may still download the *unsigned generated* PDF while waiting; once signed, admin download returns the signed file.

## Architecture / proposed approach
Three thin layers, no new endpoints:
- **Backend guard** in `updateWorkflowProgress` — refuse the publish-completing patch for new docs when not `Y` (root-cause fix), returning `422`.
- **Gate hardening** in `evaluatePublishGates` — add `esign_sign_status === 'Y'` to the required e-Sign condition so the UI/publish button reflects the same truth.
- **Signed-PDF routing** — a small shared frontend helper that, for non-old docs, downloads via the existing `signed-pdf` endpoint (falls back to generated only when the doc is genuinely not signed, i.e. admin-waiting), wired into `PublicShowRelationsPage` single/bulk download and `ResultPage` admin download.

Work in this order (backend first so the hole is closed even if frontend lags): Task 1 → 2 → 3 (backend), then 4 → 7 (frontend).

---

## Step-by-step tasks

### Task 1 — Backend: helper to detect "old" document in ReviewController
**Why:** the guard must only apply to new docs; old docs never go through e-Sign.

Read `app/Http/Controllers/Api/ReviewController.php` around the top and confirm `$this->reviewStore` is injected (it is, used throughout). Old-doc marker lives in `law_meta.document_type === 'old'`.

Add a private helper near the other private helpers (e.g. just above `updateWorkflowProgress`, ~line 236). Paste exactly:

```php
    private function isOldDocument(string $documentId): bool
    {
        try {
            $meta = $this->reviewStore->getReviewDocument($documentId)['law_meta'] ?? [];
        } catch (\Throwable) {
            $meta = [];
        }

        return ($meta['document_type'] ?? 'new') === 'old';
    }
```

**Verify:** `cd apps/app-laravel && ./vendor/bin/pint app/Http/Controllers/Api/ReviewController.php --test` → expect `PASS` (or no style diff). Do not run the app yet.

---

### Task 2 (TDD RED) — Backend: failing test that step-6 publish is blocked without `Y`
**File:** create `tests/Feature/WorkflowPublishEsignGuardTest.php`. Paste exactly:

```php
<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class WorkflowPublishEsignGuardTest extends TestCase
{
    public function test_new_doc_cannot_complete_step6_without_signed_callback(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-wf-guard-unsigned-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_submitted_at' => now()->toIso8601String(),
            'esign_sign_status' => null,       // NOT signed
            'esign_confirmed_at' => null,
        ]);
        // Mark as a NEW document (default), no law_meta.document_type = 'old'.

        $this->patchJson("/api/documents/{$docId}/workflow-progress", [
            'completed_step' => 6,
        ])->assertStatus(422);

        $after = $store->getStatus($docId);
        $this->assertNull($after['esign_confirmed_at'] ?? null, 'confirmed_at must NOT be forged');
        $this->assertNotSame('ingested', $after['status'] ?? null, 'status must NOT be published');
    }

    public function test_new_doc_completes_step6_when_signed(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-wf-guard-signed-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_submitted_at' => now()->toIso8601String(),
            'esign_sign_status' => 'Y',        // signed
            'esign_confirmed_at' => now()->toIso8601String(),
        ]);

        $this->patchJson("/api/documents/{$docId}/workflow-progress", [
            'completed_step' => 6,
        ])->assertOk();

        $this->assertSame('ingested', $store->getStatus($docId)['status'] ?? null);
    }

    public function test_step_below_6_is_unaffected(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-wf-guard-step5-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_sign_status' => null,
        ]);

        $this->patchJson("/api/documents/{$docId}/workflow-progress", [
            'completed_step' => 5,
        ])->assertOk();
    }
}
```

**Verify (RED):** `cd apps/app-laravel && php artisan test --filter=WorkflowPublishEsignGuardTest`
Expected: `test_new_doc_cannot_complete_step6_without_signed_callback` **fails** (currently returns 200, not 422). The other two may already pass.

---

### Task 3 (GREEN) — Backend: enforce the guard in `updateWorkflowProgress`
**File:** `app/Http/Controllers/Api/ReviewController.php`. Replace the step-6 block (currently lines 256-261):

```php
        // Step 6 = e-Sign confirmed → mark published immediately so the document
        // appears in the law list without waiting for the async IngestRagJob.
        if ($completedStep >= 6) {
            $patch['status'] = 'ingested';
            $patch['esign_confirmed_at'] = now()->toIso8601String();
        }
```

with:

```php
        // Step 6 = publish. For NEW documents this is only legitimate once the
        // e-Sign provider has returned sign_status = 'Y' via the Kong callback.
        // Old documents never go through e-Sign, so they publish on metadata alone.
        if ($completedStep >= 6) {
            $current = $this->reviewStore->getStatus($documentId) ?? [];
            $signCode = strtoupper(trim((string) ($current['esign_sign_status'] ?? '')));

            if (! $this->isOldDocument($documentId) && $signCode !== 'Y') {
                return response()->json([
                    'message' => 'เอกสารยังไม่ได้รับการลงนาม e-Sign (sign_status ต้องเป็น Y) จึงยังเผยแพร่ไม่ได้',
                    'esign_sign_status' => $current['esign_sign_status'] ?? null,
                ], 422);
            }

            $patch['status'] = 'ingested';
            // Only stamp confirmed_at from the real signed timestamp; never forge now().
            $patch['esign_confirmed_at'] = $current['esign_confirmed_at']
                ?? ($signCode === 'Y' ? now()->toIso8601String() : null);
        }
```

**Verify (GREEN):** `php artisan test --filter=WorkflowPublishEsignGuardTest` → all 3 pass.
Then regression: `php artisan test --filter=EsignCallback && php artisan test --filter=EsignSubmit` → all pass.
**Commit:** `git add -A && git commit -m "fix(esign): block step-6 publish for new docs until sign_status=Y"` *(do not push — user rule)*.

---

### Task 4 (TDD RED) — Frontend: failing check that gate requires `Y`
**File:** create `resources/js/composables/usePublishGates.check.ts`. Paste exactly:

```ts
// dev-only assertion — no JS test runner in this app. Run:
//   cd apps/app-laravel && npx tsx resources/js/composables/usePublishGates.check.ts
import { evaluatePublishGates } from './usePublishGates';
import type { DocumentStatus, LawMeta } from '../types/document';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error('FAIL: ' + msg);
}

const fullMeta: LawMeta = {
  title: 'ประกาศทดสอบ',
  law_type: 'ประกาศ',
  promulgation_date: '2025-01-01',
  access_scope: 'public',
  status: 'มีผลบังคับใช้',
} as LawMeta;

// New doc, e-sign submitted + confirmed_at set, but sign_status NOT 'Y' → must FAIL e-sign gate.
const forged: DocumentStatus = {
  status: 'ingested',
  esign_submitted_at: '2025-01-01T00:00:00Z',
  esign_confirmed_at: '2025-01-01T00:00:00Z',
  esign_sign_status: null,
  workflow_completed_step: 3,
} as DocumentStatus;

const forgedResult = evaluatePublishGates(fullMeta, forged, [], false);
const forgedEsign = forgedResult.gates.find((g) => g.key === 'esign');
assert(forgedEsign?.ok === false, 'e-sign gate must FAIL when sign_status !== Y even if confirmed_at is set');
assert(forgedResult.hasRequiredFail === true, 'forged confirmed_at must NOT allow publish');

// New doc properly signed (Y) → e-sign gate passes.
const signed: DocumentStatus = {
  status: 'ingested',
  esign_submitted_at: '2025-01-01T00:00:00Z',
  esign_confirmed_at: '2025-01-01T00:00:00Z',
  esign_sign_status: 'Y',
  workflow_completed_step: 3,
} as DocumentStatus;
const signedEsign = evaluatePublishGates(fullMeta, signed, [], false).gates.find((g) => g.key === 'esign');
assert(signedEsign?.ok === true, 'e-sign gate passes when sign_status === Y');

// Old doc → e-sign gate is skipped entirely (not present).
const oldResult = evaluatePublishGates(fullMeta, { status: 'ingested' } as DocumentStatus, [], true);
assert(oldResult.gates.find((g) => g.key === 'esign') === undefined, 'old docs skip the e-sign gate');
assert(oldResult.canPublish === true, 'old doc with full metadata can publish without e-sign');

console.log('usePublishGates.check.ts: all passed');
```

**Verify (RED):** `cd apps/app-laravel && npx tsx resources/js/composables/usePublishGates.check.ts`
Expected: throws `FAIL: e-sign gate must FAIL when sign_status !== Y ...` (current gate passes on `confirmed_at`).

---

### Task 5 (GREEN) — Frontend: require `Y` in the e-Sign gate
**File:** `resources/js/composables/usePublishGates.ts`. Replace the `esignOk` block (lines 39-43):

```ts
    const esignSendFailed = docStatus?.esign_send_response?.status === 'fail';
    const esignOk =
      !!docStatus?.esign_confirmed_at &&
      docStatus?.esign_sign_status !== 'rejected' &&
      !esignSendFailed;
```

with:

```ts
    const esignSendFailed = docStatus?.esign_send_response?.status === 'fail';
    const esignSignedY = String(docStatus?.esign_sign_status ?? '').trim().toUpperCase() === 'Y';
    const esignOk =
      esignSignedY &&
      !!docStatus?.esign_confirmed_at &&
      docStatus?.esign_sign_status !== 'rejected' &&
      !esignSendFailed;
```

Also update the status-label fallback so "waiting" still reads correctly — the existing `else if (docStatus?.esign_submitted_at)` branch (line 52) already yields `'อยู่ระหว่างรอลงนาม'`, which is now correct for the submitted-but-not-Y case. No other label change needed.

**Verify (GREEN):** `npx tsx resources/js/composables/usePublishGates.check.ts` → `usePublishGates.check.ts: all passed`.
Then `npm run typecheck` → no new errors.
**Commit:** `git add -A && git commit -m "fix(esign): publish gate requires sign_status=Y"`.

---

### Task 6 — Frontend: shared helper to pick signed vs generated PDF download
**Why:** DRY — public single, public bulk, and admin all need the same "if signed, use signed-pdf endpoint; if old, use original; else generated" decision. `ShowRelRow` has no esign flag, and public rows are always published (=signed for new docs), so for **public** the rule is purely `documentType`. For **admin** we must consult live status.

**File:** `resources/js/api/client.ts`. Confirm these already exist (they do): `signedEsignPdfUrl(documentId, download)`, `downloadPdfExport`, `documentFileDownloadUrl`. Add one convenience downloader near `downloadPdfExport` (~line 302). Paste exactly:

```ts
/**
 * Download the correct PDF for a published/public law row.
 *  - old doc            → original uploaded file
 *  - new doc (published)→ the signed e-Sign PDF from MinIO (never the regenerated one)
 * Public rows are only ever visible after publish, so a new doc here is always signed.
 */
export function downloadPublishedPdf(
  documentId: string,
  documentType: string,
  fallbackName: string,
): void {
  if (documentType === 'old') {
    const anchor = document.createElement('a');
    anchor.href = documentFileDownloadUrl(documentId);
    anchor.download = fallbackName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    return;
  }

  // New doc: redirect to the signed MinIO PDF (opens/downloads the signed file).
  window.open(signedEsignPdfUrl(documentId, true), '_blank', 'noopener');
}
```

(No test file for this — it is a thin DOM/window wrapper; it is exercised via Task 7's typecheck and manual verification.)

**Verify:** `npm run typecheck` → no new errors.

---

### Task 7 — Frontend: wire public single + bulk download to signed PDF
**File:** `resources/js/pages/public/PublicShowRelationsPage.vue`.

7a. Update the import line (~line 454) to pull in the new helper. Current:
```ts
import { documentFileDownloadUrl, downloadPdfExport, fetchReportSummary, fetchReview, relatedDocumentsZipUrl } from '../../api/client';
```
Replace with:
```ts
import { downloadPublishedPdf, fetchReportSummary, fetchReview, relatedDocumentsZipUrl } from '../../api/client';
```
> Note: `documentFileDownloadUrl` and `downloadPdfExport` were only used inside `downloadRowPdf`; after 7b they are unused. If `npm run typecheck`/build flags any other usage, keep the needed import — re-check with `search_files "documentFileDownloadUrl|downloadPdfExport" PublicShowRelationsPage.vue` before removing.

7b. Replace the whole `downloadRowPdf` function (the version at ~line 719, and confirm there is only one — a duplicate at ~667 appeared in an earlier grep; open the file and dedupe to a single definition):
```ts
async function downloadRowPdf(row: ShowRelRow): Promise<void> {
  const fileName = safePdfName(row.title || row.id);
  if (row.documentType === 'old') {
    const anchor = document.createElement('a');
    anchor.href = documentFileDownloadUrl(row.id);
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    return;
  }

  await downloadPdfExport(row.id, fileName);
}
```
with:
```ts
async function downloadRowPdf(row: ShowRelRow): Promise<void> {
  const fileName = safePdfName(row.title || row.id);
  downloadPublishedPdf(row.id, row.documentType, fileName);
}
```
Bulk download (`downloadSelected` → `downloadRowPdf` per row) inherits this automatically — no change needed there.

**Verify:**
- `npm run typecheck` → no new errors.
- `npm run build` → succeeds (skill note: tsc can pass while Vite build fails on SFCs — build is the real gate here).
**Commit:** `git add -A && git commit -m "feat(public): serve signed e-Sign PDF for published law downloads"`.

---

### Task 8 — Frontend: admin ResultPage download picks signed file once signed
**File:** `resources/js/pages/result/ResultPage.vue`.
Admin may download the *unsigned generated* PDF while waiting (allowed), but once `esign_sign_status === 'Y'` the button must return the signed file.

Read `handlePdfExport` (lines 318-329) and the available `docStatus` ref (used at line 300 `docStatus.value?.status`). Import the esign helper at the top of `<script setup>`:
```ts
import { isEsignApproved } from '../../utils/esignStatus';
import { signedEsignPdfUrl } from '../../api/client';
```
Replace `handlePdfExport` body:
```ts
async function handlePdfExport(): Promise<void> {
  exportingPdf.value = true;
  pdfExportError.value = '';
  try {
    await downloadPdfExport(props.documentId);
    docStatus.value = await fetchStatus(props.documentId);
  } catch (error) {
    pdfExportError.value = error instanceof Error ? error.message : 'ส่งออก PDF ไม่สำเร็จ';
  } finally {
    exportingPdf.value = false;
  }
}
```
with:
```ts
async function handlePdfExport(): Promise<void> {
  exportingPdf.value = true;
  pdfExportError.value = '';
  try {
    if (isEsignApproved(docStatus.value)) {
      // Signed: serve the signed MinIO PDF, not a regenerated one.
      window.open(signedEsignPdfUrl(props.documentId, true), '_blank', 'noopener');
    } else {
      await downloadPdfExport(props.documentId);
      docStatus.value = await fetchStatus(props.documentId);
    }
  } catch (error) {
    pdfExportError.value = error instanceof Error ? error.message : 'ส่งออก PDF ไม่สำเร็จ';
  } finally {
    exportingPdf.value = false;
  }
}
```

**Verify:** `npm run typecheck` → no new errors; `npm run build` → succeeds.
**Commit:** `git add -A && git commit -m "feat(admin): ResultPage serves signed PDF after e-Sign"`.

---

### Task 9 — Full regression + manual smoke
**Automated:**
```bash
cd apps/app-laravel
php artisan test                       # expect: OK, 0 failures
npx tsx resources/js/composables/usePublishGates.check.ts   # expect: all passed
npx tsx resources/js/composables/useShowRelations.check.ts  # expect: all passed (unrelated, sanity)
npm run typecheck                      # expect: no errors
npm run build                          # expect: build success
```
**Manual smoke (dev server or docker):**
1. New doc, submit e-Sign, do **not** trigger callback → publish button/gate shows e-Sign "อยู่ระหว่างรอลงนาม", publish blocked; `PATCH workflow-progress {completed_step:6}` returns 422.
2. Simulate callback `Y` (POST to `/api/esign/callback/{id}` with `sign_status=Y`) → gate passes, publish succeeds, doc appears in public search.
3. Public relations page → single download and "ดาวน์โหลดทั้งหมด" of that new doc → file served is the signed MinIO PDF (has QR/signature), not a regenerated one.
4. Old doc (document_type=old) → publish works without e-Sign; public download returns original upload. Unchanged.

---

## Tests / validation summary
| Layer | Test | Command | Expected |
|---|---|---|---|
| Backend guard | `WorkflowPublishEsignGuardTest` | `php artisan test --filter=WorkflowPublishEsignGuardTest` | 3 pass |
| Backend regression | callback + submit | `php artisan test --filter=Esign` | all pass |
| FE gate | `usePublishGates.check.ts` | `npx tsx resources/js/composables/usePublishGates.check.ts` | all passed |
| FE build | typecheck + build | `npm run typecheck && npm run build` | no errors / success |

## Risks, tradeoffs, open questions
- **`signedPdfObject` MinIO dependency for public bulk download.** `downloadPublishedPdf` opens `signed-pdf?download=1`, which 302-redirects to a short-lived (60s, `getPublicLinks`) MinIO URL. Bulk download of many rows opens many tabs/redirects in a loop (existing behaviour already loops `downloadRowPdf`); if MinIO link generation is slow this could be janky. Acceptable for now (YAGNI) but flag if bulk sets are large.
- **Data backfill.** Any document already forged into `status='ingested'` with `esign_sign_status !== 'Y'` before this fix will still show in public and its download will now hit `signed-pdf`, which returns **404** (no signed file). Open question: do we need a one-off audit query to find `status=ingested AND document_type!=old AND esign_sign_status!=Y` docs and unpublish them? **Recommend** running a read-only count first: (see Task 10 candidate below) — decide with user before mutating.
- **`document_type` source of truth.** Backend guard reads `law_meta.document_type`; frontend reads `documentStore.review.law_meta.document_type` / `row.documentType`. Assumed consistent (`'old'` vs `'new'`). If any doc lacks the field it defaults to `'new'` → treated as requiring e-Sign, which is the safe direction.
- **Admin "download while waiting" leaks generated PDF.** Per user decision this is intentional (admin-only, pre-publish). Public never reaches this path because unsigned new docs aren't published.
- **Duplicate `downloadRowPdf`.** Grep showed the function at two line ranges (~667 and ~719); confirm whether that is two functions or one shifted by earlier edits, and ensure a single definition after Task 7.
- **Open question for user:** Should the admin e-Sign page's "cancel" (`esign_sign_status='C'`) after a prior `Y` also revoke an already-published doc (set `status` back)? Current scope does not touch published docs on cancel. Flag if needed.
