# Upload Queue Simplification + Pipeline Routing Fix Design

**Status:** Approved for implementation
**Date:** 2026-07-11

## Context

`AdminUploadPage.vue` has two overlapping status-tracking systems:
1. A local in-memory "คิวการนำเข้าเอกสาร" card that tracks each file's upload and processing state with per-file progress bars and polling timers.
2. `DocumentPipelineTable.vue` (below the queue card) which reads all documents from MongoDB and auto-polls every 2 s when any document is in an active state.

These duplicate each other. The queue card tracks transient browser state; the pipeline table tracks durable MongoDB state. After upload, both show the same document.

Additionally, the PHP fast extraction path (`FastExtractionPipeline`) is never reached by default. The upload page maps any scan mode except `'local'` to `extraction_engine=standard`, which routes all files — including DOCX — through the Python pipeline. DOCX and DOC files have no use for Gemini/LandingAI/EasyOCR, but they currently wait for the Python service anyway.

## Goals

- Remove the "คิวการนำเข้าเอกสาร" card from `AdminUploadPage.vue`.
- After each file upload completes, immediately trigger a pipeline table reload so the new document appears without waiting up to 2 s.
- Show upload errors via a Vuetify snackbar (replacing the per-item error display the queue card had).
- Disable the file input while uploads are in progress to prevent double-submission.
- Fix `ExtractDocumentJob::shouldUseStandardPipeline()` so DOCX/DOC files always use the fast PHP path regardless of which scan mode the frontend sent.

## Non-goals

- No change to PDF routing logic (PDFs with gemini/landingai/local scan modes continue to use the Python pipeline).
- No change to `DocumentPipelineTable.vue` (already handles real-time polling correctly).
- No change to the scan mode selector in the upload form (still lets users pick gemini or local for scanned PDFs).
- No new API endpoints.

## Architecture

### `AdminUploadPage.vue` changes

**Remove entirely:**
- The "คิวการนำเข้าเอกสาร" card template block (the `v-card` with the queue list).
- `queue` ref and `UploadQueueItem` interface.
- `pollTimers` map and all per-item poll logic (`pollItem`, `clearItemTimer`).
- `applyStatus`, `isActive`, `canEdit`, `removeItem`, `retryUpload`, `goToReview` functions.
- `statusLabel`, `statusColor`, `scanModeLabel`, `fileIcon` helpers.
- The `onBeforeUnmount` cleanup for poll timers.

**Add:**
- `uploading` ref (boolean) — `true` while any POST is in flight; disables the file input.
- `errorMsg` ref (string) — drives a `v-snackbar` that shows upload failures.
- `pipelineTable` template ref (`ref<InstanceType<typeof DocumentPipelineTable> | null>(null)`) — used to call `pipelineTable.value?.load()` after each upload.

**Simplified upload flow:**
```
addFiles(files)
  → uploading = true
  → for each file: POST /api/documents
      → on success: pipelineTable.value?.load()
      → on failure: errorMsg = 'ชื่อไฟล์: <error>'
  → uploading = false
```

All files are uploaded sequentially (current behavior). The pipeline table reload fires after each successful upload so the document appears immediately.

### `ExtractDocumentJob.php` change

`shouldUseStandardPipeline()` — add a DOCX/DOC guard before the `extractionEngine` check:

```php
private function shouldUseStandardPipeline(): bool
{
    $ext = strtolower(pathinfo($this->relativeFilePath, PATHINFO_EXTENSION));

    // DOCX/DOC never need a scan pipeline; fast PHP path always applies
    if (in_array($ext, ['docx', 'doc'], true)) {
        return false;
    }

    if ($this->extractionEngine === 'standard') {
        return true;
    }

    if ($ext !== 'pdf') {
        return false;
    }

    return in_array($this->scanExtractionMode, ['gemini', 'landingai', 'local'], true);
}
```

PDFs keep all existing routing. DOCX/DOC always fall through to `runFast()`.

### File changes

| Action | Path |
|---|---|
| **Modify** | `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue` |
| **Modify** | `apps/app-laravel/app/Jobs/ExtractDocumentJob.php` |

## Data flow after change

```
User selects files
  → addFiles() called
  → uploading = true (file input disabled)
  → POST /api/documents for each file
  → document created in MongoDB (status = queued)
  → pipelineTable.load() called → document appears in table immediately
  → ExtractDocumentJob dispatched (status = processing)
  → DOCX/DOC: shouldUseStandardPipeline() → false → runFast() → seconds
  → PDF with gemini: shouldUseStandardPipeline() → true → runStandard() → Python pipeline
  → DocumentPipelineTable polls every 2 s while doc is active → status updates in real-time
  → uploading = false after all POSTs complete
```

## Error handling

- Upload POST fails (network error, 422 from server): `errorMsg` is set to `"<filename>: <message>"`, shown in snackbar. `uploading` resets to false after all files processed.
- Backend job fails: existing `ExtractDocumentJob::failed()` marks status as `'failed'` in MongoDB. Pipeline table shows the failed stage chip.

## Testing

- Run `docker compose exec laravel-app php artisan test --filter=FastExtractionPipelineTest` — fast path tests must still pass.
- Run `npm run typecheck` — TypeScript must compile clean.
- Manual: upload a DOCX → confirm pipeline table shows it within ~1 s and it completes in seconds (not minutes).
- Manual: upload a PDF with Gemini scan mode → confirms it still routes to Python pipeline.

## Acceptance criteria

- "คิวการนำเข้าเอกสาร" card is absent from the upload page.
- File input is disabled while uploads are in progress.
- After each upload, the pipeline table refreshes immediately (document appears within 1 s).
- Upload errors appear in a snackbar.
- DOCX and DOC uploads complete via the fast PHP path (seconds, not minutes).
- PDF uploads with gemini/landingai/local scan mode still route to Python pipeline (no regression).
- `php artisan test` passes (same pass count as before).
- `npm run typecheck` passes.
