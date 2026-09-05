# Plan: Recover breadcrumb/card fixes and RAG skip flow

## Goal
นำงาน Part A/B/C กลับมาอย่างเป็นระบบ: breadcrumb truncate ถูกต้อง, card equal height ไม่ใช้ arbitrary min-height, และ RAG skip flow มี `rag_skipped` end-to-end พร้อม block publish จนกว่าจะกรอก RAG สำเร็จ

## Current context / findings
- ผู้ใช้เคยให้ task นี้แล้วใน session เก่า @session:default/20260901_214156_8dbea4.
- `session_search` พบข้อความเดิมและพบว่า Codex รอบก่อนเคยทำงานต่อจาก `rag_skipped` ที่ “already implemented” ในช่วงนั้น แต่ repo ปัจจุบันไม่มี implementation แล้ว
- ตรวจ repo ปัจจุบันด้วย:
  ```bash
  cd D:/workspace/outside/docling-thai-poc
  rg -n "rag_skipped|ragSkipped|ข้ามขั้นตอนนี้|skip RAG|skip rag|Breadcrumb ellipsis|min-height: 320px" .
  git log --all --oneline -Srag_skipped -- apps/app-laravel
  git log --all --oneline -G'ข้ามขั้นตอนนี้|rag_skipped|ragSkipped' -- apps/app-laravel
  ```
  ผลที่พบ:
  - `rag_skipped` / `ragSkipped` ไม่มีใน working tree
  - `git log -Srag_skipped --all` ไม่มี commit ใดใน repo ปัจจุบัน
  - `git log -G... --all` ไม่มี commit ใดใน repo ปัจจุบัน
- สรุปสาเหตุที่ execution หายได้อย่างมีหลักฐาน:
  - งาน RAG skip เคยอยู่ในบริบท session/ผล Codex ช่วงก่อน แต่ไม่เคยถูก commit เข้ามาใน git history ปัจจุบัน หรือถูกทำใน worktree/branch/patch ที่ไม่ได้อยู่ใน repo นี้แล้ว
  - ต่อมามี merge/แก้ `EditHubWorkspace.vue` หลายรอบ ทำให้ publish dialog/rag check integration ใน edit hub เคยหายแล้วครั้งหนึ่ง และต้อง restore ใหม่ แปลว่ามี overwrite จากงาน layout จริง
  - ปัจจุบันยืนยันด้วย git search แล้วว่าไม่มี `rag_skipped` ทั้งใน working tree และ commits ของ `apps/app-laravel`

## Files to inspect before implementation
- `apps/app-laravel/resources/js/components/shared/AppShell.vue`
- `apps/app-laravel/resources/js/components/shared/LawRelationColumnPicker.vue`
- `apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue`
- `apps/app-laravel/resources/js/pages/rag/RagPage.vue`
- `apps/app-laravel/resources/js/components/esign/ESignDocumentWorkspace.vue`
- `apps/app-laravel/resources/js/components/edit/EditHubWorkspace.vue`
- `apps/app-laravel/resources/js/stores/documentStore.ts`
- `apps/app-laravel/resources/js/stores/composeStore.ts`
- `apps/app-laravel/resources/js/types/document.ts`
- `apps/app-laravel/resources/js/api/client.ts`
- `apps/app-laravel/app/Http/Controllers/Api/ReviewController.php`
- `apps/app-laravel/app/Http/Controllers/Api/ExportController.php`
- `apps/app-laravel/app/Services/ReviewStore.php`
- `apps/app-laravel/routes/api.php`
- relevant tests under `apps/app-laravel/tests/Feature` and `apps/app-laravel/tests/Unit`

## Architecture / proposed approach
Part A/B are pure CSS fixes and should be committed separately from RAG skip. Part C needs a small persistent status flag stored with the document status JSON (`rag_skipped`) plus a focused API endpoint to set/clear it; frontend RAG page sets it when user skips, successful RAG/export clears it, and publish pages read the status/review state and block publish if skipped remains true

Do not encode `rag_skipped` in localStorage only. It must live in backend status/review store so refresh, another browser, and e-sign page all see the same flag

## Step-by-step tasks

### Task 1 — Create baseline evidence before editing

Run:
```bash
cd D:/workspace/outside/docling-thai-poc
git status --short
rg -n "rag_skipped|ragSkipped|ข้ามขั้นตอนนี้|min-height: 320px|v-breadcrumbs-item:last-child|flex: 0 0 auto" apps/app-laravel/resources/js apps/app-laravel/app apps/app-laravel/routes apps/app-laravel/tests
```

Expected current baseline:
- `rag_skipped` no output
- `min-height: 320px` appears in `apps/app-laravel/resources/js/components/shared/LawRelationColumnPicker.vue`
- `AppShell.vue` has breadcrumb CSS, currently `max-width: 420px` and may not use `flex: 0 1 auto` on the last item

Do not commit

### Task 2 — Part A: fix breadcrumb ellipsis + tooltip CSS

File: `apps/app-laravel/resources/js/components/shared/AppShell.vue`

Goal: tooltip already exists/available; CSS must allow the last breadcrumb item to shrink/truncate

Patch CSS near `.app-shell__breadcrumbs`:
```css
.app-shell__breadcrumbs {
  min-width: 0;
  flex: 1 1 0;
}

.app-shell__breadcrumbs :deep(.v-breadcrumbs) {
  min-width: 0;
}

.app-shell__breadcrumbs :deep(.v-breadcrumbs-item) {
  min-width: 0;
}

.app-shell__breadcrumbs :deep(.v-breadcrumbs-item:last-child) {
  display: inline-block;
  flex: 0 1 auto;
  max-width: 220px;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
}
```

If existing code already has equivalent rules, only change the differing parts (`flex: 0 1 auto`, `max-width: 220px`, `min-width: 0`) and do not reformat unrelated CSS

Verification:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
```
Expected:
```text
> typecheck
> tsc --noEmit
```

Commit:
```bash
cd D:/workspace/outside/docling-thai-poc
git add apps/app-laravel/resources/js/components/shared/AppShell.vue
git commit -m "fix(shell): truncate breadcrumb label with ellipsis"
```

### Task 3 — Part B: card equal height without arbitrary min-height

File discovered by search: `apps/app-laravel/resources/js/components/shared/LawRelationColumnPicker.vue`

Remove arbitrary CSS:
```css
min-height: 320px;
```

Ensure the parent grid/flex container uses stretch and cards fill the cell. Pattern to use if missing:
```css
.relation-column-grid {
  align-items: stretch;
}

.relation-column-card {
  height: 100%;
}
```

Important: use the actual class names in `LawRelationColumnPicker.vue`; do not invent `.relation-column-grid` if the component uses different names

Verification:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
```
Expected:
```text
> typecheck
> tsc --noEmit
```

Commit:
```bash
cd D:/workspace/outside/docling-thai-poc
git add apps/app-laravel/resources/js/components/shared/LawRelationColumnPicker.vue
git commit -m "fix(relations): let picker cards stretch without arbitrary height"
```

### Task 4 — RED backend test: status can persist `rag_skipped`

Preferred file: create/extend `apps/app-laravel/tests/Unit/ReviewStoreTest.php` or a focused existing `ReviewStore` test file if present

Test behavior:
```php
public function test_status_persists_rag_skipped_flag(): void
{
    $store = app(ReviewStore::class);
    $id = 'doc_test_'.uniqid();

    $store->setStatus($id, [
        'status' => 'done',
        'progress' => 100,
        'current_step' => 'done',
        'rag_skipped' => true,
    ]);

    $status = $store->getStatus($id);

    $this->assertTrue($status['rag_skipped']);

    $store->setStatus($id, ['rag_skipped' => false]);
    $status = $store->getStatus($id);

    $this->assertFalse($status['rag_skipped']);

    $store->deleteDocument($id);
}
```

Run RED:
```bash
cd D:/workspace/outside/docling-thai-poc
docker compose exec laravel-app php artisan test --filter=status_persists_rag_skipped_flag
```

Expected:
- It may already pass if `setStatus()` stores arbitrary keys. If it passes, backend persistence already exists; keep the test as regression coverage
- If it fails, minimally update `ReviewStore::setStatus()` defaults/merge logic to preserve boolean `rag_skipped`

Commit after green:
```bash
git add apps/app-laravel/app/Services/ReviewStore.php apps/app-laravel/tests/Unit/ReviewStoreTest.php
git commit -m "test(review): persist rag skipped status flag"
```

### Task 5 — Add API endpoint to set/clear `rag_skipped`

Files:
- `apps/app-laravel/routes/api.php`
- `apps/app-laravel/app/Http/Controllers/Api/ReviewController.php`
- `apps/app-laravel/resources/js/api/client.ts`
- `apps/app-laravel/resources/js/types/document.ts`

Backend endpoint design:
```http
PATCH /api/documents/{documentId}/rag-skip
Content-Type: application/json

{ "rag_skipped": true }
```

Controller method:
```php
public function updateRagSkipped(string $documentId, Request $request): JsonResponse
{
    $validated = $request->validate([
        'rag_skipped' => ['required', 'boolean'],
    ]);

    if ($this->reviewStore->getStatus($documentId) === null) {
        return response()->json(['message' => 'Document not found.'], 404);
    }

    $this->reviewStore->setStatus($documentId, [
        'rag_skipped' => (bool) $validated['rag_skipped'],
    ]);

    return response()->json([
        'document_id' => $documentId,
        'rag_skipped' => (bool) $validated['rag_skipped'],
    ]);
}
```

Add `use Illuminate\Http\Request;` if missing

Route:
```php
Route::patch('/documents/{documentId}/rag-skip', [ReviewController::class, 'updateRagSkipped']);
```

Frontend types:
```ts
export interface DocumentStatus {
  ...
  rag_skipped?: boolean;
}

export interface RagSkipResponse {
  document_id: string;
  rag_skipped: boolean;
}
```

Frontend client:
```ts
export function updateRagSkipped(documentId: string, ragSkipped: boolean): Promise<RagSkipResponse> {
  return jsonRequest<RagSkipResponse>(`/api/documents/${encodeURIComponent(documentId)}/rag-skip`, {
    method: 'PATCH',
    body: JSON.stringify({ rag_skipped: ragSkipped }),
  });
}
```

Tests:
Create/extend a feature test with:
```php
public function test_can_set_and_clear_rag_skipped_flag(): void
{
    $store = app(ReviewStore::class);
    $id = 'doc_test_'.uniqid();
    $store->setStatus($id, ['status' => 'done', 'progress' => 100, 'current_step' => 'done']);

    $this->patchJson("/api/documents/{$id}/rag-skip", ['rag_skipped' => true])
        ->assertOk()
        ->assertJsonPath('rag_skipped', true);
    $this->assertTrue($store->getStatus($id)['rag_skipped']);

    $this->patchJson("/api/documents/{$id}/rag-skip", ['rag_skipped' => false])
        ->assertOk()
        ->assertJsonPath('rag_skipped', false);
    $this->assertFalse($store->getStatus($id)['rag_skipped']);

    $store->deleteDocument($id);
}
```

Run:
```bash
cd D:/workspace/outside/docling-thai-poc
docker compose exec laravel-app php artisan test --filter=rag_skipped
cd apps/app-laravel && npm run typecheck
```

Commit:
```bash
git add apps/app-laravel/routes/api.php apps/app-laravel/app/Http/Controllers/Api/ReviewController.php apps/app-laravel/resources/js/api/client.ts apps/app-laravel/resources/js/types/document.ts apps/app-laravel/tests
git commit -m "feat(rag): add skipped status endpoint"
```

### Task 6 — Add “ข้ามขั้นตอนนี้” button to RAG page

File: `apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue`

Add a visible secondary button near `WorkflowFooterBar`/page header or bottom action area:
```vue
<v-btn
  color="warning"
  variant="tonal"
  prepend-icon="mdi-skip-next-outline"
  :disabled="documentStore.saving || blockBusy"
  @click="skipRagStep"
>
  ข้ามขั้นตอนนี้
</v-btn>
```

Add script:
```ts
async function skipRagStep(): Promise<void> {
  const result = await Swal.fire({
    icon: 'warning',
    title: 'ข้ามขั้นตอนจัดลำดับ RAG?',
    html: 'หากข้ามขั้นตอนนี้ จะยังไม่สามารถเผยแพร่เอกสารได้จนกว่าจะกลับมากรอก RAG ให้เสร็จ',
    showCancelButton: true,
    confirmButtonText: 'ข้ามขั้นตอนนี้',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#f59e0b',
    cancelButtonColor: '#64748b',
  });

  if (!result.isConfirmed) return;

  await updateRagSkipped(props.documentId, true);
  router.push(`/documents/${props.documentId}/law-info`);
}
```

Imports:
```ts
import Swal from 'sweetalert2';
import { updateRagSkipped } from '../../api/client';
```

Run:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
```

Commit:
```bash
cd D:/workspace/outside/docling-thai-poc
git add apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue
git commit -m "feat(rag): allow skipping rag step with confirmation"
```

### Task 7 — Clear `rag_skipped` after successful RAG save/export

Files to inspect:
- `apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue`
- `apps/app-laravel/resources/js/stores/composeStore.ts`
- `apps/app-laravel/app/Http/Controllers/Api/ExportController.php`

Find the success path for current RAG step. In current component, footer emits `@next="goToLawInfo"`; inspect `goToLawInfo()` and export/trigger logic before editing

Preferred behavior:
- When user completes/saves RAG successfully and moves to metadata, call backend to clear flag:
```ts
await updateRagSkipped(props.documentId, false);
```
- If RAG success is server-side export endpoint, also clear in backend after success:
```php
$this->reviewStore->setStatus($documentId, ['rag_skipped' => false]);
```

Run:
```bash
cd D:/workspace/outside/docling-thai-poc
docker compose exec laravel-app php artisan test --filter=rag_skipped
cd apps/app-laravel && npm run typecheck
```

Commit:
```bash
git add apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue apps/app-laravel/app/Http/Controllers/Api/ExportController.php apps/app-laravel/tests
git commit -m "fix(rag): clear skipped flag after completing rag"
```

### Task 8 — Block publish on E-Sign and Edit Hub when `rag_skipped` is true

Files:
- `apps/app-laravel/resources/js/components/esign/ESignDocumentWorkspace.vue`
- `apps/app-laravel/resources/js/components/edit/EditHubWorkspace.vue`
- `apps/app-laravel/resources/js/stores/documentStore.ts` if it needs status fetch helper

Behavior:
- When user toggles publish ON:
  1. Fetch/read current `DocumentStatus`
  2. If `rag_skipped === true`, show SweetAlert and route to RAG page
  3. Return without publishing
  4. Only after RAG check passes should existing draft-status check/publish dialog continue

Code pattern:
```ts
async function ensureRagCompletedBeforePublish(): Promise<boolean> {
  const status = await documentStore.getStatus(props.documentId);
  if (!status?.rag_skipped) return true;

  const result = await Swal.fire({
    icon: 'warning',
    title: 'ยังไม่ได้จัดลำดับ RAG',
    html: 'เอกสารนี้เคยข้ามขั้นตอน RAG ไว้ ต้องกลับไปจัดลำดับเนื้อหาให้เสร็จก่อนเผยแพร่',
    showCancelButton: true,
    confirmButtonText: 'ไปจัดลำดับ RAG',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#1a3673',
    cancelButtonColor: '#64748b',
  });

  if (result.isConfirmed) {
    router.push(`/documents/${props.documentId}/rag`);
  }

  return false;
}
```

Then at start of publish-on branch:
```ts
if (next && !(await ensureRagCompletedBeforePublish())) {
  return;
}
```

Important:
- In `EditHubWorkspace.vue`, the current implementation may use `PublishConfirmDialog` instead of direct SweetAlert. Put the RAG gate before opening/confirming publish state
- Do not remove existing draft-status check; the order must be: RAG skipped check → draft status check → publish

Run:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
npm run build
```

Commit:
```bash
cd D:/workspace/outside/docling-thai-poc
git add apps/app-laravel/resources/js/components/esign/ESignDocumentWorkspace.vue apps/app-laravel/resources/js/components/edit/EditHubWorkspace.vue apps/app-laravel/resources/js/stores/documentStore.ts
git commit -m "fix(publish): block publish when rag was skipped"
```

### Task 9 — Final verification

Run focused tests:
```bash
cd D:/workspace/outside/docling-thai-poc
docker compose exec laravel-app php artisan test --filter=rag_skipped
```

Run frontend:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
npm run build
```

Manual browser validation:
1. Upload/create a new document
2. Go to `/documents/:id/rag`
3. Click `ข้ามขั้นตอนนี้`
4. Confirm SweetAlert
5. Verify route moves to `/documents/:id/law-info`
6. Go to `/documents/:id/esign`
7. Toggle publish ON
8. Expected: SweetAlert says must complete RAG and no publish occurs
9. Click go to RAG, complete/save RAG
10. Verify `rag_skipped` clears and publish flow can continue to draft-status check

Final git checks:
```bash
cd D:/workspace/outside/docling-thai-poc
git status --short
git log --oneline -8
```

Expected:
- `git status --short` clean except unrelated pre-existing files if user intentionally left them
- Recent commits include Part A, Part B, RAG endpoint, RAG skip button, clear flag, publish block

## Risks / open questions
- The repo currently has unrelated uncommitted work. Implementer must not commit unrelated dirty files unless user asks. Check `git status --short` before every commit
- If `ReviewStore::setStatus()` already persists arbitrary keys, `rag_skipped` backend persistence test will pass immediately; still keep the test
- The phrase “เมื่อกลับมากรอก RAG สำเร็จ” must map to the actual success action in `RagManageWorkspace.vue`; inspect `goToLawInfo()` before editing
- If Edit Hub publish uses `PublishConfirmDialog`, ensure the RAG gate runs before opening that dialog so the user is not allowed to confirm publish first
- Breadcrumb tooltip: AppShell may need explicit `v-tooltip` around the final breadcrumb if Vuetify breadcrumbs do not expose a tooltip already. User says tooltip exists, so first fix CSS only; add tooltip markup only if manual verification shows no title/tooltip
