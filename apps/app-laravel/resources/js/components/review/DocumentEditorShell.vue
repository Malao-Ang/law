<template>
  <div class="editor-shell">
    <!-- Header -->
    <div class="editor-shell-header">
      <div>
        <h1 class="editor-shell-title">ตรวจทานเนื้อหาเอกสาร</h1>
        <p class="editor-shell-subtitle">อ่านทวน แก้ไข และจัดรูปแบบก่อนยืนยันนำเข้าระบบ</p>
      </div>
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
        <span class="toolbar-label">แบบอักษร</span>
        <select class="toolbar-select" @change="setFontFamily($event)">
          <option value="">ค่าเริ่มต้น</option>
          <option value="'TH Sarabun New', 'Sarabun', sans-serif">TH Sarabun New</option>
          <option value="'TH Sarabun PSK', 'Sarabun', sans-serif">TH Sarabun PSK</option>
          <option value="'TH SarabunIT9', 'Sarabun', sans-serif">TH SarabunIT9</option>
          <option value="'Sarabun', sans-serif">Sarabun</option>
        </select>
        <div class="toolbar-divider" />
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
        <span class="toolbar-label">ระยะบรรทัด</span>
        <select class="toolbar-select" @change="setLineHeight($event)">
          <option value="">ปกติ</option>
          <option value="1">1.0</option>
          <option value="1.15">1.15</option>
          <option value="1.5">1.5</option>
          <option value="2">2.0</option>
        </select>
        <div class="toolbar-divider" />
        <span class="toolbar-autosave">
          <i
            class="mdi"
            :class="reviewUiStore.isDirty ? 'mdi-circle-medium toolbar-autosave--dirty' : 'mdi-check-circle-outline toolbar-autosave--saved'"
          />
          บันทึกอัตโนมัติ
        </span>
      </div>

      <div class="toolbar-row">
        <span class="toolbar-label">ตาราง</span>
        <button
          class="toolbar-btn"
          title="แทรกตาราง"
          @click="editor.chain().focus().insertTable({ rows: 2, cols: 2, withHeaderRow: true }).run()"
        ><i class="mdi mdi-table-plus" /></button>
        <button
          class="toolbar-btn"
          title="เพิ่มแถว"
          @click="editor.chain().focus().addRowAfter().run()"
        ><i class="mdi mdi-table-row-plus-after" /></button>
        <button
          class="toolbar-btn"
          title="ลบแถว"
          @click="editor.chain().focus().deleteRow().run()"
        ><i class="mdi mdi-table-row-remove" /></button>
        <button
          class="toolbar-btn"
          title="เพิ่มคอลัมน์"
          @click="editor.chain().focus().addColumnAfter().run()"
        ><i class="mdi mdi-table-column-plus-after" /></button>
        <button
          class="toolbar-btn"
          title="ลบคอลัมน์"
          @click="editor.chain().focus().deleteColumn().run()"
        ><i class="mdi mdi-table-column-remove" /></button>
        <button
          class="toolbar-btn"
          title="ลบตาราง"
          @click="editor.chain().focus().deleteTable().run()"
        ><i class="mdi mdi-table-remove" /></button>
        <button
          class="toolbar-btn"
          title="ผสานเซลล์"
          @click="editor.chain().focus().mergeCells().run()"
        ><i class="mdi mdi-table-merge-cells" /></button>
        <button
          class="toolbar-btn"
          title="แยกเซลล์"
          @click="editor.chain().focus().splitCell().run()"
        ><i class="mdi mdi-table-split-cell" /></button>
        <div class="toolbar-divider" />
        <span class="toolbar-label">รูปภาพ</span>
        <span class="toolbar-label">ลากมุมเพื่อปรับขนาด</span>
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
import TableRow from '@tiptap/extension-table-row';
import TableHeader from '@tiptap/extension-table-header';
import TableCell from '@tiptap/extension-table-cell';
import { patchBlockSize } from '../../api/client';
import { BlockIdExtension } from '../../extensions/BlockIdExtension';
import { IndentExtension } from '../../extensions/IndentExtension';
import { FirstLineIndentExtension } from '../../extensions/FirstLineIndentExtension';
import { LineHeightExtension } from '../../extensions/LineHeightExtension';
import { FontSizeExtension } from '../../extensions/FontSizeExtension';
import { ResizableImageExtension } from '../../extensions/ResizableImageExtension';
import { TableWithBlockIdExtension } from '../../extensions/TableWithBlockIdExtension';
import { useDocumentStore } from '../../stores/documentStore';
import { useReviewUiStore } from '../../stores/reviewUiStore';

const props = defineProps<{ documentId: string }>();

const documentStore = useDocumentStore();
const reviewUiStore = useReviewUiStore();
const router = useRouter();
let autoSaveTimer: ReturnType<typeof setTimeout> | null = null;
const tableWidths = new Map<string, number>();
let tableSyncTimer: ReturnType<typeof setTimeout> | null = null;

const initialHtml = documentStore.review?.document_review.draft_html
  || documentStore.review?.document_review.generated_html
  || '';

const editor = useEditor({
  extensions: [
    StarterKit,
    Underline,
    Heading.configure({ levels: [1, 2, 3] }),
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Highlight.configure({ multicolor: false }),
    FontSizeExtension,
    IndentExtension,
    FirstLineIndentExtension,
    BlockIdExtension,
    LineHeightExtension,
    ResizableImageExtension.configure({ inline: true, allowBase64: true, documentId: props.documentId }),
    TableWithBlockIdExtension.configure({ resizable: true }),
    TableRow,
    TableHeader,
    TableCell,
  ],
  content: initialHtml,
  editable: true,
  onCreate: () => {
    primeTableWidths();
  },
  onUpdate: () => {
    reviewUiStore.setDirty(true);
    scheduleAutoSave();
    scheduleTableSync();
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
  if (tableSyncTimer) clearTimeout(tableSyncTimer);
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

function setFontFamily(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  if (value === '') {
    editor.value?.chain().focus().unsetFontFamily().run();
    return;
  }

  editor.value?.chain().focus().setFontFamily(value).run();
}

function setLineHeight(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  if (value === '') {
    editor.value?.chain().focus().unsetLineHeight().run();
    return;
  }

  editor.value?.chain().focus().setLineHeight(value).run();
}

function scheduleAutoSave(): void {
  if (autoSaveTimer) clearTimeout(autoSaveTimer);
  autoSaveTimer = setTimeout(saveDocument, 2000);
}

function scheduleTableSync(): void {
  if (tableSyncTimer) clearTimeout(tableSyncTimer);
  tableSyncTimer = setTimeout(syncTableSizes, 800);
}

function readTables(): HTMLTableElement[] {
  const root = editor.value?.view.dom as HTMLElement | undefined;
  if (!root) return [];

  return Array.from(root.querySelectorAll('table[data-block-id]')) as HTMLTableElement[];
}

function primeTableWidths(): void {
  readTables().forEach((table) => {
    const blockId = table.getAttribute('data-block-id') ?? '';
    if (blockId) tableWidths.set(blockId, Math.round(table.offsetWidth));
  });
}

function syncTableSizes(): void {
  readTables().forEach((table) => {
    const blockId = table.getAttribute('data-block-id') ?? '';
    const pageNo = Number(table.getAttribute('data-page-no')) || 1;
    const width = Math.round(table.offsetWidth);

    if (!blockId || !width) return;
    if (tableWidths.get(blockId) === width) return;

    tableWidths.set(blockId, width);
    patchBlockSize(props.documentId, blockId, {
      page_no: pageNo,
      display_width_px: width,
      display_height_px: null,
    }).catch(() => {
      // non-fatal
    });
  });
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
  position: relative;
  height: 100dvh;
  min-height: 100dvh;
  background: #f8fafc;
  width: 100%;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 16px 24px;
  box-sizing: border-box;
}

.editor-shell-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 0;
  background: transparent;
  border-bottom: none;
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
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 8px;
  padding: 8px 12px;
  margin-top: 12px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  flex-shrink: 0;
}

/* Rows flow into one compact wrapping bar (~2 lines) instead of stacked cards. */
.toolbar-row {
  display: contents;
}

.toolbar-row:last-child {
  margin-bottom: 0;
}

.toolbar-label {
  font-size: 10px;
  color: #64748b;
  font-weight: 500;
  margin-right: 6px;
  white-space: nowrap;
  user-select: none;
}

.toolbar-divider {
  width: 1px;
  height: 18px;
  background: #e2e8f0;
  margin: 0 8px;
  flex-shrink: 0;
}

.toolbar-btn {
  width: 30px;
  height: 30px;
  border: none;
  background: transparent;
  border-radius: 8px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  color: #475569;
  transition: background 0.15s, transform 0.15s;
  flex-shrink: 0;
}

.toolbar-btn:hover:not(:disabled) {
  background: #f8fafc;
  transform: translateY(-1px);
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
  height: 28px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 0 8px;
  font-size: 12px;
  color: #475569;
  background: #f8fafc;
  cursor: pointer;
  min-width: 90px;
  max-width: 150px;
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
  min-height: 0;
  overflow-y: auto;
  display: flex;
  justify-content: center;
  padding: 16px 0;
}

.editor-shell-content {
  width: 100%;
  max-width: 820px;
}

.editor-shell-content :deep(.ProseMirror) {
  background: white;
  padding: 40px 48px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  min-height: 640px;
  font-family: 'Sarabun', sans-serif;
  font-size: 16px;
  line-height: 1.85;
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

.editor-shell-content :deep(.ProseMirror table) {
  border-collapse: collapse;
  width: 100%;
  margin: 12px 0;
  table-layout: fixed;
  word-break: break-word;
}

.editor-shell-content :deep(.ProseMirror th),
.editor-shell-content :deep(.ProseMirror td) {
  border: 1px solid #8e9aa2;
  padding: 7px 10px;
  vertical-align: top;
  position: relative;
}

.editor-shell-content :deep(.ProseMirror th) {
  background: #ffffff;
  font-weight: 700;
}

.editor-shell-content :deep(.ProseMirror .tableWrapper) {
  overflow-x: auto;
  margin: 12px 0;
}

.editor-shell-content :deep(.ProseMirror table) {
  min-width: 100%;
}

.editor-shell-content :deep(.ProseMirror .column-resize-handle) {
  position: absolute;
  right: -2px;
  top: 0;
  bottom: -2px;
  width: 4px;
  background: #4f86ff;
  cursor: col-resize;
  z-index: 20;
  pointer-events: none;
}

.editor-shell-content :deep(.ProseMirror.resize-cursor) {
  cursor: col-resize;
}

.editor-shell-content :deep(.ProseMirror .selectedCell)::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(79, 134, 255, 0.18);
  pointer-events: none;
  z-index: 10;
}

.editor-shell-content :deep(.ProseMirror img) {
  max-width: 100%;
  height: auto;
  display: block;
  margin: 8px 0;
  border: 1px solid #e1e8f5;
  border-radius: 4px;
}

/* indent classes from generated_html */
.editor-shell-content :deep(.ProseMirror .doc-indent-1)  { margin-left: 24px; }
.editor-shell-content :deep(.ProseMirror .doc-indent-2)  { margin-left: 48px; }
.editor-shell-content :deep(.ProseMirror .doc-indent-3)  { margin-left: 72px; }
.editor-shell-content :deep(.ProseMirror .doc-indent-4)  { margin-left: 96px; }
.editor-shell-content :deep(.ProseMirror .doc-indent-5)  { margin-left: 120px; }
.editor-shell-content :deep(.ProseMirror .doc-indent-6)  { margin-left: 144px; }
.editor-shell-content :deep(.ProseMirror .doc-indent-7)  { margin-left: 168px; }
.editor-shell-content :deep(.ProseMirror .doc-indent-8)  { margin-left: 192px; }
.editor-shell-content :deep(.ProseMirror .doc-indent-9)  { margin-left: 216px; }
.editor-shell-content :deep(.ProseMirror .doc-indent-10) { margin-left: 240px; }

/* tab spans from generated_html — TipTap may strip inline width, so define it in CSS */
.editor-shell-content :deep(.ProseMirror .doc-tab) {
  display: inline-block;
  min-width: 2rem;
  height: 1em;
  line-height: 1;
  vertical-align: text-bottom;
  border-bottom: 1px dotted rgba(25, 118, 210, 0.25);
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

@media (max-width: 760px) {
  .editor-shell-header,
  .editor-shell-footer {
    padding-left: 16px;
    padding-right: 16px;
  }

  .editor-shell-content :deep(.ProseMirror) {
    border-radius: 4px;
    padding: 24px 20px;
  }
}
</style>
