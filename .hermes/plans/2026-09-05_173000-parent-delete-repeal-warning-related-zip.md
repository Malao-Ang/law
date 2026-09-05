# Plan: Q1-Q3 Parent delete guard, repeal child warning, related-docs ZIP download

## Goal
Implement three remaining e-Law requirements: prevent deleting an active parent law, warn admins when repealing a parent with active child laws, and add a ZIP download for the selected law plus related documents on `/law/relations/:id`.

## Current context / assumptions
- Project root: `D:/workspace/outside/docling-thai-poc`
- Laravel app: `apps/app-laravel`
- Public relations page: `apps/app-laravel/resources/js/pages/public/PublicShowRelationsPage.vue`
- Current `downloadAll()` on `/law/relations/:id` downloads only the selected law + `flattenTree(filteredRootNode.value)` related laws, not the whole database. It triggers multiple browser downloads one-by-one. Requirement Q3 is to replace/augment this with one ZIP containing those same related files only.
- Documents are file-backed through `ReviewStore` + `MongoBlobStore` abstractions. Do not construct storage paths in controllers except by using existing `ReviewStore`/`DocumentFileController` helpers or new methods inside `ReviewStore`.
- A law is considered “active child” when it references the parent via `law_meta.parent_document_id(s)` or a document-level relation whose `target_document_id` equals the parent, and the child law metadata status is active/published. Use existing Thai active status helper semantics where available; if none is available in PHP, treat these as active: empty status, `มีผลบังคับใช้`, `ใช้งาน`, `active`, `published` and not active: statuses containing `ยกเลิก`, `สิ้นผล`, `หมดอายุ`, `repeal`, `cancel`, `expired`.

## Architecture / proposed approach
Add backend support in `ReviewStore` for child-law discovery so both delete guard and repeal warning use the same source of truth. Expose a small API endpoint for repeal warning and a ZIP endpoint for related-file download. Update frontend delete/save flows to show Thai messages and update public relations “ดาวน์โหลดทั้งหมด” to download exactly the selected law plus related tree as one ZIP.

## Task Q1 — Prevent deleting parent law with active children

### Q1.1 Add active child discovery in `ReviewStore`
File: `apps/app-laravel/app/Services/ReviewStore.php`

Add methods near `listLawMeta()` or near `deleteDocument()`:
- `activeChildLaws(string $parentDocumentId): array`
- `isActiveLawMetaStatus(?string $status): bool`

Implementation rules:
1. Use `$this->listLawMeta()` for all docs.
2. A row is a child if:
   - `parent_document_id === $parentDocumentId`, OR
   - `parent_document_ids` contains `$parentDocumentId`, OR
   - its review `relations` has a relation with `scope === 'document'` and `target_document_id === $parentDocumentId` and type in `['issued_under', 'amends', 'supersedes', 'repeals', 'references']`.
3. A child is active if `meta_status` is active by helper and `published_date` is not empty OR status is published/done. Preserve old/external docs too.
4. Return list rows with at least: `document_id`, `title`, `meta_status`, `published_date`, `law_type`.

### Q1.2 Throw a typed exception from `deleteDocument()`
File: `apps/app-laravel/app/Services/ReviewStore.php`

Before deleting any paths in `deleteDocument()`, call `activeChildLaws($documentId)`. If not empty, throw `RuntimeException` with a clear Thai message:
`ไม่สามารถลบกฎหมายแม่ได้ เนื่องจากมีกฎหมายลูกที่ยังมีผลบังคับใช้ {N} ฉบับ`

Do not return `false` for this case; `false` remains “not found”.

### Q1.3 Return 409 Conflict from delete API
File: `apps/app-laravel/app/Http/Controllers/Api/UploadController.php`

Wrap `deleteDocument()` in `try/catch (RuntimeException $exception)`:
```php
try {
    if (! $this->reviewStore->deleteDocument($documentId)) { ...404... }
} catch (RuntimeException $exception) {
    return response()->json(['message' => $exception->getMessage()], 409);
}
```

Add import: `use RuntimeException;`

### Q1.4 Frontend delete error already displays message
File: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`

Current catch already shows `err.message`. No UI change needed unless `jsonRequest` hides message. If needed, update `jsonRequest` error parsing in `api/client.ts` so backend `message` appears in thrown Error.

## Task Q2 — Warn when repealing a parent with active child laws

### Q2.1 Add API endpoint for active children
File: `apps/app-laravel/app/Http/Controllers/Api/ReviewController.php`

Add method:
```php
public function activeChildren(string $documentId): JsonResponse
{
    $children = $this->reviewStore->activeChildLaws($documentId);
    return response()->json([
        'document_id' => $documentId,
        'count' => count($children),
        'children' => $children,
    ]);
}
```

File: `apps/app-laravel/routes/api.php`
Add route:
```php
Route::get('/documents/{documentId}/active-children', [ReviewController::class, 'activeChildren']);
```

### Q2.2 Add API client function
File: `apps/app-laravel/resources/js/api/client.ts`

Add type + function:
```ts
export interface ActiveChildLaw {
  document_id: string;
  title: string;
  meta_status?: string | null;
  published_date?: string | null;
  law_type?: string | null;
}

export function fetchActiveChildren(documentId: string): Promise<{ document_id: string; count: number; children: ActiveChildLaw[] }> {
  return jsonRequest(`/api/documents/${documentId}/active-children`);
}
```

### Q2.3 Warn in law-info save flow when status becomes repealed/cancelled
File: `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue`

In `saveAndNext()` after `const payload = buildLawMetaPayload();` and before `documentStore.saveLawMeta(payload)`, detect repeal/cancel status:
- `payload.status`
- `payload.change_status`

If either contains `ยกเลิก`, call `fetchActiveChildren(props.documentId)`. If count > 0, show SweetAlert warning:
- title: `มีกฎหมายลูกที่ยังมีผลบังคับใช้`
- html/list: show count and first 5 child titles
- confirm: `บันทึกต่อ`
- cancel: `กลับไปตรวจสอบ`
- if cancel, stop save.

Import `fetchActiveChildren` and `Swal` if not already imported.

## Task Q3 — ZIP download of selected law + related docs only

### Q3.1 Add backend ZIP endpoint
File: create `apps/app-laravel/app/Http/Controllers/Api/RelatedDocumentsZipController.php`

Endpoint behavior:
- Route: `GET /api/documents/{documentId}/related-download.zip`
- Reads the selected document review and its related document tree.
- Include selected document itself plus descendants/related rows only. Do NOT include all database docs.
- Use existing relationship data. At minimum include:
  - selected document `$documentId`
  - any document whose `parent_document_id(s)` contains `$documentId`
  - any document connected by document-level relations under the selected law tree
- For each included document:
  - Old/external docs: source file from `documentFileDownloadUrl` equivalent / status `source_path`.
  - New/internal docs: prefer generated/signed/export PDF if available; fallback to source file.
- Use `ZipArchive` if available. If unavailable, return HTTP 500 with Thai message `เซิร์ฟเวอร์ยังไม่รองรับการสร้างไฟล์ ZIP`.
- ZIP filename: safe title of selected law + `-เอกสารที่เกี่ยวข้อง.zip`.
- Inside zip, filenames must use law titles, not `doc_...`; de-duplicate by suffix `(2)`, `(3)`.

Recommended ReviewStore support methods:
- `relatedDocumentIdsForDownload(string $documentId): array`
- `downloadableFileForDocument(string $documentId): ?array` returning path/title/extension
Implement these inside `ReviewStore` to avoid duplicating path logic in the controller.

File: `apps/app-laravel/routes/api.php`
Add import and route:
```php
use App\Http\Controllers\Api\RelatedDocumentsZipController;
Route::get('/documents/{documentId}/related-download.zip', RelatedDocumentsZipController::class);
```

### Q3.2 Add frontend API helper
File: `apps/app-laravel/resources/js/api/client.ts`

Add:
```ts
export function relatedDocumentsZipUrl(documentId: string): string {
  return apiUrl(`/api/documents/${encodeURIComponent(documentId)}/related-download.zip`);
}
```

Use the existing URL construction helper style in `client.ts`.

### Q3.3 Update public relations download all button to ZIP
File: `apps/app-laravel/resources/js/pages/public/PublicShowRelationsPage.vue`

Current `downloadAll()` downloads selected row then loops through `flattenTree(filteredRootNode.value)`. Replace with a single anchor download:
```ts
async function downloadAll(): Promise<void> {
  if (!selectedRow.value || downloadAllLoading.value) return;
  downloadAllLoading.value = true;
  try {
    const anchor = document.createElement('a');
    anchor.href = relatedDocumentsZipUrl(selectedRow.value.id);
    anchor.download = safeZipName(`${selectedRow.value.title || selectedRow.value.id}-เอกสารที่เกี่ยวข้อง`);
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
  } finally {
    downloadAllLoading.value = false;
  }
}
```
Add `safeZipName()` similar to `safePdfName()`.
Update import from `client.ts` to include `relatedDocumentsZipUrl`.

Important: This still downloads only selected law + related laws because backend endpoint is scoped by `{documentId}`.

## Tests / validation

### PHP focused tests
Add tests in an existing suitable test file or a new feature/unit test under `apps/app-laravel/tests`:
1. `delete parent with active child returns 409` — create parent/child review/status fixtures, call DELETE `/api/documents/{parent}`, expect 409 and message contains `กฎหมายลูก`.
2. `active children endpoint returns count and titles` — call GET `/api/documents/{parent}/active-children`, expect count 1 and child title.
3. `related ZIP does not include unrelated database documents` — create selected, child, unrelated docs, call `/api/documents/{selected}/related-download.zip`, inspect zip entries if possible; assert selected/child present and unrelated absent.

Run focused tests:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
docker compose exec laravel-app php artisan test --filter=ReviewStore
```
If Docker is unavailable, run:
```bash
php artisan test --filter=ReviewStore
```

### Frontend validation
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
```
Expected: `tsc --noEmit`, exit code 0.

## Commit
Commit as one logical batch:
```bash
git add apps/app-laravel/app apps/app-laravel/routes/api.php apps/app-laravel/resources/js apps/app-laravel/tests
git commit -m "feat(law-relations): guard parent deletion and download related docs zip"
```

## Risks / tradeoffs
- ZIP generation depends on PHP `ZipArchive`; handle missing extension with a clear 500 response.
- Relationship graph may contain cycles; backend traversal must track visited IDs.
- For generated/signed PDFs, existing export locations may differ. Prefer using existing ReviewStore path helpers rather than constructing storage paths in controller.
- Warning on repeal is advisory; Q2 requirement says notify/warn, not block.
