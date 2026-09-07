# Plan: MinIO-first document storage (source on upload, PDF on e-Sign, serve from MinIO)

## Goal
Guarantee every uploaded document's source file lands in MinIO on both extraction paths (fast + standard), keep the existing "PDF → MinIO at e-Sign" step, and make all file serving read from MinIO first (fallback to local) so `storage/app/poc` is no longer the primary source of truth.

## Current context / assumptions

### What already works
- **Upload**: `UploadController::store` saves the source to `storage/app/poc/...`, records `source_path` + `source_file` in status, dispatches `ExtractDocumentJob`.
- **Source → MinIO** already happens, but in **two duplicated copies** of `uploadSourceToMinio()`:
  - `app/Jobs/ExtractDocumentJob.php:147-173` (fast path, after extraction).
  - `app/Http/Controllers/Api/PipelineCallbackController.php:96-122` (standard path, on callback).
  Both upload source-only to folder `/{documentId}`, set `minio_source_filename`, and are non-fatal via `MinioUploadService::uploadIfEnabled`.
- **PDF → MinIO at e-Sign**: `EsignSubmitService::submit` (`app/Services/EsignSubmitService.php:60-113`) exports the reviewed doc to PDF, calls `buuEsign->uploadPdf(...)`, stores `esign_doc_filename` + `esign_bucket`. `send()` then calls SendDocumentSign referencing that MinIO object. This is the "PDF for e-Sign" path and stays.
- **Serving**: `DocumentFileController::show` (`app/Http/Controllers/Api/DocumentFileController.php:52-86`) is **local-first, MinIO-fallback** today.
- `MinioUploadService` (`app/Services/Buu/MinioUploadService.php`) wraps `BuuMinioService::putFile`; `uploadIfEnabled` returns null when `config('buu.minio_enabled')` is false (local dev) — so nothing breaks when MinIO is off.

### Decisions (from user, confirmed)
1. **DOCX upload**: store the **original `.docx` only** on MinIO at upload time. The PDF is generated later at e-Sign (existing `EsignSubmitService::submit` flow). Do NOT convert-and-upload a PDF at upload time.
2. **Old docs** (no e-Sign): store the **uploaded original only** on MinIO. No separate PDF export for old docs.
3. **Serving**: **MinIO-first, local fallback.** Try MinIO; on any failure fall back to the local file. Add a `TODO` noting a future `minio-only` mode (drop local serving) once MinIO is proven stable in production.

> Net effect: items 1 & 2 mean the *upload-time* MinIO behaviour is already correct (source-only). The real work is (a) DRY the duplicated source-upload, (b) flip serving to MinIO-first, (c) make source-upload resilient/verifiable. No new PDF-at-upload step is needed.

### Constraints
- `USER.md`: never `git push` without permission; no AI/co-author markers in code or commit messages.
- MinIO is OFF in local dev (`BUU_MINIO_ENABLED=false`) — every change must degrade gracefully to local when MinIO is disabled.

## Architecture / proposed approach
Extract the two duplicated `uploadSourceToMinio()` bodies into one reusable method on `MinioUploadService` so both extraction paths call a single implementation (DRY). Invert `DocumentFileController::show` to attempt the MinIO public-link redirect first and fall back to streaming the local file only when MinIO is disabled or errors. Keep everything non-fatal so local-dev (MinIO off) still serves from disk.

---

## Task 1 — DRY: one source-upload method on MinioUploadService

### 1a. Add `uploadSource()` to `MinioUploadService`
File: `apps/app-laravel/app/Services/Buu/MinioUploadService.php`

Add this method (uses the same non-fatal `uploadIfEnabled` under the hood, centralising the status read/write):
```php
    /**
     * Upload a document's source file (from its status source_path) to MinIO
     * under folder /{documentId}, and persist minio_source_filename.
     * Non-fatal + no-op when MinIO disabled, source_path empty, or file missing.
     * Returns the stored MinIO object name, or null.
     */
    public function uploadSource(string $documentId, ReviewStore $reviewStore): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $status = $reviewStore->getStatus($documentId);
        $relative = (string) ($status['source_path'] ?? '');
        if ($relative === '') {
            return null;
        }

        $sourcePath = $reviewStore->absolutePath($relative);
        if (! is_file($sourcePath)) {
            return null;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $minioFilename = $this->uploadIfEnabled(
            absolutePath: $sourcePath,
            originalExtension: $ext,
            documentId: $documentId,
            folderPath: '/'.$documentId,
        );

        if ($minioFilename !== null) {
            $reviewStore->setStatus($documentId, [
                'minio_source_filename' => $minioFilename,
            ]);
        }

        return $minioFilename;
    }
```
Add the import at the top of the file:
```php
use App\Services\ReviewStore;
```

### 1b. Replace the duplicated body in ExtractDocumentJob
File: `apps/app-laravel/app/Jobs/ExtractDocumentJob.php`
- Change the call site at line 117 from `$this->uploadSourceToMinio($store);` to:
```php
        app(MinioUploadService::class)->uploadSource($this->documentId, $store);
```
- Delete the private `uploadSourceToMinio()` method (lines 143-173).
- Keep the existing `use App\Services\Buu\MinioUploadService;` import (already present at line 5).

### 1c. Replace the duplicated body in PipelineCallbackController
File: `apps/app-laravel/app/Http/Controllers/Api/PipelineCallbackController.php`
- Change line 87 from `$this->uploadSourceToMinio($reviewStore, $documentId);` to:
```php
        app(MinioUploadService::class)->uploadSource($documentId, $reviewStore);
```
- Delete the private `uploadSourceToMinio()` method (lines 92-122).
- `use App\Services\Buu\MinioUploadService;` already present at line 5.

### 1d. Verify
```bash
docker compose exec laravel-app php -l app/Services/Buu/MinioUploadService.php
docker compose exec laravel-app php -l app/Jobs/ExtractDocumentJob.php
docker compose exec laravel-app php -l app/Http/Controllers/Api/PipelineCallbackController.php
```
Expected: `No syntax errors detected` for all three.
```bash
docker compose exec laravel-app vendor/bin/pint --test app/Services/Buu/MinioUploadService.php app/Jobs/ExtractDocumentJob.php app/Http/Controllers/Api/PipelineCallbackController.php
```
Expected: passes (or run without `--test` to auto-format).

Commit: `refactor(minio): centralise source upload into MinioUploadService::uploadSource`

---

## Task 2 — Serve files MinIO-first

### 2a. Invert `DocumentFileController::show` to MinIO-first
File: `apps/app-laravel/app/Http/Controllers/Api/DocumentFileController.php`

Replace the body **from line 52 to line 86** (the "1. Try local first" block through the final `abort`) with MinIO-first ordering:
```php
        // 1. MinIO first (source of truth once uploaded).
        // TODO(minio-only): when MinIO is proven stable in prod, drop the
        // local fallback below and 404 if MinIO has no object.
        if (config('buu.minio_enabled')) {
            $minioKey = (string) ($status['minio_source_filename'] ?? '');
            if ($minioKey !== '') {
                try {
                    $links = $this->minioService->getPublicLinks(
                        ['file' => $minioKey],
                        ['file' => basename((string) ($status['source_file'] ?? $relative))],
                    );
                    $url = $links['file'][$isDownload ? 'download' : 'view'] ?? $links['file']['view'] ?? null;
                    if (is_string($url) && $url !== '') {
                        return redirect($url);
                    }
                } catch (\Throwable) {
                    // Fall through to local streaming.
                }
            }
        }

        // 2. Local fallback (dev, or MinIO miss/error).
        $path = $this->reviewStore->absolutePath($relative);
        if (File::exists($path)) {
            $disposition = $isDownload
                ? HeaderUtils::DISPOSITION_ATTACHMENT
                : HeaderUtils::DISPOSITION_INLINE;
            $filename = basename((string) ($status['source_file'] ?? $relative));
            $asciiFallback = trim((string) preg_replace('/[^\x20-\x7e]/', '', $filename)) ?: 'document';
            $dispositionHeader = HeaderUtils::makeDisposition($disposition, $filename, $asciiFallback);

            return response(File::get($path), 200, [
                'Content-Type'        => $mime,
                'Content-Disposition' => $dispositionHeader,
                'Cache-Control'       => 'private, max-age=3600',
            ]);
        }

        abort(404, 'File not found.');
```
Note: the MinIO branch now uses `minio_source_filename` (the actual uploaded key) rather than falling back to `$relative` as a key — using the local relative path as a MinIO key was a latent bug. When `minio_source_filename` is empty (never uploaded), it skips straight to local.

### 2b. Verify
```bash
docker compose exec laravel-app php -l app/Http/Controllers/Api/DocumentFileController.php
```
Expected: `No syntax errors detected`.

Manual (MinIO off — local dev): `GET /api/documents/{id}/file` still streams the local file (200). 
Manual (MinIO on — server): same endpoint returns a 302 redirect to a MinIO presigned URL; `curl -I` shows `Location:` a MinIO URL. If MinIO errors, it falls back to 200 local stream.

Commit: `feat(files): serve documents MinIO-first with local fallback`

---

## Task 3 — Make source upload observable + retriable (optional hardening)

> YAGNI check: only do this if uploads are silently failing in prod. It adds a status flag + a manual retry endpoint. If not needed now, skip and keep Task 1+2.

### 3a. Record upload outcome in status
File: `apps/app-laravel/app/Services/Buu/MinioUploadService.php` — in `uploadSource()`, when `$minioFilename === null` and MinIO is enabled, also set a diagnostic flag:
```php
        if ($minioFilename === null) {
            $reviewStore->setStatus($documentId, ['minio_source_upload_failed' => true]);
            return null;
        }
        $reviewStore->setStatus($documentId, [
            'minio_source_filename' => $minioFilename,
            'minio_source_upload_failed' => false,
        ]);
        return $minioFilename;
```
(Replace the plain `if ($minioFilename !== null)` block accordingly.)

### 3b. Verify
```bash
docker compose exec laravel-app php -l app/Services/Buu/MinioUploadService.php
```
Expected: `No syntax errors detected`.

Commit: `feat(minio): flag source-upload failures in document status`

---

## Tests / validation

There is a PHPUnit suite (`docker compose exec laravel-app php artisan test`). Add a focused test for the new service method (TDD order: write failing test → run red → implement → run green → commit).

### T1. Test `MinioUploadService::uploadSource` no-ops when MinIO disabled
File: `apps/app-laravel/tests/Feature/MinioUploadSourceTest.php` (new)
```php
<?php

namespace Tests\Feature;

use App\Services\Buu\MinioUploadService;
use App\Services\ReviewStore;
use Tests\TestCase;

class MinioUploadSourceTest extends TestCase
{
    public function test_upload_source_noop_when_minio_disabled(): void
    {
        config(['buu.minio_enabled' => false]);
        $store = $this->createMock(ReviewStore::class);
        $store->expects($this->never())->method('getStatus');

        $result = app(MinioUploadService::class)->uploadSource('doc_x', $store);

        $this->assertNull($result);
    }
}
```
Run (expect PASS once 1a lands; run BEFORE 1a to see it fail with method-not-found):
```bash
docker compose exec laravel-app php artisan test --filter=MinioUploadSourceTest
```
Expected after 1a: `OK (1 test)`.

### T2. Regression: existing suite stays green
```bash
docker compose exec laravel-app php artisan test
```
Expected: no new failures vs. baseline. (If the suite has pre-existing failures unrelated to these files, note them; do not fix here.)

Commit each task once its verify passes.

## Risks, tradeoffs, and open questions
- **MinIO-first adds a network round-trip per file view.** `getPublicLinks` calls Kong on every request. Mitigation: the presigned URL is a redirect (browser fetches MinIO directly, not through Laravel), and the existing `Cache-Control` only applies to the local branch. If Kong latency becomes an issue, cache presigned URLs briefly (follow-up, not now).
- **`minio_source_filename` may be empty for docs uploaded before this change / when MinIO was off during their upload.** Those fall through to local serving — correct behaviour. No backfill included; a one-off backfill command could re-upload old sources if desired (open question — not requested).
- **Task 3 is optional** (YAGNI). Include only if prod shows silent upload failures.
- **PDF-at-e-Sign unchanged.** Per decision #1, DOCX/new docs still generate the PDF only at e-Sign time via `EsignSubmitService::submit`. If later you want the PDF in MinIO immediately at upload (so it's downloadable before signing), that's a separate task: convert via `LibreOfficeConverter` (docx→pdf) or `DocumentExportService::toPdf`, then `MinioUploadService::uploadIfEnabled` with a `pdf` folder — explicitly out of scope here.
- **`minio-only` mode** is left as a `TODO` in `DocumentFileController` (decision #3) — not implemented until MinIO is proven stable in production.
- **Local dev safety:** every branch checks `config('buu.minio_enabled')`; with it false, behaviour is identical to today (local streaming). Verify by running Task 2's manual check with MinIO off.
