# Admin Upload Flow Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 3-screen admin import flow so Upload → `/review` (whole-doc TipTap editor) → `/rag` (read-only RAG block manager) → `/preview`, retiring the per-block compose flow.

**Architecture:** Simplify `UploadForm` and `AdminUploadPage`; extend `DocumentEditorShell` with a 3-row TipTap toolbar (new `FontSizeExtension`); create `RagPage` + `RagManageWorkspace` that reuse `composeStore` and `blockStore`; wire the `/rag` route. Stores unchanged.

**Tech Stack:** Vue 3 `<script setup>`, Pinia 2, TypeScript strict, Vuetify 3, TipTap 2 (`@tiptap/vue-3`), MDI icons.

---

## File Map

| Action | Path |
|--------|------|
| Create | `apps/app-laravel/resources/js/extensions/FontSizeExtension.ts` |
| Modify | `apps/app-laravel/resources/js/components/shared/UploadForm.vue` |
| Modify | `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue` |
| Modify | `apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue` |
| Create | `apps/app-laravel/resources/js/pages/rag/RagPage.vue` |
| Create | `apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue` |
| Modify | `apps/app-laravel/resources/js/router/index.ts` |

---

## Task 1: Install TipTap extensions

**Files:**
- Modify: `apps/app-laravel/package.json` (via npm)

- [ ] **Step 1: Install the 3 missing extensions**

`@tiptap/extension-text-align` is already installed. Only run:

```bash
cd apps/app-laravel && npm install @tiptap/extension-heading @tiptap/extension-highlight @tiptap/extension-text-style
```

Expected: installs `^2.x.x` versions, `package-lock.json` updated.

- [ ] **Step 2: Verify typecheck still passes**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors (packages not imported yet, no breakage).

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/package.json apps/app-laravel/package-lock.json
git commit -m "deps(frontend): add tiptap heading/highlight/text-style extensions"
```

---

## Task 2: Create FontSizeExtension

**Files:**
- Create: `apps/app-laravel/resources/js/extensions/FontSizeExtension.ts`

This extends TipTap's `TextStyle` mark to carry a `fontSize` attribute and expose `setFontSize`/`unsetFontSize` commands. It replaces `TextStyle` in the editor setup — do NOT also import `TextStyle` separately.

- [ ] **Step 1: Create the file**

`apps/app-laravel/resources/js/extensions/FontSizeExtension.ts`:

```ts
import TextStyle from '@tiptap/extension-text-style';

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    fontSize: {
      setFontSize: (size: string) => ReturnType;
      unsetFontSize: () => ReturnType;
    };
  }
}

export const FontSizeExtension = TextStyle.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      fontSize: {
        default: null,
        parseHTML: (element) => element.style.fontSize || null,
        renderHTML: (attributes) =>
          attributes.fontSize ? { style: `font-size: ${attributes.fontSize}` } : {},
      },
    };
  },

  addCommands() {
    return {
      ...this.parent?.(),
      setFontSize:
        (size: string) =>
        ({ commands }) =>
          commands.updateAttributes('textStyle', { fontSize: size }),
      unsetFontSize:
        () =>
        ({ commands }) =>
          commands.updateAttributes('textStyle', { fontSize: null }),
    };
  },
});
```

- [ ] **Step 2: Verify typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/extensions/FontSizeExtension.ts
git commit -m "feat(extensions): add FontSizeExtension extending TipTap TextStyle"
```

---

## Task 3: Simplify UploadForm.vue

**Files:**
- Modify: `apps/app-laravel/resources/js/components/shared/UploadForm.vue`

Remove: `v-card` wrapper, both `v-select` controls (engine + scan mode), the `v-chip` selected indicator, `onFileChange` event handler. Keep: hidden `extractionEngine` and `scanExtractionMode` refs (still passed to the API). Change: clicking "เลือกไฟล์จากเครื่อง" opens the hidden file input and auto-submits on selection. `AdminUploadPage` provides the visual card context.

- [ ] **Step 1: Replace the file entirely**

`apps/app-laravel/resources/js/components/shared/UploadForm.vue`:

```vue
<template>
  <div class="upload-form">
    <input
      ref="fileInput"
      type="file"
      accept=".doc,.docx,.pdf"
      class="upload-form__hidden-input"
      @change="onFileSelected"
    />
    <div class="upload-form__actions">
      <v-btn
        color="#1a3673"
        size="large"
        :loading="loading"
        @click="fileInput?.click()"
      >
        เลือกไฟล์จากเครื่อง
      </v-btn>
      <v-btn
        variant="outlined"
        size="large"
        :disabled="loading"
        @click="reset"
      >
        ยกเลิก
      </v-btn>
    </div>
    <p v-if="selectedFileName" class="upload-form__filename">
      ไฟล์ที่เลือก: {{ selectedFileName }}
    </p>
    <p v-if="error" class="upload-form__error">{{ error }}</p>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useUploadStore } from '../../stores/uploadStore';

const emit = defineEmits<{
  uploaded: [documentId: string];
}>();

const uploadStore = useUploadStore();

// Hidden defaults — still sent to API, not shown in UI
const extractionEngine = ref<'standard' | 'fast'>('standard');
const scanExtractionMode = ref<'auto' | 'local' | 'landingai'>('auto');

const fileInput = ref<HTMLInputElement | null>(null);
const selectedFileName = ref<string | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function onFileSelected(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null;
  if (!file) return;
  selectedFileName.value = file.name;
  error.value = null;
  loading.value = true;
  try {
    const documentId = await uploadStore.upload(
      file,
      scanExtractionMode.value,
      extractionEngine.value,
    );
    emit('uploaded', documentId);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'อัปโหลดไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

function reset(): void {
  selectedFileName.value = null;
  error.value = null;
  if (fileInput.value) fileInput.value.value = '';
}
</script>

<style scoped>
.upload-form__hidden-input {
  display: none;
}

.upload-form__actions {
  display: flex;
  gap: 12px;
  justify-content: center;
  flex-wrap: wrap;
}

.upload-form__filename {
  font-size: 13px;
  color: #64748b;
  margin-top: 8px;
  text-align: center;
}

.upload-form__error {
  font-size: 13px;
  color: #ef4444;
  margin-top: 8px;
  text-align: center;
}
</style>
```

- [ ] **Step 2: Verify typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/components/shared/UploadForm.vue
git commit -m "feat(upload): simplify UploadForm — remove selectors, auto-upload on file select"
```

---

## Task 4: Redesign AdminUploadPage.vue

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue`

Remove: right `<aside>` panel, CSS grid layout, `goToView`/`goToEdit` buttons, `isDone` computed, `formatDuration`. Change: single-column centered layout, auto-redirect to `/documents/:id/review` 1 s after status reaches `'done'` or `'exported'`. The status card stays to show progress.

- [ ] **Step 1: Replace the file entirely**

`apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue`:

```vue
<template>
  <LawspaceShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล']"
    title="การนำเข้าเอกสารกฎหมาย"
    subtitle="อัปโหลดไฟล์เพื่อเตรียมสกัดเนื้อหาเข้าสู่ระบบฐานข้อมูล"
  >
    <div class="admin-upload">
      <div class="admin-upload__drop-card">
        <span class="mdi mdi-cloud-upload-outline admin-upload__drop-icon"></span>
        <h3 class="admin-upload__drop-title">ลากและวางไฟล์ข้อมูลกฎหมาย</h3>
        <p class="admin-upload__drop-sub">รองรับไฟล์ .PDF หรือ .DOCX</p>
        <UploadForm @uploaded="onUploaded" />
      </div>

      <transition name="fade">
        <div v-if="uploadStore.status" class="admin-upload__status-card">
          <div class="admin-upload__status-header">
            <span class="mdi mdi-file-document-outline"></span>
            <span class="admin-upload__status-filename">{{ uploadStore.status.source_file ?? 'เอกสาร' }}</span>
            <span
              class="admin-upload__status-chip"
              :class="`admin-upload__status-chip--${uploadStore.status.status}`"
            >
              {{ statusLabel(uploadStore.status.status) }}
            </span>
          </div>
          <v-progress-linear
            v-if="isProcessing"
            indeterminate
            color="primary"
            class="admin-upload__progress"
          />
          <p v-if="uploadStore.status.current_step" class="admin-upload__step-label">
            {{ uploadStore.status.current_step }}
          </p>
        </div>
      </transition>
    </div>
  </LawspaceShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useUploadStore } from '../../stores/uploadStore';
import LawspaceShell from '../../components/shared/LawspaceShell.vue';
import UploadForm from '../../components/shared/UploadForm.vue';

const router = useRouter();
const uploadStore = useUploadStore();

let documentId: string | null = null;
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const isProcessing = computed(() =>
  ['queued', 'processing', 'ingesting'].includes(uploadStore.status?.status ?? ''),
);

onBeforeUnmount(() => {
  if (pollTimer) clearTimeout(pollTimer);
  uploadStore.reset();
});

function statusLabel(s: string): string {
  const map: Record<string, string> = {
    queued: 'รอดำเนินการ',
    processing: 'กำลังประมวลผล',
    ingesting: 'กำลังนำเข้าระบบ',
    done: 'เสร็จสิ้น',
    exported: 'ส่งออกแล้ว',
    ingested: 'นำเข้าแล้ว',
    failed: 'ล้มเหลว',
  };
  return map[s] ?? s;
}

function onUploaded(id: string): void {
  documentId = id;
  uploadStore.reset();
  pollStatus();
}

async function pollStatus(): Promise<void> {
  if (!documentId) return;
  const result = await uploadStore.pollOnce(documentId);
  if (result && ['queued', 'processing', 'ingesting'].includes(result.status)) {
    pollTimer = setTimeout(pollStatus, 1500);
  } else if (!result) {
    pollTimer = setTimeout(pollStatus, 2000);
  } else if (result.status === 'done' || result.status === 'exported') {
    // Brief pause to show "done" chip, then auto-redirect
    pollTimer = setTimeout(() => {
      router.push(`/documents/${documentId!}/review`);
    }, 1000);
  }
}
</script>

<style scoped>
.admin-upload {
  max-width: 600px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.admin-upload__drop-card {
  border: 2px dashed var(--law-border);
  border-radius: 12px;
  padding: 40px 32px;
  text-align: center;
  background: var(--law-surface);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.admin-upload__drop-icon {
  font-size: 56px;
  color: var(--law-border);
}

.admin-upload__drop-title {
  font-size: 18px;
  font-weight: 700;
  color: var(--elaw-navy);
  margin: 8px 0 0;
}

.admin-upload__drop-sub {
  font-size: 13px;
  color: var(--elaw-muted);
  margin: 0 0 8px;
}

.admin-upload__status-card {
  border: 1px solid var(--law-border);
  border-radius: 10px;
  padding: 20px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.admin-upload__status-header {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 600;
}

.admin-upload__status-header .mdi {
  font-size: 20px;
  color: var(--law-primary);
}

.admin-upload__status-filename {
  flex: 1;
  font-size: 14px;
}

.admin-upload__status-chip {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 10px;
}

.admin-upload__status-chip--done,
.admin-upload__status-chip--exported,
.admin-upload__status-chip--ingested {
  color: #15803d;
  background: #dcfce7;
}

.admin-upload__status-chip--processing,
.admin-upload__status-chip--ingesting {
  color: #92400e;
  background: #fffbeb;
}

.admin-upload__status-chip--queued {
  color: #1d4ed8;
  background: #dbeafe;
}

.admin-upload__status-chip--failed {
  color: #b91c1c;
  background: #fee2e2;
}

.admin-upload__step-label {
  font-size: 12px;
  color: var(--elaw-muted);
  margin: 0;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
```

- [ ] **Step 2: Verify typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue
git commit -m "feat(admin): simplify upload page — remove sidebar, auto-redirect to review on done"
```

---

## Task 5: Redesign DocumentEditorShell.vue

**Files:**
- Modify: `apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue`

Remove: mode toggle (edit/preview), `switchMode`, `switchModeError`, `modeLabel`, `sanitizedHtml`, DOMPurify import, preview pane, `reviewUiStore.mode` watcher, "บันทึกและไปที่ Compose" button.

Add: yellow warning banner, 3-row toolbar (undo/redo, heading dropdown, font-size dropdown, bold/italic/underline/strike/highlight, alignment L/C/R/J, bullet/numbered/indent/outdent, auto-save dot), footer with char count + ยกเลิก + บันทึกข้อมูล.

Change: `saveAndContinue` navigates to `/documents/:id/rag`; editor always `editable: true`.

New extensions: `Heading`, `TextAlign`, `Highlight`, `FontSizeExtension` (replaces TextStyle), `IndentExtension`.

- [ ] **Step 1: Replace the file entirely**

`apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue`:

```vue
<template>
  <div class="editor-shell">
    <!-- Header -->
    <div class="editor-shell-header">
      <div>
        <h1 class="editor-shell-title">ตรวจทานเนื้อหาเอกสาร</h1>
        <p class="editor-shell-subtitle">อ่านทวน แก้ไข และจัดรูปแบบก่อนยืนยันนำเข้าระบบ</p>
      </div>
      <v-btn icon="mdi-close" variant="text" @click="router.back()" />
    </div>

    <!-- Warning banner -->
    <div class="review-warning-banner">
      <v-icon icon="mdi-alert-outline" size="16" color="#7b3306" />
      <span>กรุณาตรวจทานเนื้อหาให้ครบถ้วนก่อนยืนยัน เนื่องจากการแปลงไฟล์อัตโนมัติอาจมีความคลาดเคลื่อนในบางจุด</span>
    </div>

    <!-- Toolbar (only rendered after editor is ready) -->
    <div v-if="editor" class="editor-toolbar">
      <!-- Row 1: History + Heading -->
      <div class="toolbar-row">
        <span class="toolbar-label">ย้อนกลับ</span>
        <button
          class="toolbar-btn"
          :disabled="!editor.can().undo()"
          title="Undo"
          @click="editor.chain().focus().undo().run()"
        >
          <i class="mdi mdi-undo" />
        </button>
        <button
          class="toolbar-btn"
          :disabled="!editor.can().redo()"
          title="Redo"
          @click="editor.chain().focus().redo().run()"
        >
          <i class="mdi mdi-redo" />
        </button>
        <div class="toolbar-divider" />
        <span class="toolbar-label">หัวข้อ</span>
        <select class="toolbar-select" :value="activeHeadingLevel" @change="setHeading($event)">
          <option value="0">ย่อหน้า</option>
          <option value="1">หัวข้อ 1</option>
          <option value="2">หัวข้อ 2</option>
          <option value="3">หัวข้อ 3</option>
        </select>
      </div>

      <!-- Row 2: Font size + Format -->
      <div class="toolbar-row">
        <span class="toolbar-label">ขนาด</span>
        <select class="toolbar-select" @change="setFontSize($event)">
          <option v-for="s in [12, 14, 16, 18, 20]" :key="s" :value="`${s}pt`">{{ s }}</option>
        </select>
        <div class="toolbar-divider" />
        <span class="toolbar-label">รูปแบบ</span>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive('bold') }"
          @click="editor.chain().focus().toggleBold().run()"
        ><i class="mdi mdi-format-bold" /></button>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive('italic') }"
          @click="editor.chain().focus().toggleItalic().run()"
        ><i class="mdi mdi-format-italic" /></button>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive('underline') }"
          @click="editor.chain().focus().toggleUnderline().run()"
        ><i class="mdi mdi-format-underline" /></button>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive('strike') }"
          @click="editor.chain().focus().toggleStrike().run()"
        ><i class="mdi mdi-format-strikethrough" /></button>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive('highlight') }"
          @click="editor.chain().focus().toggleHighlight().run()"
        ><i class="mdi mdi-marker" /></button>
      </div>

      <!-- Row 3: Alignment + Lists + Auto-save indicator -->
      <div class="toolbar-row">
        <span class="toolbar-label">จัดข้อความ</span>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive({ textAlign: 'left' }) }"
          @click="editor.chain().focus().setTextAlign('left').run()"
        ><i class="mdi mdi-format-align-left" /></button>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive({ textAlign: 'center' }) }"
          @click="editor.chain().focus().setTextAlign('center').run()"
        ><i class="mdi mdi-format-align-center" /></button>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive({ textAlign: 'right' }) }"
          @click="editor.chain().focus().setTextAlign('right').run()"
        ><i class="mdi mdi-format-align-right" /></button>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive({ textAlign: 'justify' }) }"
          @click="editor.chain().focus().setTextAlign('justify').run()"
        ><i class="mdi mdi-format-align-justify" /></button>
        <div class="toolbar-divider" />
        <span class="toolbar-label">รายการ</span>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive('bulletList') }"
          @click="editor.chain().focus().toggleBulletList().run()"
        ><i class="mdi mdi-format-list-bulleted" /></button>
        <button
          class="toolbar-btn"
          :class="{ 'is-active': editor.isActive('orderedList') }"
          @click="editor.chain().focus().toggleOrderedList().run()"
        ><i class="mdi mdi-format-list-numbered" /></button>
        <button
          class="toolbar-btn"
          @click="editor.chain().focus().increaseIndent().run()"
        ><i class="mdi mdi-format-indent-increase" /></button>
        <button
          class="toolbar-btn"
          @click="editor.chain().focus().decreaseIndent().run()"
        ><i class="mdi mdi-format-indent-decrease" /></button>
        <div class="toolbar-divider" />
        <span class="toolbar-autosave">
          <i
            class="mdi"
            :class="reviewUiStore.isDirty ? 'mdi-circle-medium toolbar-autosave--dirty' : 'mdi-check-circle-outline toolbar-autosave--saved'"
          />
          บันทึกอัตโนมัติ
        </span>
      </div>
    </div>

    <!-- Editor area -->
    <div class="editor-shell-edit" @click.stop>
      <EditorContent v-if="editor" :editor="editor" class="editor-shell-content" />
    </div>

    <!-- Error bar -->
    <div v-if="documentStore.saveError" class="editor-shell-error">
      <v-icon icon="mdi-alert-circle-outline" size="20" />
      <span>{{ documentStore.saveError }}</span>
      <v-btn size="x-small" variant="text" @click="documentStore.setSaveError()">ปิด</v-btn>
    </div>

    <!-- Footer -->
    <div class="editor-shell-footer">
      <span class="editor-shell-footer__count">{{ charCount }} ตัวอักษร · บันทึกอัตโนมัติเมื่อปิด</span>
      <div class="editor-shell-footer__actions">
        <v-btn variant="outlined" @click="router.back()">ยกเลิก</v-btn>
        <v-btn color="#1c398e" :loading="documentStore.saving" @click="saveAndContinue">บันทึกข้อมูล</v-btn>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Heading from '@tiptap/extension-heading';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import { IndentExtension } from '../../extensions/IndentExtension';
import { FontSizeExtension } from '../../extensions/FontSizeExtension';
import { useDocumentStore } from '../../stores/documentStore';
import { useReviewUiStore } from '../../stores/reviewUiStore';

const props = defineProps<{ documentId: string }>();

const documentStore = useDocumentStore();
const reviewUiStore = useReviewUiStore();
const router = useRouter();
let autoSaveTimer: ReturnType<typeof setTimeout> | null = null;

const initialHtml = documentStore.review?.document_review.draft_html ?? '';

const editor = useEditor({
  extensions: [
    StarterKit,                                          // Strike, BulletList, OrderedList, History included
    Underline,
    Heading.configure({ levels: [1, 2, 3] }),
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Highlight.configure({ multicolor: false }),
    FontSizeExtension,                                   // extends TextStyle; covers textStyle mark
    IndentExtension,
  ],
  content: initialHtml,
  editable: true,
  onUpdate: () => {
    reviewUiStore.setDirty(true);
    scheduleAutoSave();
  },
});

const charCount = computed(() => editor.value?.state.doc.textContent.length ?? 0);

const activeHeadingLevel = computed<string>(() => {
  if (!editor.value) return '0';
  for (const level of [1, 2, 3] as const) {
    if (editor.value.isActive('heading', { level })) return String(level);
  }
  return '0';
});

onBeforeUnmount(() => {
  if (autoSaveTimer) clearTimeout(autoSaveTimer);
  editor.value?.destroy();
});

function setHeading(event: Event): void {
  const level = parseInt((event.target as HTMLSelectElement).value, 10);
  if (level === 0) {
    editor.value?.chain().focus().setParagraph().run();
  } else {
    editor.value?.chain().focus().setHeading({ level: level as 1 | 2 | 3 }).run();
  }
}

function setFontSize(event: Event): void {
  editor.value?.chain().focus().setFontSize((event.target as HTMLSelectElement).value).run();
}

function scheduleAutoSave(): void {
  if (autoSaveTimer) clearTimeout(autoSaveTimer);
  autoSaveTimer = setTimeout(saveDocument, 2000);
}

async function saveDocument(): Promise<void> {
  if (!editor.value || !reviewUiStore.isDirty) return;
  const result = await documentStore.saveReview({ draft_html: editor.value.getHTML() });
  if (result !== null) reviewUiStore.setDirty(false);
}

async function saveAndContinue(): Promise<void> {
  if (documentStore.saving) return;
  const result = await documentStore.saveReview({ draft_html: editor.value?.getHTML() ?? '' });
  if (result !== null) {
    reviewUiStore.setDirty(false);
    router.push(`/documents/${props.documentId}/rag`);
  }
}
</script>

<style scoped>
.editor-shell {
  background: #f8fafc;
  height: 100vh;
  width: 100vw;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.editor-shell-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 24px;
  background: white;
  border-bottom: 1px solid #e2e8f0;
  flex-shrink: 0;
}

.editor-shell-title {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
}

.editor-shell-subtitle {
  margin: 2px 0 0;
  font-size: 12px;
  color: #64748b;
}

.review-warning-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 24px;
  background: #fffbeb;
  border-bottom: 1px solid #fde68a;
  font-size: 13px;
  color: #7b3306;
  flex-shrink: 0;
}

.editor-toolbar {
  background: white;
  border-bottom: 1px solid #e2e8f0;
  flex-shrink: 0;
}

.toolbar-row {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px 12px;
  border-bottom: 1px solid #f1f5f9;
}

.toolbar-row:last-child {
  border-bottom: none;
}

.toolbar-label {
  font-size: 11px;
  color: #94a3b8;
  font-weight: 500;
  margin-right: 4px;
  white-space: nowrap;
  user-select: none;
}

.toolbar-divider {
  width: 1px;
  height: 20px;
  background: #e2e8f0;
  margin: 0 8px;
  flex-shrink: 0;
}

.toolbar-btn {
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  border-radius: 4px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  color: #475569;
  transition: background 0.15s;
  flex-shrink: 0;
}

.toolbar-btn:hover:not(:disabled) {
  background: #f1f5f9;
}

.toolbar-btn:disabled {
  opacity: 0.35;
  cursor: default;
}

.toolbar-btn.is-active {
  background: #e0e7ff;
  color: #4338ca;
}

.toolbar-select {
  height: 26px;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 0 6px;
  font-size: 12px;
  color: #475569;
  background: white;
  cursor: pointer;
}

.toolbar-autosave {
  font-size: 11px;
  color: #94a3b8;
  display: flex;
  align-items: center;
  gap: 4px;
  margin-left: auto;
  white-space: nowrap;
}

.toolbar-autosave--dirty {
  color: #f59e0b;
}

.toolbar-autosave--saved {
  color: #22c55e;
}

.editor-shell-edit {
  flex: 1;
  overflow: auto;
  padding: 24px;
  display: flex;
  justify-content: center;
}

.editor-shell-content {
  width: 100%;
  max-width: 768px;
}

.editor-shell-content :deep(.ProseMirror) {
  background: white;
  padding: 40px;
  border-radius: 6px;
  min-height: 944px;
  box-shadow: 0 0 0 1px #e2e8f0, 0 1px 3px rgba(0, 0, 0, 0.1);
  font-family: 'Sarabun', sans-serif;
  font-size: 14.4px;
  line-height: 1.75;
  color: #1e293b;
  outline: none;
}

.editor-shell-content :deep(.ProseMirror h1) {
  font-size: 20px;
  font-weight: 700;
  margin: 16px 0 8px;
}

.editor-shell-content :deep(.ProseMirror h2) {
  font-size: 17px;
  font-weight: 700;
  margin: 14px 0 7px;
}

.editor-shell-content :deep(.ProseMirror h3) {
  font-size: 15px;
  font-weight: 700;
  margin: 12px 0 6px;
}

.editor-shell-content :deep(.ProseMirror p) {
  margin: 0 0 8px;
}

.editor-shell-content :deep(.ProseMirror ul),
.editor-shell-content :deep(.ProseMirror ol) {
  padding-left: 24px;
  margin: 8px 0;
}

.editor-shell-content :deep(.ProseMirror li) {
  margin: 4px 0;
}

.editor-shell-content :deep(.ProseMirror mark) {
  background: #fff085;
  border-radius: 2px;
}

.editor-shell-error {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  background: #fef2f2;
  color: #b91c1c;
  border-top: 1px solid #fecaca;
  font-size: 13px;
  flex-shrink: 0;
}

.editor-shell-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 24px;
  background: white;
  border-top: 1px solid #e2e8f0;
  flex-shrink: 0;
}

.editor-shell-footer__count {
  font-size: 12px;
  color: #94a3b8;
}

.editor-shell-footer__actions {
  display: flex;
  gap: 8px;
}
</style>
```

- [ ] **Step 2: Verify typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors. If TypeScript complains about `setFontSize` not existing on the editor commands, verify `FontSizeExtension.ts` has the `declare module '@tiptap/core'` block from Task 2.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue
git commit -m "feat(review): rebuild DocumentEditorShell with 3-row toolbar, footer, auto-redirect to /rag"
```

---

## Task 6: Create RagPage.vue

**Files:**
- Create: `apps/app-laravel/resources/js/pages/rag/RagPage.vue`

Thin route shell — mirrors `ComposePage.vue`. Props flow from router (`props: true`).

- [ ] **Step 1: Create the directory and file**

`apps/app-laravel/resources/js/pages/rag/RagPage.vue`:

```vue
<template>
  <RagManageWorkspace :document-id="documentId" />
</template>

<script setup lang="ts">
import RagManageWorkspace from '../../components/rag/RagManageWorkspace.vue';

defineProps<{ documentId: string }>();
</script>
```

- [ ] **Step 2: Verify typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: error about missing `RagManageWorkspace` import is expected here — it will be resolved in Task 7. If the only error is the missing component, proceed.

Actually — to avoid a cascading typecheck failure, create a temporary stub for `RagManageWorkspace` first (a single-line file), then replace it in Task 7. Or just do Task 7 immediately after Task 6 before running typecheck.

**Skip typecheck here; run it after Task 7 instead.**

- [ ] **Step 3: Do NOT commit yet — commit after Task 7**

---

## Task 7: Create RagManageWorkspace.vue

**Files:**
- Create: `apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue`

Loads block data via `composeStore.fetch()`. Renders read-only block cards with checkboxes. Action bar for merge (≥2 selected) and remove (≥1 selected). Footer buttons: "ยกเลิก" (back to `/review`) and "บันทึกและเผยแพร่" (export → `/preview`).

**Critical:** No TipTap, no text editing, no format controls. Block text is plain `<p>` output only.

- [ ] **Step 1: Create the directory and file**

`apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue`:

```vue
<template>
  <LawspaceShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'จัดการ RAG บล็อก']"
    title="จัดการเนื้อหา RAG"
    subtitle="เลือกบล็อกเพื่อรวมหรือลบก่อนเผยแพร่"
  >
    <template #actions>
      <v-btn variant="outlined" @click="router.push(`/documents/${props.documentId}/review`)">
        ยกเลิก
      </v-btn>
      <v-btn color="#1a3673" :loading="composeStore.exporting" @click="handleExport">
        บันทึกและเผยแพร่
      </v-btn>
    </template>

    <!-- Loading -->
    <div v-if="composeStore.loading" class="rag-state">
      <v-progress-circular indeterminate color="primary" />
      <p>กำลังโหลดบล็อก...</p>
    </div>

    <!-- Error -->
    <div v-else-if="composeStore.error" class="rag-state">
      <v-icon icon="mdi-alert-circle-outline" size="32" color="error" />
      <p>{{ composeStore.error }}</p>
      <v-btn variant="outlined" size="small" @click="composeStore.setError()">ปิด</v-btn>
    </div>

    <template v-else>
      <!-- Action bar -->
      <div v-if="selectedBlockIds.size > 0" class="rag-action-bar">
        <span class="rag-action-bar__count">{{ selectedBlockIds.size }} รายการที่เลือก</span>
        <v-btn
          v-if="selectedBlockIds.size >= 2"
          size="small"
          color="primary"
          :loading="blockOpBusy"
          @click="handleMerge"
        >
          รวม RAG
        </v-btn>
        <v-btn
          size="small"
          color="error"
          variant="tonal"
          :loading="blockOpBusy"
          @click="handleRemove"
        >
          ลบ
        </v-btn>
        <v-btn size="small" variant="text" @click="selectedBlockIds = new Set()">
          ยกเลิกการเลือก
        </v-btn>
      </div>

      <!-- Block cards -->
      <div class="rag-block-list">
        <div
          v-for="item in flatBlocks"
          :key="item.block.block_id"
          class="rag-block-card"
          :class="{ 'rag-block-card--selected': selectedBlockIds.has(item.block.block_id) }"
        >
          <v-checkbox
            :model-value="selectedBlockIds.has(item.block.block_id)"
            density="compact"
            hide-details
            @update:model-value="toggleSelect(item.block.block_id)"
          />
          <div class="rag-block-card__body">
            <div class="rag-block-card__header">
              <span
                class="rag-block-badge"
                :style="{ background: typeColor(item.block.type) }"
              >
                {{ item.block.type }}
              </span>
              <span class="rag-block-card__page">หน้า {{ item.pageNo }}</span>
            </div>
            <p class="rag-block-card__text">
              {{ item.block.approved_text || item.block.normalized_text }}
            </p>
          </div>
        </div>

        <div v-if="flatBlocks.length === 0" class="rag-state">
          <p>ไม่พบบล็อกเนื้อหา</p>
        </div>
      </div>
    </template>
  </LawspaceShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useComposeStore } from '../../stores/composeStore';
import { useBlockStore } from '../../stores/blockStore';
import LawspaceShell from '../shared/LawspaceShell.vue';

const props = defineProps<{ documentId: string }>();

const router = useRouter();
const composeStore = useComposeStore();
const blockStore = useBlockStore();

const selectedBlockIds = ref(new Set<string>());
const blockOpBusy = ref(false);

const TYPE_COLORS: Record<string, string> = {
  title: '#1d4ed8',
  section_header: '#2563eb',
  paragraph: '#6b7280',
  list_item: '#0891b2',
  table: '#7c3aed',
  image: '#059669',
  figure_caption: '#64748b',
  footnote: '#78716c',
  unknown: '#9ca3af',
};

const flatBlocks = computed(() =>
  composeStore.review?.pages.flatMap(p =>
    p.blocks.map(b => ({ pageNo: p.page_no, block: b })),
  ) ?? [],
);

function typeColor(type: string): string {
  return TYPE_COLORS[type] ?? '#9ca3af';
}

function toggleSelect(blockId: string): void {
  const next = new Set(selectedBlockIds.value);
  if (next.has(blockId)) next.delete(blockId);
  else next.add(blockId);
  selectedBlockIds.value = next;
}

async function handleMerge(): Promise<void> {
  if (selectedBlockIds.value.size < 2 || blockOpBusy.value) return;
  blockOpBusy.value = true;
  try {
    const ordered = flatBlocks.value
      .filter(i => selectedBlockIds.value.has(i.block.block_id))
      .map(i => i.block.block_id);
    await blockStore.merge(props.documentId, ordered);
    selectedBlockIds.value = new Set();
    await composeStore.fetch(props.documentId);
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'รวมบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}

async function handleRemove(): Promise<void> {
  if (selectedBlockIds.value.size === 0 || blockOpBusy.value) return;
  blockOpBusy.value = true;
  try {
    for (const item of flatBlocks.value) {
      if (selectedBlockIds.value.has(item.block.block_id)) {
        await blockStore.remove(props.documentId, item.block.block_id, item.pageNo);
      }
    }
    selectedBlockIds.value = new Set();
    await composeStore.fetch(props.documentId);
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'ลบบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}

async function handleExport(): Promise<void> {
  await composeStore.triggerExport(props.documentId);
  if (!composeStore.error) {
    router.push(`/documents/${props.documentId}/preview`);
  }
}

onMounted(() => composeStore.fetch(props.documentId));
onBeforeUnmount(() => composeStore.reset());
</script>

<style scoped>
.rag-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px;
  gap: 12px;
  color: #64748b;
}

.rag-action-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  margin-bottom: 12px;
}

.rag-action-bar__count {
  font-size: 13px;
  font-weight: 600;
  color: #1d4ed8;
  flex: 1;
}

.rag-block-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.rag-block-card {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px;
  background: white;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  transition: border-color 0.15s, background 0.15s;
}

.rag-block-card--selected {
  border-color: #6366f1;
  background: #f5f3ff;
}

.rag-block-card__body {
  flex: 1;
  min-width: 0;
}

.rag-block-card__header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.rag-block-badge {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 99px;
  color: white;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.rag-block-card__page {
  font-size: 11px;
  color: #94a3b8;
}

.rag-block-card__text {
  font-size: 13px;
  color: #334155;
  line-height: 1.6;
  margin: 0;
  white-space: pre-wrap;
  font-family: 'Sarabun', sans-serif;
}
</style>
```

- [ ] **Step 2: Verify typecheck (covers Task 6 + Task 7 together)**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 3: Commit Tasks 6 + 7 together**

```bash
git add apps/app-laravel/resources/js/pages/rag/RagPage.vue apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue
git commit -m "feat(rag): add RagPage and RagManageWorkspace for block merge/remove/export"
```

---

## Task 8: Wire /rag route

**Files:**
- Modify: `apps/app-laravel/resources/js/router/index.ts`

Add `RagPage` import and `/documents/:documentId/rag` route. The `/compose` route stays in the file — nothing in the new flow links to it, making it a dead route.

- [ ] **Step 1: Edit the router**

`apps/app-laravel/resources/js/router/index.ts` — replace entirely:

```ts
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import PublicHomePage from '../pages/public/PublicHomePage.vue';
import AdminDashboardPage from '../pages/admin/AdminDashboardPage.vue';
import AdminUploadPage from '../pages/admin/AdminUploadPage.vue';
import UploadPage from '../pages/UploadPage.vue';
import ReviewPage from '../pages/review/ReviewPage.vue';
import ComposePage from '../pages/compose/ComposePage.vue';
import PreviewPage from '../pages/preview/PreviewPage.vue';
import RagPage from '../pages/rag/RagPage.vue';

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: PublicHomePage, meta: { bareLayout: true } },
  { path: '/admin', name: 'admin', component: AdminDashboardPage, meta: { bareLayout: true } },
  { path: '/admin/upload', name: 'admin-upload', component: AdminUploadPage, meta: { bareLayout: true } },
  { path: '/upload', name: 'upload-legacy', component: UploadPage },
  { path: '/documents/:documentId/review', name: 'review', component: ReviewPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/compose', name: 'compose', component: ComposePage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/rag', name: 'rag', component: RagPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/preview', name: 'preview', component: PreviewPage, props: true, meta: { bareLayout: true } },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
```

- [ ] **Step 2: Verify typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/router/index.ts
git commit -m "feat(router): add /documents/:id/rag route wired to RagPage"
```

---

## Self-Review Checklist

**Spec coverage:**
- ✅ Upload page: simplified, no sidebar, no selectors, auto-redirect on done (Tasks 3–4)
- ✅ Review/edit page: 3-row toolbar, warning banner, footer, no AI banner, no preview toggle, nav to /rag (Task 5)
- ✅ RAG management: read-only blocks, merge, remove, export to /preview (Tasks 6–7)
- ✅ Router wired (Task 8)
- ✅ FontSizeExtension: custom TextStyle extension (Task 2)
- ✅ IndentExtension reused from existing extensions/ (referenced in Task 5, no new file)
- ✅ No AI banner anywhere
- ✅ No per-block editing in review shell
- ✅ `UploadForm` keeps hidden defaults (Task 3)

**Type consistency across tasks:**
- `FontSizeExtension` defined in Task 2, imported in Task 5 — names match
- `composeStore.fetch(documentId)` called in Task 7 — matches `composeStore.ts` signature ✅
- `composeStore.triggerExport(documentId)` called in Task 7 — matches `composeStore.ts` ✅
- `blockStore.merge(documentId, blockIds)` called in Task 7 — matches `blockStore.ts` ✅
- `blockStore.remove(documentId, blockId, pageNo)` called in Task 7 — matches `blockStore.ts` ✅
- `documentStore.saveReview({ draft_html })` called in Task 5 — matches `documentStore.ts` ✅
- `reviewUiStore.setDirty(v)` + `reviewUiStore.isDirty` — matches `reviewUiStore.ts` ✅
