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

    <!-- Toolbar -->
    <div v-if="editor" class="editor-toolbar">
      <!-- Row 1: History + Heading -->
      <div class="toolbar-row">
        <span class="toolbar-label">ย้อนกลับ</span>
        <button
          class="toolbar-btn"
          :disabled="!editor.can().undo()"
          title="Undo"
          @click="editor.chain().focus().undo().run()"
        ><i class="mdi mdi-undo" /></button>
        <button
          class="toolbar-btn"
          :disabled="!editor.can().redo()"
          title="Redo"
          @click="editor.chain().focus().redo().run()"
        ><i class="mdi mdi-redo" /></button>
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
