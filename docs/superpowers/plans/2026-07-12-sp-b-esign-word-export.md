# SP-B: eSign Word Export + Result Summary Page + Review Lock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Word (.docx) export for eSign handoff, a result-summary page admin lands on after completing all workflow steps, and a read-only lock on the text editor once a document has been exported.

**Architecture:** Three self-contained slices. (1) PHP `WordExportController` uses the already-installed `phpoffice/phpword` library to stream a `.docx` file and stamps `esign_exported_at` into the MongoDB status doc. (2) A new `ResultPage.vue` at `/documents/{id}/result` shows document meta, export status, and action buttons; `LawRelationsPage` redirects there after completing step 5. (3) `ReviewPage.vue` fetches status alongside the review doc on mount; if `esign_exported_at` is set it passes `locked=true` to `DocumentEditorShell`, which sets TipTap non-editable and hides the toolbar.

**Tech Stack:** PHP 8.3 · Laravel 11 · phpoffice/phpword ^1.4 (already in composer.json) · Vue 3 + Pinia · TypeScript · Vuetify 3

---

## File structure

| Action | Path | Responsibility |
|---|---|---|
| **Create** | `apps/app-laravel/app/Http/Controllers/Api/WordExportController.php` | DOCX stream + status stamp |
| **Create** | `apps/app-laravel/tests/Feature/WordExportControllerTest.php` | Controller test |
| **Create** | `apps/app-laravel/resources/js/pages/result/ResultPage.vue` | Result/summary page |
| **Modify** | `apps/app-laravel/routes/api.php` | Add `POST /documents/{id}/export-word` |
| **Modify** | `apps/app-laravel/routes/web.php` | Add `/documents/{id}/result` SPA route |
| **Modify** | `apps/app-laravel/resources/js/router/index.ts` | Add result route |
| **Modify** | `apps/app-laravel/resources/js/types/document.ts` | Add `esign_exported_at` to `DocumentStatus` |
| **Modify** | `apps/app-laravel/resources/js/api/client.ts` | Add `downloadWordExport()` |
| **Modify** | `apps/app-laravel/resources/js/pages/law-relations/LawRelationsPage.vue` | Redirect to result after step 5 |
| **Modify** | `apps/app-laravel/resources/js/pages/review/ReviewPage.vue` | Fetch status; pass `locked` prop |
| **Modify** | `apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue` | Accept `locked` prop; lock editor |

---

### Task 1: Backend — WordExportController + route + test

**Files:**
- Create: `apps/app-laravel/app/Http/Controllers/Api/WordExportController.php`
- Create: `apps/app-laravel/tests/Feature/WordExportControllerTest.php`
- Modify: `apps/app-laravel/routes/api.php`

- [ ] **Step 1: Write the failing test**

Create `apps/app-laravel/tests/Feature/WordExportControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WordExportControllerTest extends TestCase
{
    public function test_export_word_returns_docx_and_stamps_esign_exported_at(): void
    {
        $store = app(ReviewStore::class);
        $documentId = $store->generateDocumentId();
        $store->storeUpload(
            UploadedFile::fake()->create('law.pdf', 64, 'application/pdf'),
            $documentId,
        );
        $store->setStatus($documentId, ['status' => 'done']);
        $store->writeReviewDocument($documentId, [
            'source_file' => 'law.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'law_meta' => ['title' => 'กฎหมายทดสอบ'],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'raw_text' => 'ข้อความทดสอบ',
                    'normalized_text' => '',
                    'ai_suggested_text' => '',
                    'approved_text' => 'ข้อความที่อนุมัติแล้ว',
                ]],
            ]],
            'document_review' => [
                'generated_html' => '',
                'draft_html' => '',
                'html_mode' => 'generated',
                'out_of_sync' => false,
            ],
        ]);

        $response = $this->postJson("/api/documents/{$documentId}/export-word");

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            (string) $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
        );

        $status = $store->getStatus($documentId);
        $this->assertNotNull($status['esign_exported_at'] ?? null);
    }

    public function test_export_word_returns_404_for_unknown_document(): void
    {
        $this->postJson('/api/documents/nonexistent-id/export-word')
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec laravel-app php artisan test --filter=WordExportControllerTest
```

Expected: both tests fail — controller and route don't exist yet.

- [ ] **Step 3: Create WordExportController**

Create `apps/app-laravel/app/Http/Controllers/Api/WordExportController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReviewStore;
use Illuminate\Http\Response;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

class WordExportController extends Controller
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function store(string $documentId): Response
    {
        $document = $this->reviewStore->getReviewDocument($documentId);
        if (empty($document)) {
            abort(404, 'Document not found.');
        }

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $fontName = 'TH Sarabun New';
        $bodyStyle = ['name' => $fontName, 'size' => 16];
        $paraStyle = ['spaceAfter' => 60, 'alignment' => Jc::BOTH];

        foreach (($document['pages'] ?? []) as $page) {
            foreach (($page['blocks'] ?? []) as $block) {
                $text = trim((string) (
                    $block['approved_text'] ?? $block['normalized_text'] ?? $block['raw_text'] ?? ''
                ));
                if ($text === '') {
                    continue;
                }
                $type = (string) ($block['type'] ?? 'paragraph');
                if ($type === 'title') {
                    $section->addText($text, ['name' => $fontName, 'size' => 20, 'bold' => true], ['alignment' => Jc::CENTER]);
                } elseif ($type === 'section_header') {
                    $section->addText($text, ['name' => $fontName, 'size' => 16, 'bold' => true], $paraStyle);
                } else {
                    $section->addText($text, $bodyStyle, $paraStyle);
                }
            }
        }

        $lawMeta = is_array($document['law_meta'] ?? null) ? $document['law_meta'] : [];
        $rawTitle = trim((string) ($lawMeta['title'] ?? $document['source_file'] ?? 'document'));
        $filename = (string) preg_replace('/[^a-zA-Z0-9_\-\.]/u', '_', $rawTitle);

        $this->reviewStore->setStatus($documentId, ['esign_exported_at' => now()->toIso8601String()]);

        $tempPath = tempnam(sys_get_temp_dir(), 'we_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $content = (string) file_get_contents($tempPath);
        @unlink($tempPath);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.docx"',
        ]);
    }
}
```

- [ ] **Step 4: Add route to api.php**

In `apps/app-laravel/routes/api.php`, add after the existing `use` imports at the top:

```php
use App\Http\Controllers\Api\WordExportController;
```

Then add the route after `Route::post('/documents/{documentId}/export', ...)`:

```php
Route::post('/documents/{documentId}/export-word', [WordExportController::class, 'store']);
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker compose exec laravel-app php artisan test --filter=WordExportControllerTest
```

Expected: 2 passed.

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/app/Http/Controllers/Api/WordExportController.php \
        apps/app-laravel/tests/Feature/WordExportControllerTest.php \
        apps/app-laravel/routes/api.php
git commit -m "feat(esign): add POST /documents/{id}/export-word — DOCX stream + stamps esign_exported_at"
```

---

### Task 2: Frontend — types + downloadWordExport API helper

**Files:**
- Modify: `apps/app-laravel/resources/js/types/document.ts`
- Modify: `apps/app-laravel/resources/js/api/client.ts`

- [ ] **Step 1: Add `esign_exported_at` to DocumentStatus**

In `apps/app-laravel/resources/js/types/document.ts`, find the `DocumentStatus` interface (around line 289) and add the field after `workflow_updated_at`:

```typescript
export interface DocumentStatus {
  document_id: string;
  status: 'queued' | 'processing' | 'done' | 'failed' | 'exported' | 'ingesting' | 'ingested';
  progress: number;
  current_step: string;
  workflow_completed_step?: number | null;
  workflow_current_step?: number | null;
  workflow_updated_at?: string | null;
  esign_exported_at?: string | null;         // ← add this line
  source_file?: string;
  // ... rest unchanged
```

- [ ] **Step 2: Add `downloadWordExport` to api/client.ts**

In `apps/app-laravel/resources/js/api/client.ts`, add after the `exportDocument` function:

```typescript
export async function downloadWordExport(documentId: string): Promise<void> {
  const response = await fetch(`/api/documents/${documentId}/export-word`, {
    method: 'POST',
    headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
  });
  if (!response.ok) {
    const data = (await response.json().catch(() => ({}))) as ApiErrorPayload;
    throw new Error(data.message ?? 'Word export failed');
  }
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  const disposition = response.headers.get('Content-Disposition') ?? '';
  const match = /filename="?([^";\n]+)"?/.exec(disposition);
  a.download = match?.[1] ?? `document-${documentId}.docx`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
```

- [ ] **Step 3: Typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/types/document.ts \
        apps/app-laravel/resources/js/api/client.ts
git commit -m "feat(esign): add esign_exported_at to DocumentStatus + downloadWordExport API helper"
```

---

### Task 3: Result summary page + routes

**Files:**
- Create: `apps/app-laravel/resources/js/pages/result/ResultPage.vue`
- Modify: `apps/app-laravel/routes/web.php`
- Modify: `apps/app-laravel/resources/js/router/index.ts`

- [ ] **Step 1: Create ResultPage.vue**

Create `apps/app-laravel/resources/js/pages/result/ResultPage.vue`:

```vue
<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'บทสรุปการดำเนินการ']"
    title="บทสรุปการดำเนินการ"
    subtitle="ตรวจสอบและส่งออกเอกสารสำหรับ e-Sign"
  >
    <div class="result-page mx-auto">
      <div v-if="loading" class="d-flex align-center justify-center pa-12 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate />
        <span>กำลังโหลด...</span>
      </div>

      <template v-else>
        <!-- Document summary card -->
        <v-card border rounded="lg" class="mb-5 pa-6">
          <div class="d-flex align-center ga-3 mb-4">
            <v-icon icon="mdi-file-document-outline" size="32" color="admin-primary" />
            <div>
              <div class="text-h6 font-weight-bold">{{ docTitle }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ meta?.law_type || 'เอกสาร' }}<span v-if="meta?.agency"> · {{ meta.agency }}</span>
              </div>
            </div>
          </div>
          <div class="d-flex flex-wrap ga-2">
            <v-chip v-if="meta?.status" size="small" color="primary" variant="tonal">{{ meta.status }}</v-chip>
            <v-chip v-if="meta?.promulgation_date" size="small" variant="outlined">ประกาศ {{ meta.promulgation_date }}</v-chip>
            <v-chip v-if="meta?.access_scope === 'private'" size="small" color="warning" variant="tonal" prepend-icon="mdi-lock-outline">Private</v-chip>
          </div>
        </v-card>

        <!-- eSign export card -->
        <v-card border rounded="lg" class="mb-5 pa-6">
          <div class="d-flex align-center ga-3 mb-4">
            <v-icon icon="mdi-draw-pen" size="28" color="elaw-gold" />
            <div class="text-subtitle-1 font-weight-bold">ส่งออกสำหรับ e-Sign</div>
          </div>

          <v-alert v-if="esignExportedAt" type="success" variant="tonal" density="comfortable" class="mb-4">
            ส่งออกแล้วเมื่อ {{ formatThaiDate(esignExportedAt) }}
          </v-alert>
          <v-alert v-if="exportError" type="error" variant="tonal" density="compact" class="mb-3">
            {{ exportError }}
          </v-alert>

          <v-btn
            color="admin-primary"
            prepend-icon="mdi-microsoft-word"
            :loading="exporting"
            @click="handleWordExport"
          >
            {{ esignExportedAt ? 'ส่งออกอีกครั้ง (Word)' : 'Export as Word for e-Sign' }}
          </v-btn>
        </v-card>

        <!-- Navigation actions -->
        <v-card border rounded="lg" class="pa-6">
          <div class="text-subtitle-2 font-weight-bold mb-4">ดำเนินการต่อ</div>
          <div class="d-flex flex-wrap ga-3">
            <v-btn variant="outlined" prepend-icon="mdi-database-cog-outline"
              @click="router.push(`/documents/${props.documentId}/rag`)">
              แก้ไข RAG บล็อก
            </v-btn>
            <v-btn variant="outlined" prepend-icon="mdi-information-outline"
              @click="router.push(`/documents/${props.documentId}/law-info`)">
              แก้ไขข้อมูลเอกสาร
            </v-btn>
            <v-btn variant="outlined" prepend-icon="mdi-graph-outline"
              @click="router.push(`/documents/${props.documentId}/relations`)">
              แก้ไขความสัมพันธ์
            </v-btn>
            <v-btn variant="tonal" color="primary" prepend-icon="mdi-eye-outline"
              @click="router.push(`/law/${props.documentId}`)">
              ดูหน้าเผยแพร่
            </v-btn>
          </div>
        </v-card>
      </template>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { downloadWordExport, fetchReview, fetchStatus } from '../../api/client';
import type { DocumentStatus, LawMeta, ReviewDocument } from '../../types/document';
import AppShell from '../../components/shared/AppShell.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();

const loading = ref(true);
const review = ref<ReviewDocument | null>(null);
const docStatus = ref<DocumentStatus | null>(null);
const exporting = ref(false);
const exportError = ref('');

onMounted(async () => {
  try {
    [review.value, docStatus.value] = await Promise.all([
      fetchReview(props.documentId),
      fetchStatus(props.documentId),
    ]);
  } catch {
    // non-fatal: page still renders with whatever loaded
  } finally {
    loading.value = false;
  }
});

const meta = computed<LawMeta | undefined>(() => review.value?.law_meta);
const docTitle = computed(() => meta.value?.title || review.value?.source_file || props.documentId);
const esignExportedAt = computed(() => docStatus.value?.esign_exported_at ?? null);

function formatThaiDate(iso: string): string {
  return new Date(iso).toLocaleString('th-TH');
}

async function handleWordExport(): Promise<void> {
  exporting.value = true;
  exportError.value = '';
  try {
    await downloadWordExport(props.documentId);
    docStatus.value = await fetchStatus(props.documentId);
  } catch (e) {
    exportError.value = e instanceof Error ? e.message : 'ส่งออกไม่สำเร็จ';
  } finally {
    exporting.value = false;
  }
}
</script>

<style scoped>
.result-page {
  max-width: 720px;
}
</style>
```

- [ ] **Step 2: Add web route**

In `apps/app-laravel/routes/web.php`, add after the existing `/documents/{documentId}/rag` line:

```php
Route::view('/documents/{documentId}/result', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
```

- [ ] **Step 3: Add router entry**

In `apps/app-laravel/resources/js/router/index.ts`:

Add import at top with other page imports:
```typescript
import ResultPage from '../pages/result/ResultPage.vue';
```

Add route entry in the `routes` array after the rag route:
```typescript
{ path: '/documents/:documentId/result', name: 'result', component: ResultPage, props: true, meta: { bareLayout: true } },
```

- [ ] **Step 4: Typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/pages/result/ResultPage.vue \
        apps/app-laravel/routes/web.php \
        apps/app-laravel/resources/js/router/index.ts
git commit -m "feat(esign): add /documents/{id}/result summary page with Word export + nav actions"
```

---

### Task 4: Redirect LawRelationsPage to result after step 5

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/law-relations/LawRelationsPage.vue`

- [ ] **Step 1: Change the post-step-5 navigation target**

In `apps/app-laravel/resources/js/pages/law-relations/LawRelationsPage.vue`, find the `saveAndNext` function (around line 124). It currently ends with:

```typescript
router.push(`/documents/${props.documentId}/permissions`);
```

Change it to:

```typescript
router.push(`/documents/${props.documentId}/result`);
```

- [ ] **Step 2: Typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/pages/law-relations/LawRelationsPage.vue
git commit -m "feat(esign): redirect to result page after completing step 5 (relations)"
```

---

### Task 5: Review lock — read-only editor after eSign export

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/review/ReviewPage.vue`
- Modify: `apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue`

- [ ] **Step 1: Fetch status in ReviewPage and pass locked prop**

Replace the entire contents of `apps/app-laravel/resources/js/pages/review/ReviewPage.vue` with:

```vue
<template>
  <div v-if="documentStore.loading" class="review-page-loading">
    <v-progress-circular indeterminate color="primary" />
    <p>กำลังโหลดเอกสาร...</p>
  </div>

  <div v-else-if="documentStore.error" class="review-page-error">
    <v-icon icon="mdi-alert-circle-outline" size="48" />
    <p>{{ documentStore.error }}</p>
    <v-btn variant="outlined" @click="reload">ลองใหม่</v-btn>
  </div>

  <DocumentEditorShell
    v-else-if="documentStore.review"
    :document-id="documentId"
    :locked="locked"
  />
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import DocumentEditorShell from '../../components/review/DocumentEditorShell.vue';
import { fetchStatus } from '../../api/client';
import { useDocumentStore } from '../../stores/documentStore';
import { useReviewUiStore } from '../../stores/reviewUiStore';
import type { DocumentStatus } from '../../types/document';

const props = defineProps<{
  documentId: string;
}>();

const documentStore = useDocumentStore();
const reviewUiStore = useReviewUiStore();
const docStatus = ref<DocumentStatus | null>(null);

const locked = computed(() => docStatus.value?.esign_exported_at != null);

onMounted(async () => {
  const [, status] = await Promise.all([
    documentStore.fetch(props.documentId),
    fetchStatus(props.documentId).catch(() => null),
  ]);
  docStatus.value = status;
});

onUnmounted(() => {
  documentStore.reset();
  reviewUiStore.reset();
});

async function reload(): Promise<void> {
  const [, status] = await Promise.all([
    documentStore.fetch(props.documentId),
    fetchStatus(props.documentId).catch(() => null),
  ]);
  docStatus.value = status;
}
</script>

<style scoped>
.review-page-loading,
.review-page-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  gap: 16px;
  background: #f5f5f5;
}

.review-page-error {
  color: #d97706;

  p {
    font-size: 16px;
  }
}
</style>
```

- [ ] **Step 2: Add `locked` prop + banner + non-editable mode to DocumentEditorShell**

In `apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue`:

**2a.** Change the `defineProps` call (around line 143) from:

```typescript
const props = defineProps<{ documentId: string }>();
```

to:

```typescript
const props = defineProps<{ documentId: string; locked?: boolean }>();
```

**2b.** After `const editor = useEditor({...})` block (around line 184), add a watchEffect that syncs the editable state:

```typescript
import { watchEffect } from 'vue';
```

(Add `watchEffect` to the existing vue import at line 119: `import { computed, onBeforeUnmount, watchEffect } from 'vue';`)

Then after the `editor` declaration, add:

```typescript
watchEffect(() => {
  if (editor.value) {
    editor.value.setEditable(!(props.locked ?? false));
  }
});
```

**2c.** In the template, after the `<WorkflowStepper :step="2" />` line (around line 17), add the lock banner:

```vue
<v-alert
  v-if="props.locked"
  type="warning"
  variant="tonal"
  density="compact"
  class="mx-0 my-2"
  style="flex-shrink:0"
  prepend-icon="mdi-lock-outline"
>
  เอกสารนี้ผ่านขั้นตอน e-Sign แล้ว — ไม่สามารถแก้ไขเนื้อหาได้
</v-alert>
```

**2d.** Hide the toolbar when locked. Find the toolbar `<div v-if="editor"` (around line 20) and change to:

```vue
<div v-if="editor && !props.locked" class="d-flex flex-wrap align-center ga-1 pa-3 mt-2 bg-white rounded-lg" style="border:1px solid #e2e8f0; flex-shrink:0">
```

- [ ] **Step 3: Typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 4: Run full PHP test suite**

```bash
docker compose exec laravel-app php artisan test
```

Expected: same pass count as before plus the 2 new WordExport tests.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/pages/review/ReviewPage.vue \
        apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue
git commit -m "feat(esign): lock review editor read-only after esign export; show warning banner"
```

---

## Self-review

**Spec coverage:**
- [x] Export as Word for eSign → `WordExportController` (Task 1)
- [x] Download as Word → DOCX file-download response in Task 1; blob-download in `downloadWordExport` (Task 2)
- [x] Record the eSign event → `esign_exported_at` stamped in status (Task 1)
- [x] Result/summary page → `ResultPage.vue` at `/documents/{id}/result` (Task 3)
- [x] Back to preview/edit (RAG and above only) → nav buttons on result page go to `/rag`, `/law-info`, `/relations` (Task 3)
- [x] Review page text editing disabled after eSign → `locked` prop + `setEditable(false)` + toolbar hidden (Task 5)
- [x] Admin can still view private document → already fixed in SP-A (LawDocumentView)
- [x] Relations page redirects to result after completing step 5 → Task 4

**Placeholder scan:** No TBDs or stubs. Every step has complete code.

**Type consistency:**
- `esign_exported_at` added to `DocumentStatus` (Task 2) → read as `docStatus.value?.esign_exported_at` in Task 5 ✓
- `downloadWordExport` defined in Task 2 → imported in `ResultPage.vue` (Task 3) ✓
- `locked` prop typed as `boolean | undefined` in Task 5 → passed from `ReviewPage` computed ✓
- `fetchStatus` already in `api/client.ts` — no new definition needed, used in Tasks 3 and 5 ✓
