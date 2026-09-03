# Plan: Document Download on /law/:id (Original + Related + Generated PDF)

## Goal

Add download buttons to the `/law/:id` page so users can download: (1) the original source document, (2) a server-generated PDF from approved content, and (3) files of related/linked documents — with dual storage support (local dev vs BUU MinIO production).

## Current Context

### What exists
- `DocumentFileController::show()` at `GET /api/documents/{id}/file` — serves the original PDF inline (only if source is PDF; 404s for DOCX/DOC).
- `OriginalPdfExportController::store()` at `POST /api/documents/{id}/export-pdf-original` — converts DOCX/DOC → PDF via LibreOffice, returns PDF download.
- `downloadPdfExport()` (client.ts:278) — `POST /api/documents/{id}/export-pdf` — downloads a generated PDF from approved blocks.
- `downloadOriginalPdfExport()` (client.ts:291) — calls the LibreOffice conversion endpoint.
- `LawDocumentView.vue` line 112 — already has a "ดาวน์โหลด PDF" button, but only when `usesOriginalPdfLayout` is true (old docs / PDF source). It links to `/api/documents/{id}/file` (inline, not download).
- Relations exist in `review.relations[]` with `target_document_id` linking to other documents.
- `BuuMinioService` has `putFile()` and `getPublicLinks()` but is NOT wired into file serving yet — only e-sign uses it.
- `BUU_MINIO_ENABLED` env toggle pattern (from memory) — used to gate MinIO features.

### What's missing
1. No download button for non-PDF originals (DOCX/DOC) on `/law/:id`.
2. No "generated PDF" download on `/law/:id` (only exists in compose/export flow).
3. No way to download related documents' files from the relations panel.
4. `DocumentFileController` only serves local files — no MinIO fallback.
5. No `BUU_MINIO_ENABLED` toggle in `DocumentFileController`.

## Architecture

Three changes: (A) Enhance `DocumentFileController` to support both local and MinIO file serving with a download disposition, (B) Add a download section to `LawDocumentView.vue` with 3 buttons (original source, generated PDF, related docs), (C) Wire the MinIO `getPublicLinks()` as a redirect-based fallback when local file is absent and MinIO is enabled.

## Assumptions

- "เอกสารที่เชื่อมโยง" = documents linked via `relations[].target_document_id` (the relation system already exists).
- "PDF ที่เราทำให้ user" = the generated PDF from approved blocks (existing `/export-pdf` endpoint).
- Download buttons go on `/law/:id` (LawDocumentView) — the public-facing page.
- MinIO feature toggle follows the `BUU_MINIO_ENABLED` env pattern already established.
- For dev: files served from local `poc_storage` volume. For prod (MinIO enabled): redirect to presigned MinIO URL.

---

## Step-by-step Tasks

### Task 1: Add `BUU_MINIO_ENABLED` config key

**File:** `apps/app-laravel/config/buu.php`

Add at top of the return array:
```php
'minio_enabled' => env('BUU_MINIO_ENABLED', false),
```

**File:** `apps/app-laravel/.env.example`

Add:
```
BUU_MINIO_ENABLED=false
```

**Verify:** `grep minio_enabled apps/app-laravel/config/buu.php` — should show the line.

---

### Task 2: Enhance `DocumentFileController` — support all file types + download disposition + MinIO fallback

**File:** `apps/app-laravel/app/Http/Controllers/Api/DocumentFileController.php`

Replace the entire `show()` method. The new logic:

1. Look up `status.source_path` (the relative path to the uploaded file).
2. Determine MIME type from extension (pdf/docx/doc).
3. If `?download=1` query param, use `Content-Disposition: attachment` instead of `inline`.
4. If local file exists → serve it directly.
5. Else if `BUU_MINIO_ENABLED` → call `BuuMinioService::getPublicLinks()` to get a presigned URL → redirect.
6. Else → 404.

Add a new method `downloadOriginalAsPdf()` that:
- If source is already PDF → delegate to `show()` with download=1.
- If source is DOCX/DOC → delegate to `OriginalPdfExportController::store()` (reuse existing logic).

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buu\BuuMinioService;
use App\Services\ReviewStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\HeaderUtils;

class DocumentFileController extends Controller
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly BuuMinioService $minioService,
    ) {}

    public function show(Request $request, string $documentId): Response|\Illuminate\Http\RedirectResponse
    {
        $status = $this->reviewStore->getStatus($documentId);
        if ($status === null) {
            abort(404, 'Document not found.');
        }

        // Access check
        try {
            $meta = $this->reviewStore->getReviewDocument($documentId)['law_meta'] ?? [];
        } catch (\Throwable) {
            $meta = [];
        }
        if (($meta['access_scope'] ?? 'public') === 'private' && ! $request->user()) {
            abort(403, 'This document is private.');
        }

        $relative = (string) ($status['source_path'] ?? '');
        if ($relative === '') {
            abort(404, 'Original file not available.');
        }

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        $isDownload = $request->boolean('download');

        // Try local file first
        $path = $this->reviewStore->absolutePath($relative);
        if (File::exists($path)) {
            $disposition = $isDownload
                ? HeaderUtils::DISPOSITION_ATTACHMENT
                : HeaderUtils::DISPOSITION_INLINE;
            $filename = basename((string) ($status['source_file'] ?? $relative));
            $dispositionHeader = HeaderUtils::makeDisposition(
                $disposition,
                $filename,
                trim((string) preg_replace('/[^\x20-\x7e]/', '', $filename)) ?: 'document',
            );

            return response(File::get($path), 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => $dispositionHeader,
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        // Try MinIO fallback
        if (config('buu.minio_enabled')) {
            try {
                $links = $this->minioService->getPublicLinks(
                    ['file' => $relative],
                    ['file' => basename((string) ($status['source_file'] ?? $relative))],
                );
                $url = $links['file'][$isDownload ? 'download' : 'view'] ?? $links['file']['view'] ?? null;
                if ($url) {
                    return redirect($url);
                }
            } catch (\Throwable) {
                // Fall through to 404
            }
        }

        abort(404, 'File not found.');
    }
}
```

**Route:** No change needed — `GET /api/documents/{documentId}/file` already exists at `routes/api.php:61`.

**Verify:**
```bash
docker compose exec laravel-app php artisan route:list --path=documents | grep file
```
Expected: shows `GET documents/{documentId}/file`.

---

### Task 3: Add API endpoint for related document file download

**File:** `apps/app-laravel/routes/api.php`

Add after line 61:
```php
Route::get('/documents/{documentId}/related/{targetDocumentId}/file', [DocumentFileController::class, 'showRelated']);
```

**File:** `apps/app-laravel/app/Http/Controllers/Api/DocumentFileController.php`

Add method `showRelated()`:
```php
public function showRelated(Request $request, string $documentId, string $targetDocumentId): Response|\Illuminate\Http\RedirectResponse
{
    // Verify the relation exists (security: can't download arbitrary docs via this route)
    $doc = $this->reviewStore->getReviewDocument($documentId);
    $relations = $doc['relations'] ?? [];
    $linked = collect($relations)->firstWhere('target_document_id', $targetDocumentId);
    if (! $linked) {
        abort(404, 'Related document not found.');
    }

    // Delegate to the standard show() — it handles access check, local/MinIO
    return $this->show($request, $targetDocumentId);
}
```

**Verify:**
```bash
docker compose exec laravel-app php artisan route:list --path=related
```

---

### Task 4: Add TypeScript client functions

**File:** `apps/app-laravel/resources/js/api/client.ts`

Add after `documentFileUrl()` (line 106):
```typescript
export function documentFileDownloadUrl(documentId: string): string {
  return `/api/documents/${encodeURIComponent(documentId)}/file?download=1`;
}

export function relatedDocumentFileUrl(documentId: string, targetDocumentId: string): string {
  return `/api/documents/${encodeURIComponent(documentId)}/related/${encodeURIComponent(targetDocumentId)}/file?download=1`;
}
```

**Verify:** `grep -n documentFileDownloadUrl apps/app-laravel/resources/js/api/client.ts` — should show the new function.

---

### Task 5: Add download section to LawDocumentView.vue

**File:** `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`

**5a.** Add imports in `<script setup>` (after line 228):
```typescript
import { documentFileDownloadUrl, relatedDocumentFileUrl, downloadPdfExport } from '../../api/client';
```

**5b.** Add computed/ref values after `fileUrl` (after line 287):
```typescript
const downloadUrl = computed(() => documentFileDownloadUrl(props.documentId));
const pdfExportLoading = ref(false);

async function handlePdfExport() {
  pdfExportLoading.value = true;
  try {
    await downloadPdfExport(props.documentId);
  } catch (e: any) {
    console.error('PDF export failed', e);
  } finally {
    pdfExportLoading.value = false;
  }
}

const downloadableRelations = computed(() =>
  relations.value.filter((r) => r.target_document_id),
);
```

**5c.** Add download card in template — insert after the `lawx-headcard` (after line 107, before the `usesOriginalPdfLayout` template):

```html
<v-card class="lawx-card lawx-download-card" elevation="0">
  <div class="lawx-download-card__title">
    <span class="mdi mdi-download-circle-outline" />
    ดาวน์โหลดเอกสาร
  </div>
  <div class="lawx-download-card__actions">
    <v-btn
      :href="downloadUrl"
      variant="tonal"
      size="small"
      prepend-icon="mdi-file-document-outline"
    >
      ดาวน์โหลดเอกสารต้นฉบับ
    </v-btn>
    <v-btn
      variant="tonal"
      size="small"
      prepend-icon="mdi-file-pdf-box"
      :loading="pdfExportLoading"
      @click="handlePdfExport"
    >
      ดาวน์โหลด PDF
    </v-btn>
  </div>

  <template v-if="downloadableRelations.length">
    <div class="lawx-download-card__subtitle">
      <span class="mdi mdi-link-variant" />
      เอกสารที่เชื่อมโยง
    </div>
    <div class="lawx-download-card__relations">
      <div v-for="rel in downloadableRelations" :key="rel.id" class="lawx-download-card__relrow">
        <span class="lawx-download-card__reltitle">{{ rel.target_title || rel.target_document_id }}</span>
        <v-btn
          :href="relatedDocumentFileUrl(props.documentId, rel.target_document_id!)"
          variant="text"
          size="x-small"
          prepend-icon="mdi-download"
          class="text-none"
        >
          ดาวน์โหลด
        </v-btn>
      </div>
    </div>
  </template>
</v-card>
```

**5d.** Add CSS for the download card in the `<style>` section:

```css
.lawx-download-card {
  padding: 16px 24px;
}
.lawx-download-card__title {
  font-weight: 600;
  font-size: 0.95rem;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.lawx-download-card__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.lawx-download-card__subtitle {
  font-weight: 500;
  font-size: 0.85rem;
  margin-top: 16px;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
  color: rgba(var(--v-theme-on-surface), 0.7);
}
.lawx-download-card__relations {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.lawx-download-card__relrow {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 0;
}
.lawx-download-card__reltitle {
  font-size: 0.85rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
  min-width: 0;
}
```

**Verify:**
```bash
cd apps/app-laravel && npx vue-tsc --noEmit 2>&1 | head -20
```
Expected: no new errors.

---

### Task 6: Feature test for DocumentFileController enhancements

**File:** `apps/app-laravel/tests/Feature/DocumentFileTest.php`

This file already exists. Add tests for:
1. `download=1` query param returns `Content-Disposition: attachment`.
2. Non-PDF files (DOCX) are served with correct MIME.
3. `showRelated()` returns 404 when relation doesn't exist.
4. `showRelated()` serves file when relation is valid.

```php
public function test_download_param_returns_attachment_disposition(): void
{
    $store = app(ReviewStore::class);
    $id = 'doc_dl_'.uniqid();
    // Create a fake PDF file
    $uploadDir = $store->absolutePath('uploads');
    @mkdir($uploadDir, 0755, true);
    $filePath = $uploadDir.'/'.$id.'.pdf';
    file_put_contents($filePath, '%PDF-1.4 fake');
    $store->setStatus($id, [
        'status' => 'done',
        'source_path' => 'uploads/'.$id.'.pdf',
        'source_file' => 'test.pdf',
    ]);

    $response = $this->get("/api/documents/{$id}/file?download=1");
    $response->assertOk();
    $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

    @unlink($filePath);
}

public function test_related_file_404_when_no_relation(): void
{
    $store = app(ReviewStore::class);
    $id = 'doc_rel_file_'.uniqid();
    $store->setStatus($id, ['status' => 'done']);
    $store->writeReviewDocument($id, [
        'document_id' => $id, 'source_file' => 'x.pdf', 'source_type' => 'pdf',
        'language' => 'th', 'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
        'pages' => [['page_no' => 1, 'blocks' => []]],
    ]);

    $response = $this->get("/api/documents/{$id}/related/nonexistent/file");
    $response->assertNotFound();
}
```

**Verify:**
```bash
docker compose exec laravel-app php artisan test --filter=DocumentFileTest
```

---

### Task 7: Manual smoke test

1. Upload a DOCX document via admin UI.
2. Publish it (set `published_date` in law_meta).
3. Navigate to `/law/{id}`.
4. Verify "ดาวน์โหลดเอกสารต้นฉบับ" button triggers a file download.
5. Verify "ดาวน์โหลด PDF" button triggers a generated PDF download.
6. If the document has relations with `target_document_id`, verify the related doc download buttons appear and work.

---

## Risks & Tradeoffs

| Risk | Mitigation |
|------|-----------|
| MinIO `getPublicLinks()` may return different key structure than expected | Test with real MinIO in staging; the `$links['file']['view']` path is based on existing e-sign usage pattern — verify with `BuuMinioService` tests |
| `showRelated()` opens a side channel to download any document if you know its ID and can create a relation pointing to it | The method validates the relation exists AND delegates to `show()` which checks `access_scope` — acceptable |
| LibreOffice not available in all containers | `OriginalPdfExportController` already handles this with 503 — "PDF ดาวน์โหลด" button uses the export-pdf endpoint (block-based), not LibreOffice |
| Large files (>100MB) served through PHP | Production should use MinIO presigned URLs (redirect, no PHP memory) — that's the MinIO path. For dev, files are typically small |

## Open Questions

1. **MinIO folder path**: What MinIO folder are original documents stored under? The `putFile()` calls in the codebase only appear in e-sign flow. If original docs aren't uploaded to MinIO yet, we need an upload step in the extraction pipeline (or a backfill command like `minio:migrate`). → **Assumption: the feature/minio-old-doc branch (from memory) handles MinIO upload. This plan only adds the read/serve path.**

2. **Generated PDF style**: The existing `/export-pdf` endpoint generates PDF from approved blocks. Is the styling/layout acceptable for the law page download? → **Assumption: yes, reuse existing.**
