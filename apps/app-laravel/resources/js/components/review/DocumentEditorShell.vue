<template>
  <div class="d-flex flex-column" style="height:100dvh; min-height:100dvh; overflow:hidden; padding:16px 24px; background:#f8fafc; box-sizing:border-box">
    <!-- Header -->
    <div class="d-flex justify-space-between align-center py-3" style="flex-shrink:0">
      <div>
        <div class="text-subtitle-1 font-weight-bold">ตรวจทานเนื้อหาเอกสาร</div>
        <div class="text-caption text-medium-emphasis">อ่านทวน แก้ไข และจัดรูปแบบก่อนยืนยันนำเข้าระบบ</div>
      </div>
    </div>

    <!-- Warning banner -->
    <v-alert type="warning" variant="tonal" density="compact" icon="mdi-alert-outline" class="mb-2" style="flex-shrink:0">
      กรุณาตรวจทานเนื้อหาให้ครบถ้วนก่อนยืนยัน เนื่องจากการแปลงไฟล์อัตโนมัติอาจมีความคลาดเคลื่อนในบางจุด
    </v-alert>

    <!-- Toolbar -->
    <div v-if="editor" class="d-flex flex-wrap align-center ga-1 pa-3 mt-2 bg-white rounded-lg" style="border:1px solid #e2e8f0; flex-shrink:0">
      <!-- Row 1: History + Heading -->
      <span class="text-caption text-medium-emphasis mr-1">ย้อนกลับ</span>
      <v-btn icon="mdi-undo" variant="text" size="small" :disabled="!editor.can().undo()" title="Undo" @click="editor.chain().focus().undo().run()" />
      <v-btn icon="mdi-redo" variant="text" size="small" :disabled="!editor.can().redo()" title="Redo" @click="editor.chain().focus().redo().run()" />
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">หัวข้อ</span>
      <select style="height:28px; border:1px solid #d1d5db; border-radius:8px; padding:0 8px; font-size:12px; background:#f8fafc; cursor:pointer; min-width:90px; max-width:150px" :value="activeHeadingLevel" @change="setHeading($event)">
        <option value="0">ย่อหน้า</option>
        <option value="1">หัวข้อ 1</option>
        <option value="2">หัวข้อ 2</option>
        <option value="3">หัวข้อ 3</option>
      </select>

      <!-- Row 2: Font size + Format -->
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">แบบอักษร</span>
      <select style="height:28px; border:1px solid #d1d5db; border-radius:8px; padding:0 8px; font-size:12px; background:#f8fafc; cursor:pointer; min-width:90px; max-width:150px" @change="setFontFamily($event)">
        <option value="">ค่าเริ่มต้น</option>
        <option value="'TH Sarabun New', 'Sarabun', sans-serif">TH Sarabun New</option>
        <option value="'TH Sarabun PSK', 'Sarabun', sans-serif">TH Sarabun PSK</option>
        <option value="'TH SarabunIT9', 'Sarabun', sans-serif">TH SarabunIT9</option>
        <option value="'Sarabun', sans-serif">Sarabun</option>
      </select>
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">ขนาด</span>
      <select style="height:28px; border:1px solid #d1d5db; border-radius:8px; padding:0 8px; font-size:12px; background:#f8fafc; cursor:pointer; min-width:90px; max-width:150px" @change="setFontSize($event)">
        <option v-for="s in [12, 14, 16, 18, 20]" :key="s" :value="`${s}pt`">{{ s }}</option>
      </select>
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">รูปแบบ</span>
      <v-btn icon="mdi-format-bold" variant="text" size="small" :color="editor.isActive('bold') ? 'primary' : undefined" @click="editor.chain().focus().toggleBold().run()" />
      <v-btn icon="mdi-format-italic" variant="text" size="small" :color="editor.isActive('italic') ? 'primary' : undefined" @click="editor.chain().focus().toggleItalic().run()" />
      <v-btn icon="mdi-format-underline" variant="text" size="small" :color="editor.isActive('underline') ? 'primary' : undefined" @click="editor.chain().focus().toggleUnderline().run()" />
      <v-btn icon="mdi-format-strikethrough" variant="text" size="small" :color="editor.isActive('strike') ? 'primary' : undefined" @click="editor.chain().focus().toggleStrike().run()" />
      <v-btn icon="mdi-marker" variant="text" size="small" :color="editor.isActive('highlight') ? 'primary' : undefined" @click="editor.chain().focus().toggleHighlight().run()" />

      <!-- Row 3: Alignment + Lists + Auto-save indicator -->
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">จัดข้อความ</span>
      <v-btn icon="mdi-format-align-left" variant="text" size="small" :color="editor.isActive({ textAlign: 'left' }) ? 'primary' : undefined" @click="editor.chain().focus().setTextAlign('left').run()" />
      <v-btn icon="mdi-format-align-center" variant="text" size="small" :color="editor.isActive({ textAlign: 'center' }) ? 'primary' : undefined" @click="editor.chain().focus().setTextAlign('center').run()" />
      <v-btn icon="mdi-format-align-right" variant="text" size="small" :color="editor.isActive({ textAlign: 'right' }) ? 'primary' : undefined" @click="editor.chain().focus().setTextAlign('right').run()" />
      <v-btn icon="mdi-format-align-justify" variant="text" size="small" :color="editor.isActive({ textAlign: 'justify' }) ? 'primary' : undefined" @click="editor.chain().focus().setTextAlign('justify').run()" />
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">รายการ</span>
      <v-btn icon="mdi-format-list-bulleted" variant="text" size="small" :color="editor.isActive('bulletList') ? 'primary' : undefined" @click="editor.chain().focus().toggleBulletList().run()" />
      <v-btn icon="mdi-format-list-numbered" variant="text" size="small" :color="editor.isActive('orderedList') ? 'primary' : undefined" @click="editor.chain().focus().toggleOrderedList().run()" />
      <v-btn icon="mdi-format-indent-increase" variant="text" size="small" @click="editor.chain().focus().increaseIndent().run()" />
      <v-btn icon="mdi-format-indent-decrease" variant="text" size="small" @click="editor.chain().focus().decreaseIndent().run()" />
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">ระยะบรรทัด</span>
      <select style="height:28px; border:1px solid #d1d5db; border-radius:8px; padding:0 8px; font-size:12px; background:#f8fafc; cursor:pointer; min-width:90px; max-width:150px" @change="setLineHeight($event)">
        <option value="">ปกติ</option>
        <option value="1">1.0</option>
        <option value="1.15">1.15</option>
        <option value="1.5">1.5</option>
        <option value="2">2.0</option>
      </select>
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption d-flex align-center ga-1 ml-auto" style="white-space:nowrap">
        <v-icon :icon="reviewUiStore.isDirty ? 'mdi-circle-medium' : 'mdi-check-circle-outline'" :color="reviewUiStore.isDirty ? 'warning' : 'success'" size="16" />
        บันทึกอัตโนมัติ
      </span>

      <!-- Row 4: Table + Image -->
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">ตาราง</span>
      <v-btn icon="mdi-table-plus" variant="text" size="small" title="แทรกตาราง" @click="editor.chain().focus().insertTable({ rows: 2, cols: 2, withHeaderRow: true }).run()" />
      <v-btn icon="mdi-table-row-plus-after" variant="text" size="small" title="เพิ่มแถว" @click="editor.chain().focus().addRowAfter().run()" />
      <v-btn icon="mdi-table-row-remove" variant="text" size="small" title="ลบแถว" @click="editor.chain().focus().deleteRow().run()" />
      <v-btn icon="mdi-table-column-plus-after" variant="text" size="small" title="เพิ่มคอลัมน์" @click="editor.chain().focus().addColumnAfter().run()" />
      <v-btn icon="mdi-table-column-remove" variant="text" size="small" title="ลบคอลัมน์" @click="editor.chain().focus().deleteColumn().run()" />
      <v-btn icon="mdi-table-remove" variant="text" size="small" title="ลบตาราง" @click="editor.chain().focus().deleteTable().run()" />
      <v-btn icon="mdi-table-merge-cells" variant="text" size="small" title="ผสานเซลล์" @click="editor.chain().focus().mergeCells().run()" />
      <v-btn icon="mdi-table-split-cell" variant="text" size="small" title="แยกเซลล์" @click="editor.chain().focus().splitCell().run()" />
      <v-divider vertical class="mx-2" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">รูปภาพ</span>
      <span class="text-caption text-medium-emphasis mr-1">ลากมุมเพื่อปรับขนาด</span>
    </div>

    <!-- Editor area -->
    <div class="flex-grow-1 overflow-y-auto d-flex justify-center py-4" style="min-height:0" @click.stop>
      <EditorContent v-if="editor" :editor="editor" class="editor-shell-content" />
    </div>

    <!-- Error bar -->
    <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" style="flex-shrink:0">
      {{ documentStore.saveError }}
      <template #append>
        <v-btn size="x-small" variant="text" @click="documentStore.setSaveError()">ปิด</v-btn>
      </template>
    </v-alert>

    <!-- Footer -->
    <div class="d-flex align-center justify-space-between px-6 py-3 bg-white" style="border-top:1px solid #e2e8f0; flex-shrink:0">
      <span class="text-caption text-medium-emphasis">{{ charCount }} ตัวอักษร · บันทึกอัตโนมัติเมื่อปิด</span>
      <div class="d-flex ga-2">
        <v-btn variant="outlined" @click="router.back()">ยกเลิก</v-btn>
        <v-btn variant="outlined" prepend-icon="mdi-eye-outline" :loading="documentStore.saving" @click="openPreview">ดูตัวอย่าง</v-btn>
        <v-btn color="#1c398e" :loading="documentStore.saving" @click="saveAndContinue">บันทึกข้อมูล</v-btn>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue';
import { onBeforeRouteLeave, useRouter } from 'vue-router';
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

onBeforeRouteLeave(async () => {
  if (autoSaveTimer) {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = null;
  }

  if (reviewUiStore.isDirty && editor.value) {
    await saveDocument();
  }
});

onBeforeUnmount(() => {
  if (autoSaveTimer) {
    clearTimeout(autoSaveTimer);
    void saveDocument();
  }
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
    router.push(`/documents/${props.documentId}/law-info`);
  }
}

async function openPreview(): Promise<void> {
  if (reviewUiStore.isDirty && editor.value) {
    const result = await documentStore.saveReview({ draft_html: editor.value.getHTML() });
    if (result !== null) reviewUiStore.setDirty(false);
  }

  router.push(`/documents/${props.documentId}/preview`);
}
</script>

<style scoped>
/* ponytail: ProseMirror/TipTap document canvas — keep all */
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

@media (max-width: 760px) {
  .editor-shell-content :deep(.ProseMirror) {
    border-radius: 4px;
    padding: 24px 20px;
  }
}
</style>
