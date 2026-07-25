# Upload: File-List Card as Confirm Dialog — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On `/admin/upload`, after the user picks/drops files, open a **dialog** containing the full file-list card (per-file OCR mode + add/remove), where the user reviews and clicks **อัปโหลด** to run the upload. The drop zone stays on the page; selected files **persist** if the dialog is closed and reopened (cleared only by explicit ยกเลิก/clearAll or a successful upload).

**Architecture:** Pure frontend restructure of `AdminUploadPage.vue`. The inline Step-1 cards and the old summary confirm-dialog are replaced by a single `uploadDialog` holding the existing file-list markup. There is now ONE `adm-drop__input` (in the always-visible drop zone, `ref=fileInputEl`); both "เลือกไฟล์จากเครื่อง" and the dialog's "เพิ่มไฟล์" click it, so no duplicate input / no ref collision. `pendingItems` is component state, so it survives dialog open/close for free.

**Tech Stack:** Vue 3 + Vuetify 3 + TypeScript.

**Verification:** frontend has no JS test runner — verify with `npm run typecheck`, `npm run build`, and a manual check.

---

### Task 1: Script — dialog state, open-on-add, close-on-success

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue` (`<script setup>`, lines ~236, ~316-359)

- [ ] **Step 1: Rename the dialog ref**

Replace line 236:

```ts
const confirmDialog = ref(false);
```

with:

```ts
const uploadDialog = ref(false);
```

- [ ] **Step 2: Open the dialog whenever files are added**

Replace `onInputChange` and `onDrop` (lines 316-325):

```ts
function onInputChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  if (input.files?.length) addFiles(input.files);
  input.value = '';
  if (pendingItems.value.length) uploadDialog.value = true;
}

function onDrop(event: DragEvent): void {
  dragOver.value = false;
  if (event.dataTransfer?.files.length) addFiles(event.dataTransfer.files);
  if (pendingItems.value.length) uploadDialog.value = true;
}
```

- [ ] **Step 3: Remove the old summary wrapper and close the dialog on success**

Delete `confirmAndUpload` entirely (lines 327-330):

```ts
async function confirmAndUpload(): Promise<void> {
  confirmDialog.value = false;
  await uploadAll();
}
```

Then, in `uploadAll`, close the dialog when the queue clears on success. Replace the success block (lines 354-358):

```ts
  const failed = pendingItems.value.filter(i => i.error).length;
  if (!failed) {
    snackbar.success?.(`อัปโหลดสำเร็จ ${toUpload.length} ไฟล์`);
    pendingItems.value = [];
    uploadDialog.value = false;
  }
```

- [ ] **Step 4: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: FAIL — template still references `confirmDialog` / `confirmAndUpload` (fixed in Task 2). This confirms the rename is complete in the script; proceed to Task 2 before re-running.

---

### Task 2: Template — always-on drop zone, reopen bar, file-list dialog

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue` (`<template>`, lines 8-210)

- [ ] **Step 1: Always show the drop zone + add a reopen bar**

Replace the Step-0 block (lines 10-40, the `<template v-if="!pendingItems.length">` … drop zone … `</template>`) with an always-visible drop zone followed by a reopen bar:

```vue
      <!-- Drop zone (always visible) -->
      <div
        class="adm-drop"
        :class="{ 'adm-drop--over': dragOver }"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="onDrop"
      >
        <input
          ref="fileInputEl"
          type="file"
          accept=".pdf,.doc,.docx"
          multiple
          class="adm-drop__input"
          @change="onInputChange"
        />
        <div class="adm-drop__icon">
          <v-icon icon="mdi-cloud-upload-outline" size="30" color="white" />
        </div>
        <p class="adm-drop__title">ลากและวางไฟล์ข้อมูลกฎหมาย</p>
        <p class="adm-drop__sub">รองรับไฟล์ .PDF หรือ .DOCX (เลือกได้หลายไฟล์พร้อมกัน)</p>
        <div class="adm-drop__btns">
          <button type="button" class="adm-btn adm-btn--primary" @click="fileInputEl?.click()">
            เลือกไฟล์จากเครื่อง
          </button>
        </div>
      </div>

      <!-- Reopen bar: files chosen but dialog closed -->
      <div v-if="pendingItems.length && !uploadDialog" class="adm-review-bar">
        <span class="adm-review-bar__text">เลือกไว้ {{ pendingItems.length }} ไฟล์</span>
        <button type="button" class="adm-btn adm-btn--primary" @click="uploadDialog = true">
          ตรวจสอบและอัปโหลด
        </button>
      </div>
```

- [ ] **Step 2: Delete the inline Step-1 block**

Delete the entire `<template v-else>` … `</template>` block that held the two `adm-card mb-4` cards (originally lines 42-164). Its file-list markup is re-created inside the dialog in Step 3.

- [ ] **Step 3: Replace the old confirm dialog with the file-list dialog**

Replace the old confirm `<v-dialog v-model="confirmDialog" …> … </v-dialog>` (originally lines 166-206) with:

```vue
      <!-- ── File-list / upload dialog ─────────────────────── -->
      <v-dialog v-model="uploadDialog" max-width="640" persistent scrollable>
        <v-card rounded="xl">
          <div class="adm-card__head-row pa-5 pb-2">
            <h3 class="adm-card__head">ไฟล์เอกสาร ({{ pendingItems.length }} ไฟล์)</h3>
            <button type="button" class="adm-btn-sm adm-btn-sm--outline" @click="fileInputEl?.click()">
              <v-icon icon="mdi-plus" size="14" />
              เพิ่มไฟล์
            </button>
          </div>

          <v-card-text class="px-5 py-2">
            <div class="adm-file-list">
              <div v-for="(item, i) in pendingItems" :key="i" class="adm-file-item">
                <div class="adm-file-row">
                  <div class="adm-file-row__icon">
                    <v-icon :icon="iconFor(item.file)" size="20" color="white" />
                  </div>
                  <div class="adm-file-row__body">
                    <span class="adm-file-row__name">{{ item.file.name }}</span>
                    <span class="adm-file-row__meta">{{ sizeOf(item.file) }}</span>
                  </div>
                  <v-chip v-if="item.done" color="success" size="small" variant="flat" class="mr-2">
                    <v-icon start icon="mdi-check" size="13" />อัปโหลดแล้ว
                  </v-chip>
                  <v-progress-circular
                    v-else-if="item.uploading"
                    indeterminate size="18" width="2" color="white" class="mr-2"
                  />
                  <button
                    v-if="!item.uploading && !item.done"
                    type="button"
                    class="adm-file-row__remove"
                    @click="removeItem(i)"
                  >
                    <v-icon icon="mdi-close" size="16" />
                  </button>
                </div>

                <div v-if="!item.done" class="adm-file-ocr">
                  <span class="adm-label-sm">โหมด:</span>
                  <v-select
                    v-model="item.scanMode"
                    :items="modeOptionsFor(item.file)"
                    item-title="title"
                    item-value="value"
                    density="compact"
                    variant="outlined"
                    hide-details
                    rounded="lg"
                    style="flex:1;max-width:420px"
                  />
                  <span v-if="hintFor(item)" class="adm-hint">{{ hintFor(item) }}</span>
                </div>

                <v-alert v-if="item.error" type="error" density="compact" rounded="lg" class="mt-2">
                  {{ item.error }}
                </v-alert>
              </div>

              <div v-if="!pendingItems.length" class="text-center pa-6 text-medium-emphasis">
                ยังไม่มีไฟล์ที่เลือก
              </div>
            </div>
          </v-card-text>

          <v-divider />
          <v-card-actions class="pa-4 gap-2 justify-end">
            <button type="button" class="adm-btn adm-btn--ghost" style="font-size:15px;padding:8px 20px"
              :disabled="isUploading" @click="uploadDialog = false">
              ปิด
            </button>
            <button type="button" class="adm-btn adm-btn--ghost" style="font-size:15px;padding:8px 20px"
              :disabled="isUploading || !pendingItems.length" @click="clearAll">
              ล้างทั้งหมด
            </button>
            <button type="button" class="adm-btn adm-btn--primary" style="font-size:15px;padding:8px 20px"
              :disabled="isUploading || allDone || !pendingCount" @click="uploadAll">
              <v-icon icon="mdi-cloud-upload-outline" size="16" class="mr-1" />
              {{ `อัปโหลด ${pendingCount} ไฟล์` }}
            </button>
          </v-card-actions>
        </v-card>
      </v-dialog>
```

- [ ] **Step 4: Add the reopen-bar style**

In `<style scoped>` add:

```css
.adm-review-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 16px;
  padding: 12px 16px;
  background: #eef2ff;
  border: 1px solid #c7d2fe;
  border-radius: 12px;
}
.adm-review-bar__text {
  font-weight: 600;
  color: #1e3a8a;
}
```

- [ ] **Step 5: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS. (No remaining `confirmDialog` / `confirmAndUpload` references.)

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue
git commit -m "feat(upload): move file-list card into a confirm dialog; drop zone always visible"
```

---

### Task 3: Verify behavior

**Files:** none (verification only)

- [ ] **Step 1: Manual check**

Open `http://localhost:8000/admin/upload`. Confirm:
- The drop zone is always visible. Selecting or dropping files opens the **dialog** with the file list + per-file OCR mode.
- "เพิ่มไฟล์" in the dialog opens the OS file picker and adds to the same list.
- Clicking **ปิด** closes the dialog WITHOUT losing files; the "เลือกไว้ N ไฟล์ — ตรวจสอบและอัปโหลด" bar appears and reopens the dialog with the same files.
- **อัปโหลด N ไฟล์** uploads (per-row spinner → อัปโหลดแล้ว); on full success the queue clears and the dialog closes; the pipeline table refreshes.
- **ล้างทั้งหมด** empties the list.

- [ ] **Step 2: Commit (only if manual check required tweaks)**

```bash
git add -A && git commit -m "fix(upload): dialog polish after manual check"
```

---

## Self-Review

- **Spec coverage:** file-list card shown as dialog (Task 2 Step 3), user confirms then uploads from the dialog (Task 2 Step 3 อัปโหลด → `uploadAll`), files persist on close/reopen (component `pendingItems` never cleared on close; reopen bar Task 2 Step 1). ✓
- **Placeholders:** none — full markup/code given; deletions reference the exact original blocks. ✓
- **Type consistency:** `uploadDialog` defined in Task 1 Step 1 and used in Task 2 Steps 1,3; `confirmDialog`/`confirmAndUpload` fully removed (Task 1 Steps 1,3); `uploadAll`, `clearAll`, `removeItem`, `fileInputEl`, `pendingCount`, `allDone`, `isUploading` all pre-existing and reused. ✓
- **Single input:** only the drop-zone `adm-drop__input` remains (`ref=fileInputEl`); the dialog's เพิ่มไฟล์ reuses it — no duplicate input / ref collision. ✓
