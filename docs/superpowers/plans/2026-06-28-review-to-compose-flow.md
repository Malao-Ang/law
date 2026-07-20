# Review → Compose UI Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After extraction completes, automatically redirect users to the ReviewPage (TipTap rich-text editor); once they finish editing they click "Save & Continue to Compose" to navigate to ComposePage.

**Architecture:** Two targeted edits — `UploadPage.vue` gains an auto-redirect when the status poll detects `done`; `DocumentEditorShell.vue` gains a "Save & Continue" button that saves via the existing `saveDocument()` path and then pushes to the compose route. No new components, no new API calls, no schema changes.

**Tech Stack:** Vue 3 · Vuetify 4 · TipTap 2 · Vue Router 4

**Spec:** Agreed in conversation 2026-06-28 — Option A navigation, auto-redirect after extraction.

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `apps/app-laravel/resources/js/pages/UploadPage.vue` | Auto-redirect to ReviewPage on extraction success | Modify |
| `apps/app-laravel/resources/js/components/DocumentEditorShell.vue` | Add "Save & Continue to Compose" button | Modify |

No other files change.

---

## Task 1: Auto-redirect to ReviewPage after extraction completes

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/UploadPage.vue`

**Why:** Currently `pollStatus()` stops polling when extraction is done and shows two manual buttons. The new flow requires the user to go through ReviewPage first — so we auto-redirect there the moment status becomes `done` / `exported` / `ingested`, removing the need for manual clicks.

- [ ] **Step 1: Open `UploadPage.vue` and locate `pollStatus()`**

The function is at lines 146–158. The relevant block is the inner `if` that stops polling:

```typescript
if (['queued', 'processing', 'ingesting'].includes(status.value.status)) {
  pollTimer = setTimeout(pollStatus, 1500);
}
```

- [ ] **Step 2: Replace `pollStatus()` with the auto-redirect version**

Replace the entire `pollStatus` function (lines 146–158) with:

```typescript
async function pollStatus(): Promise<void> {
  if (!documentId.value) return;

  try {
    status.value = await fetchStatus(documentId.value);

    if (['queued', 'processing', 'ingesting'].includes(status.value.status)) {
      pollTimer = setTimeout(pollStatus, 1500);
    } else if (['done', 'exported', 'ingested'].includes(status.value.status)) {
      router.push(`/documents/${documentId.value}/review`);
    }
  } catch {
    pollTimer = setTimeout(pollStatus, 2000);
  }
}
```

- [ ] **Step 3: Remove the now-unused manual button block from the template**

Remove lines 50–64 (the `<div v-if="canOpenReview" …>` block with the two buttons):

```html
<!-- DELETE THIS ENTIRE BLOCK -->
<div v-if="canOpenReview" class="upload-actions">
  <v-btn color="success" @click="goToReview">ตรวจสอบเอกสาร</v-btn>
  <v-btn color="primary" variant="tonal" @click="goToCompose">เปิด Compose Editor</v-btn>
</div>
```

- [ ] **Step 4: Remove the now-unused script helpers**

In the `<script setup>` block, delete:
- The `canOpenReview` computed ref (lines 85–87)
- The `goToReview()` function (lines 160–163)
- The `goToCompose()` function (lines 165–168)

Also delete the `upload-actions` CSS rule in `<style scoped>` (lines 172–176).

- [ ] **Step 5: Verify the final `UploadPage.vue` looks like this**

```vue
<template>
  <HeaderComponent
    title="อัปโหลดเอกสาร"
    :breadcrumbs="[
      { text: 'หน้าแรก', to: '/' },
      { text: 'อัปโหลดเอกสาร' }
    ]"
  />

  <v-main>
    <v-container class="py-8">
      <v-row>
        <v-col cols="12">
          <UploadForm @uploaded="onUploaded" />
        </v-col>
      </v-row>

      <v-row v-if="status">
        <v-col cols="12">
          <v-card>
            <v-card-title>สถานะการประมวลผลเอกสาร</v-card-title>
            <v-card-text>
              <v-chip :color="getStatusColor(status.status)" class="mb-4">
                {{ getStatusText(status.status) }}
              </v-chip>

              <v-progress-linear
                v-if="['queued', 'processing', 'ingesting'].includes(status.status)"
                indeterminate
                color="primary"
                class="mb-4"
              ></v-progress-linear>

              <p v-if="status.scan_extraction_mode_requested" class="text-body-2 mb-1">
                Scan mode requested: {{ status.scan_extraction_mode_requested }}
              </p>
              <p v-if="status.scan_extraction_mode_effective" class="text-body-2 mb-4">
                Scan mode effective: {{ status.scan_extraction_mode_effective }}
              </p>
              <p v-if="status.extraction_path?.length" class="text-body-2 mb-1">
                Extraction path: {{ status.extraction_path.join(' -> ') }}
              </p>
              <p v-if="status.conversion" class="text-body-2 mb-1">
                Converted from .doc via {{ status.conversion.tool }} ({{ formatDuration(status.conversion.duration_ms) }})
              </p>
              <p v-if="status.timings" class="text-body-2 mb-4">
                Timings: {{ formatTimings(status.timings) }}
              </p>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import UploadForm from '../components/UploadForm.vue';
import HeaderComponent from '../components/HeaderComponent.vue';
import { fetchStatus } from '../api/client';
import type { DocumentStatus } from '../types/document';

const router = useRouter();
const documentId = ref<string | null>(null);
const status = ref<DocumentStatus | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

function getStatusColor(s: string): string {
  switch (s) {
    case 'done': case 'exported': case 'ingested': return 'success';
    case 'processing': case 'ingesting': return 'warning';
    case 'queued': return 'info';
    default: return 'error';
  }
}

function getStatusText(s: string): string {
  switch (s) {
    case 'queued': return 'รอดำเนินการ';
    case 'processing': return 'กำลังประมวลผล';
    case 'ingesting': return 'กำลังนำเข้าระบบ';
    case 'done': return 'เสร็จสิ้น';
    case 'exported': return 'ส่งออกแล้ว';
    case 'ingested': return 'นำเข้าระบบแล้ว';
    case 'failed': return 'ล้มเหลว';
    default: return s;
  }
}

function formatTimings(timings: Record<string, number>): string {
  return Object.entries(timings).map(([k, v]) => `${k}=${v}ms`).join(', ');
}

function formatDuration(ms?: number | null): string {
  if (!ms || ms <= 0) return '-';
  return `${(ms / 1000).toFixed(1)}s`;
}

function onUploaded(id: string): void {
  documentId.value = id;
  status.value = null;
  pollStatus();
}

async function pollStatus(): Promise<void> {
  if (!documentId.value) return;
  try {
    status.value = await fetchStatus(documentId.value);
    if (['queued', 'processing', 'ingesting'].includes(status.value.status)) {
      pollTimer = setTimeout(pollStatus, 1500);
    } else if (['done', 'exported', 'ingested'].includes(status.value.status)) {
      router.push(`/documents/${documentId.value}/review`);
    }
  } catch {
    pollTimer = setTimeout(pollStatus, 2000);
  }
}
</script>
```

- [ ] **Step 6: Test in the browser**

1. Start dev server: `npm run dev` (inside `apps/app-laravel/` on the host, or via Vite container at `http://localhost:5173`)
2. Upload a `.docx` or `.pdf` file
3. Watch the progress indicator appear
4. Verify the page **automatically navigates** to `/documents/{id}/review` once extraction finishes — no button click required
5. Verify the ReviewPage shows the TipTap editor immediately in edit mode

- [ ] **Step 7: Commit**

```bash
git add apps/app-laravel/resources/js/pages/UploadPage.vue
git commit -m "feat(upload): auto-redirect to ReviewPage after extraction completes

Removes the manual 'Open Review' and 'Open Compose' buttons from the
upload status card. When the extraction poll detects done/exported/
ingested, the router immediately navigates to the ReviewPage so the
user lands in the TipTap editor without an extra click.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 2: Add "Save & Continue to Compose" button to DocumentEditorShell

**Files:**
- Modify: `apps/app-laravel/resources/js/components/DocumentEditorShell.vue`

**Why:** ReviewPage is now the first stop after upload. Users need an explicit exit from the editor that (a) saves their work and (b) takes them to ComposePage to arrange section order.

- [ ] **Step 1: Add `useRouter` import**

In the `<script setup>` block, `useRouter` is not yet imported. Add it to the Vue Router import line at the top of the script (currently there is no vue-router import — add it):

```typescript
import { useRouter } from 'vue-router';
```

Place this after the existing Vue imports (`computed`, `onBeforeUnmount`, `ref`, `watch`) and before the TipTap imports.

- [ ] **Step 2: Instantiate the router**

In the `<script setup>` block, immediately after the `props` and `emit` declarations (around line 87), add:

```typescript
const router = useRouter();
```

- [ ] **Step 3: Add `saveAndContinue()` function**

Add this function right after the existing `saveDocument()` function (after line 167):

```typescript
async function saveAndContinue(): Promise<void> {
  await saveDocument();
  if (!error.value) {
    router.push(`/documents/${props.documentId}/compose`);
  }
}
```

- [ ] **Step 4: Add the "Save & Continue" button to the header template**

In the `<template>`, inside `.editor-shell-actions` (the `<div>` that currently holds the Preview, Edit, and Save buttons), add the new button **after** the existing save button:

```html
<v-btn
  size="small"
  color="primary"
  :disabled="saving"
  :loading="saving"
  prepend-icon="mdi-arrow-right-circle-outline"
  @click="saveAndContinue"
>
  บันทึกและไปที่ Compose
</v-btn>
```

The full `.editor-shell-actions` block should now be:

```html
<div class="editor-shell-actions">
  <v-btn
    v-if="mode === 'edit'"
    size="small"
    variant="tonal"
    color="primary"
    prepend-icon="mdi-eye-outline"
    @click="switchMode('preview')"
  >
    ดูตัวอย่าง
  </v-btn>
  <v-btn
    v-if="mode === 'preview'"
    size="small"
    variant="tonal"
    prepend-icon="mdi-pencil-outline"
    @click="switchMode('edit')"
  >
    แก้ไขข้อมูล
  </v-btn>
  <v-btn
    size="small"
    variant="tonal"
    :disabled="!isDirty || saving"
    prepend-icon="mdi-content-save-outline"
    @click="saveDocument"
  >
    {{ saving ? 'บันทึก...' : 'บันทึก' }}
  </v-btn>
  <v-btn
    size="small"
    color="primary"
    :disabled="saving"
    :loading="saving"
    prepend-icon="mdi-arrow-right-circle-outline"
    @click="saveAndContinue"
  >
    บันทึกและไปที่ Compose
  </v-btn>
</div>
```

- [ ] **Step 5: Test in the browser**

1. Navigate to an existing document's ReviewPage: `http://localhost:8000/documents/{id}/review`
2. Make any edit in the TipTap editor
3. Click **"บันทึกและไปที่ Compose"**
4. Verify the page navigates to `/documents/{id}/compose`
5. If there are unsaved changes, verify the save completes before navigation (no data loss)
6. If save fails (network error), verify the user stays on ReviewPage and sees the error message — they are NOT redirected

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/resources/js/components/DocumentEditorShell.vue
git commit -m "feat(review): add Save & Continue to Compose button

Adds a primary action button to DocumentEditorShell that saves the
current TipTap draft and then navigates to the compose route. Navigation
is blocked if the save throws an error so the user is never silently
redirected away from unsaved work.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Self-Review

**1. Spec coverage:**

| Requirement | Task |
|---|---|
| After extraction → land on ReviewPage automatically | Task 1 (auto-redirect in `pollStatus`) |
| ReviewPage shows TipTap rich-text editor | Already exists in `DocumentEditorShell` — no change needed |
| "Save & Continue to Compose" button | Task 2 |
| ComposePage shows after save for section ordering | Already exists — no change needed |
| Navigation blocked if save fails | Task 2 (`if (!error.value)` guard) |

No spec requirement is unimplemented.

**2. Placeholder scan:** No "TBD", "TODO", or vague steps. Every code block shows the complete content to write.

**3. Type consistency:** `saveAndContinue()` calls `saveDocument()` (same function, already defined). `router.push(...)` uses the same URL pattern as the existing `goToReview()` helper. `props.documentId` is the same string prop used by `saveDocumentReview()` throughout the file.
