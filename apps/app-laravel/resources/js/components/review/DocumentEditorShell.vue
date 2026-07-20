<template>
  <div class="d-flex flex-column" style="height:100dvh; min-height:100dvh; overflow:hidden; padding:16px 24px 60px; background:#f8fafc; box-sizing:border-box">
    <WorkflowFooterBar
      :step="2"
      next-label="บันทึก"
      :next-loading="documentStore.saving"
      @back="router.push('/admin/upload')"
      @next="saveAndContinue"
    />
    <WorkflowStepper :step="2" description="อ่านทวน แก้ไข และจัดรูปแบบก่อนยืนยันนำเข้าระบบ" />

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

    <div v-if="editor && !props.locked" class="d-flex flex-wrap align-center ga-2 pa-3 mt-2 bg-white rounded-lg" style="border:1px solid #e2e8f0; flex-shrink:0">
      <span class="text-caption text-medium-emphasis mr-1">ย้อนกลับ</span>
      <v-btn icon="mdi-undo" variant="text" size="small" :disabled="!editor.can().undo()" title="Undo" @click="editor.chain().focus().undo().run()" />
      <v-btn icon="mdi-redo" variant="text" size="small" :disabled="!editor.can().redo()" title="Redo" @click="editor.chain().focus().redo().run()" />

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">หัวข้อ</span>
      <select class="toolbar-select" :value="activeHeadingLevel" @change="setHeading($event)">
        <option value="0">ย่อหน้า</option>
        <option value="1">หัวข้อ 1</option>
        <option value="2">หัวข้อ 2</option>
        <option value="3">หัวข้อ 3</option>
      </select>

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">แบบอักษร</span>
      <select class="toolbar-select toolbar-select--font" :value="activeFontFamilyOption" @change="setFontFamily($event)">
        <option value="">ค่าเริ่มต้น</option>
        <option value="'TH Sarabun New', 'Sarabun', sans-serif">TH Sarabun New</option>
        <option value="'TH Sarabun PSK', 'Sarabun', sans-serif">TH Sarabun PSK</option>
        <option value="'TH SarabunIT9', 'Sarabun', sans-serif">TH SarabunIT9</option>
        <option value="'CordiaNew', 'Sarabun', sans-serif">CordiaNew</option>
        <option value="'Sarabun', sans-serif">Sarabun</option>
      </select>

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">ขนาด</span>
      <div class="d-flex align-center ga-1">
        <input
          v-model="fontSizeInput"
          class="toolbar-number-input"
          type="number"
          min="8"
          max="72"
          step="1"
          inputmode="numeric"
          @blur="commitFontSizeInput"
          @keydown.enter.prevent="commitFontSizeInput"
        >
        <span class="text-caption text-medium-emphasis">pt</span>
        <select class="toolbar-select toolbar-select--preset" :value="fontSizePresetValue" @change="applyFontSizePreset($event)">
          <option value="">ขนาดด่วน</option>
          <option v-for="size in fontSizePresets" :key="size" :value="String(size)">{{ size }} pt</option>
        </select>
      </div>

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">รูปแบบ</span>
      <v-btn icon="mdi-format-bold" variant="text" size="small" :color="editor.isActive('bold') ? 'primary' : undefined" @click="editor.chain().focus().toggleBold().run()" />
      <v-btn icon="mdi-format-italic" variant="text" size="small" :color="editor.isActive('italic') ? 'primary' : undefined" @click="editor.chain().focus().toggleItalic().run()" />
      <v-btn icon="mdi-format-underline" variant="text" size="small" :color="editor.isActive('underline') ? 'primary' : undefined" @click="editor.chain().focus().toggleUnderline().run()" />
      <v-btn icon="mdi-format-strikethrough" variant="text" size="small" :color="editor.isActive('strike') ? 'primary' : undefined" @click="editor.chain().focus().toggleStrike().run()" />
      <v-btn icon="mdi-marker" variant="text" size="small" :color="editor.isActive('highlight') ? 'primary' : undefined" @click="editor.chain().focus().toggleHighlight().run()" />

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">จัดข้อความ</span>
      <v-btn icon="mdi-format-align-left" variant="text" size="small" :color="editor.isActive({ textAlign: 'left' }) ? 'primary' : undefined" @click="editor.chain().focus().setTextAlign('left').run()" />
      <v-btn icon="mdi-format-align-center" variant="text" size="small" :color="editor.isActive({ textAlign: 'center' }) ? 'primary' : undefined" @click="editor.chain().focus().setTextAlign('center').run()" />
      <v-btn icon="mdi-format-align-right" variant="text" size="small" :color="editor.isActive({ textAlign: 'right' }) ? 'primary' : undefined" @click="editor.chain().focus().setTextAlign('right').run()" />
      <v-btn icon="mdi-format-align-justify" variant="text" size="small" :color="editor.isActive({ textAlign: 'justify' }) ? 'primary' : undefined" @click="editor.chain().focus().setTextAlign('justify').run()" />

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">รายการ</span>
      <v-btn icon="mdi-format-list-bulleted" variant="text" size="small" :color="editor.isActive('bulletList') ? 'primary' : undefined" @click="editor.chain().focus().toggleBulletList().run()" />
      <v-btn icon="mdi-format-list-numbered" variant="text" size="small" :color="editor.isActive('orderedList') ? 'primary' : undefined" @click="editor.chain().focus().toggleOrderedList().run()" />
      <v-btn icon="mdi-format-indent-increase" variant="text" size="small" @click="editor.chain().focus().increaseIndent().run()" />
      <v-btn icon="mdi-format-indent-decrease" variant="text" size="small" @click="editor.chain().focus().decreaseIndent().run()" />

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">ระยะบรรทัด</span>
      <select class="toolbar-select toolbar-select--preset" @change="setLineHeight($event)">
        <option value="">ปกติ</option>
        <option value="1">1.0</option>
        <option value="1.15">1.15</option>
        <option value="1.5">1.5</option>
        <option value="2">2.0</option>
      </select>

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">ระยะขอบ</span>
      <select class="toolbar-select toolbar-select--preset" :value="selectedMarginPreset" @change="updateMarginPreset($event)">
        <option value="normal">ปกติ</option>
        <option value="narrow">แคบ</option>
        <option value="wide">กว้าง</option>
        <option value="custom">กำหนดเอง</option>
      </select>
      <div v-if="selectedMarginPreset === 'custom'" class="d-flex flex-wrap align-center ga-1">
        <label v-for="side in marginInputOrder" :key="side.key" class="d-flex align-center ga-1 text-caption text-medium-emphasis">
          <span>{{ side.label }}</span>
          <input
            :value="marginInputsMm[side.key]"
            class="toolbar-mm-input"
            type="number"
            min="0"
            max="101.6"
            step="0.1"
            inputmode="decimal"
            @input="updateCustomMargin(side.key, $event)"
            @blur="resetCustomMarginInput(side.key)"
          >
          <span>mm</span>
        </label>
      </div>

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">ซูม</span>
      <select class="toolbar-select toolbar-select--preset" :value="String(zoomPercent)" @change="setZoom($event)">
        <option v-for="zoom in zoomPresets" :key="zoom" :value="String(zoom)">{{ zoom }}%</option>
      </select>

      <span class="text-caption d-flex align-center ga-1 ml-auto" style="white-space:nowrap">
        <v-icon :icon="reviewUiStore.isDirty ? 'mdi-circle-medium' : 'mdi-check-circle-outline'" :color="reviewUiStore.isDirty ? 'warning' : 'success'" size="16" />
        บันทึกอัตโนมัติ
      </span>
      <v-btn size="small" variant="outlined" prepend-icon="mdi-eye-outline" :loading="documentStore.saving" @click="openPreview">ดูตัวอย่าง</v-btn>

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">ตาราง</span>
      <v-btn icon="mdi-table-plus" variant="text" size="small" title="แทรกตาราง" @click="editor.chain().focus().insertTable({ rows: 2, cols: 2, withHeaderRow: true }).run()" />
      <v-btn icon="mdi-table-row-plus-after" variant="text" size="small" title="เพิ่มแถว" @click="editor.chain().focus().addRowAfter().run()" />
      <v-btn icon="mdi-table-row-remove" variant="text" size="small" title="ลบแถว" @click="editor.chain().focus().deleteRow().run()" />
      <v-btn icon="mdi-table-column-plus-after" variant="text" size="small" title="เพิ่มคอลัมน์" @click="editor.chain().focus().addColumnAfter().run()" />
      <v-btn icon="mdi-table-column-remove" variant="text" size="small" title="ลบคอลัมน์" @click="editor.chain().focus().deleteColumn().run()" />
      <v-btn icon="mdi-table-remove" variant="text" size="small" title="ลบตาราง" @click="editor.chain().focus().deleteTable().run()" />
      <v-btn icon="mdi-table-merge-cells" variant="text" size="small" title="ผสานเซลล์" @click="editor.chain().focus().mergeCells().run()" />
      <v-btn icon="mdi-table-split-cell" variant="text" size="small" title="แยกเซลล์" @click="editor.chain().focus().splitCell().run()" />

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">หน้า</span>
      <v-btn
        icon="mdi-format-page-break"
        variant="text"
        size="small"
        title="แบ่งหน้า"
        @click="editor.chain().focus().insertPageBreak().run()"
      />

      <v-divider vertical class="mx-1" style="height:18px; align-self:center" />
      <span class="text-caption text-medium-emphasis mr-1">รูปภาพ</span>
      <span class="text-caption text-medium-emphasis">ลากมุมเพื่อปรับขนาด</span>
    </div>

    <div class="editor-shell-scroll flex-grow-1" style="min-height:0" @click.stop>
      <div
        v-if="editor && !props.locked"
        class="ruler-stage"
        :style="rulerStageStyle"
      >
        <ReviewRuler :editor="editor" :content-mm="contentMm" />
      </div>
      <div class="editor-stage" :style="editorStageStyle">
        <div ref="pageFrameRef" class="a4-page" :style="pageFrameStyle">
          <EditorContent v-if="editor" :editor="editor" class="editor-shell-content" />
        </div>
      </div>
    </div>

    <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" style="flex-shrink:0">
      {{ documentStore.saveError }}
      <template #append>
        <v-btn size="x-small" variant="text" @click="documentStore.setSaveError()">ปิด</v-btn>
      </template>
    </v-alert>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';
import type { CSSProperties } from 'vue';
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
import { PageBreakExtension } from '../../extensions/PageBreakExtension';
import { ResizableImageExtension } from '../../extensions/ResizableImageExtension';
import { TableWithBlockIdExtension } from '../../extensions/TableWithBlockIdExtension';
import { useDocumentStore } from '../../stores/documentStore';
import { useReviewUiStore } from '../../stores/reviewUiStore';
import type { PageMargins } from '../../types/document';
import WorkflowStepper from '../shared/WorkflowStepper.vue';
import WorkflowFooterBar from '../shared/WorkflowFooterBar.vue';
import ReviewRuler from './ReviewRuler.vue';

type MarginPreset = 'normal' | 'narrow' | 'wide' | 'custom';
type MarginKey = keyof PageMargins;
type ReviewSavePayload = {
  draft_html?: string;
  page_margins?: PageMargins;
};

const PAGE_WIDTH_MM = 210;
const PAGE_MIN_HEIGHT_MM = 297;
const MM_TO_CSS_PX = 96 / 25.4;
const fontSizePresets = [12, 14, 16, 18, 20, 22, 24, 28, 36] as const;
const zoomPresets = [75, 100, 125, 150] as const;
const DEFAULT_PAGE_MARGINS: PageMargins = {
  top: 1440,
  bottom: 1440,
  left: 1800,
  right: 1800,
};
const MARGIN_PRESETS: Record<Exclude<MarginPreset, 'custom'>, PageMargins> = {
  normal: { ...DEFAULT_PAGE_MARGINS },
  narrow: { top: 720, bottom: 720, left: 720, right: 720 },
  wide: { top: 2160, bottom: 2160, left: 2160, right: 2160 },
};
const marginInputOrder: Array<{ key: MarginKey; label: string }> = [
  { key: 'top', label: 'บน' },
  { key: 'bottom', label: 'ล่าง' },
  { key: 'left', label: 'ซ้าย' },
  { key: 'right', label: 'ขวา' },
];

const props = defineProps<{ documentId: string; locked?: boolean }>();

const documentStore = useDocumentStore();
const reviewUiStore = useReviewUiStore();
const router = useRouter();

let autoSaveTimer: ReturnType<typeof setTimeout> | null = null;
let tableSyncTimer: ReturnType<typeof setTimeout> | null = null;
let pageResizeObserver: ResizeObserver | null = null;
let hasPendingDraftSave = false;
let pendingReviewPayload: ReviewSavePayload = {};

const tableWidths = new Map<string, number>();
const pageFrameRef = ref<HTMLElement | null>(null);
const pageHeightPx = ref(PAGE_MIN_HEIGHT_MM * MM_TO_CSS_PX);
const zoomPercent = ref<number>(100);
const activeHeadingLevel = ref<string>('0');
const activeFontFamilyOption = ref<string>('');
const fontSizeInput = ref<string>('16');
const pageMargins = ref<PageMargins>(normalizePageMargins(documentStore.review?.compose_state?.page_margins));
const marginInputsMm = ref<Record<MarginKey, string>>(pageMarginsToInputs(pageMargins.value));

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
    PageBreakExtension,
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
    syncToolbarState();
    queueMicrotask(refreshPageHeight);
  },
  onSelectionUpdate: () => {
    syncToolbarState();
  },
  onUpdate: () => {
    hasPendingDraftSave = true;
    reviewUiStore.setDirty(true);
    scheduleAutoSave();
    scheduleTableSync();
    syncToolbarState();
    queueMicrotask(refreshPageHeight);
  },
});

const fontSizePresetValue = computed<string>(() => (
  fontSizePresets.includes(Number(fontSizeInput.value) as (typeof fontSizePresets)[number])
    ? fontSizeInput.value
    : ''
));

const selectedMarginPreset = computed<MarginPreset>(() => detectMarginPreset(pageMargins.value));

const contentMm = computed<number>(() => (
  PAGE_WIDTH_MM - twipsToMm(pageMargins.value.left) - twipsToMm(pageMargins.value.right)
));



const editorStageStyle = computed<CSSProperties>(() => {
  const scale = zoomPercent.value / 100;
  const widthPx = PAGE_WIDTH_MM * MM_TO_CSS_PX * scale;
  const heightPx = Math.max(pageHeightPx.value, PAGE_MIN_HEIGHT_MM * MM_TO_CSS_PX) * scale;

  return {
    width: `${widthPx}px`,
    height: `${heightPx}px`,
    marginTop: '12px',
  };
});

const rulerStageStyle = computed<CSSProperties>(() => {
  const scale = zoomPercent.value / 100;
  const pageWidthPx = PAGE_WIDTH_MM * MM_TO_CSS_PX;
  const leftPx = twipsToMm(pageMargins.value.left) * MM_TO_CSS_PX;
  const rightPx = twipsToMm(pageMargins.value.right) * MM_TO_CSS_PX;

  return {
    width: `${pageWidthPx * scale}px`,
    paddingLeft: `${leftPx * scale}px`,
    paddingRight: `${rightPx * scale}px`,
  };
});

const pageFrameStyle = computed<CSSProperties>(() => {
  const scale = zoomPercent.value / 100;

  return {
    transform: `scale(${scale})`,
    '--page-margin-top': `${formatMillimeters(twipsToMm(pageMargins.value.top))}mm`,
    '--page-margin-bottom': `${formatMillimeters(twipsToMm(pageMargins.value.bottom))}mm`,
    '--page-margin-left': `${formatMillimeters(twipsToMm(pageMargins.value.left))}mm`,
    '--page-margin-right': `${formatMillimeters(twipsToMm(pageMargins.value.right))}mm`,
  } as CSSProperties;
});

watchEffect(() => {
  if (editor.value) {
    editor.value.setEditable(!(props.locked ?? false));
  }
});

onMounted(() => {
  attachPageObserver();
  refreshPageHeight();
});

onBeforeRouteLeave(async () => {
  if (autoSaveTimer) {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = null;
  }

  if (hasPendingSave()) {
    await saveDocument();
  }
});

onBeforeUnmount(() => {
  if (autoSaveTimer) {
    clearTimeout(autoSaveTimer);
    void saveDocument();
  }
  if (tableSyncTimer) clearTimeout(tableSyncTimer);
  pageResizeObserver?.disconnect();
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

function setFontFamily(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  if (value === '') {
    editor.value?.chain().focus().unsetFontFamily().run();
    return;
  }

  editor.value?.chain().focus().setFontFamily(value).run();
}

function commitFontSizeInput(): void {
  if (!editor.value) return;

  const parsed = Number(fontSizeInput.value);
  if (!Number.isFinite(parsed)) {
    fontSizeInput.value = String(resolveSelectionFontSize());
    return;
  }

  const pointSize = clampFontSizePt(parsed);
  fontSizeInput.value = String(pointSize);
  editor.value.chain().focus().setFontSize(`${pointSize}pt`).run();
}

function applyFontSizePreset(event: Event): void {
  const value = Number((event.target as HTMLSelectElement).value);
  if (!Number.isFinite(value)) return;

  const pointSize = clampFontSizePt(value);
  fontSizeInput.value = String(pointSize);
  editor.value?.chain().focus().setFontSize(`${pointSize}pt`).run();
}

function setLineHeight(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  if (value === '') {
    editor.value?.chain().focus().unsetLineHeight().run();
    return;
  }

  editor.value?.chain().focus().setLineHeight(value).run();
}

function setZoom(event: Event): void {
  const value = Number((event.target as HTMLSelectElement).value);
  if (!Number.isFinite(value)) return;
  zoomPercent.value = value;
}

function updateMarginPreset(event: Event): void {
  const preset = (event.target as HTMLSelectElement).value as MarginPreset;
  if (preset === 'custom') {
    marginInputsMm.value = pageMarginsToInputs(pageMargins.value);
    return;
  }

  applyPageMargins(MARGIN_PRESETS[preset]);
}

function updateCustomMargin(side: MarginKey, event: Event): void {
  const raw = (event.target as HTMLInputElement).value;
  marginInputsMm.value = { ...marginInputsMm.value, [side]: raw };

  const millimeters = Number(raw);
  if (!Number.isFinite(millimeters)) return;

  applyPageMargins({
    ...pageMargins.value,
    [side]: mmToTwips(millimeters),
  }, false);
}

function resetCustomMarginInput(side: MarginKey): void {
  marginInputsMm.value = {
    ...marginInputsMm.value,
    [side]: formatMillimeters(twipsToMm(pageMargins.value[side])),
  };
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
  if (!editor.value || !hasPendingSave()) return;

  const payload: ReviewSavePayload = {};
  if (hasPendingDraftSave) {
    payload.draft_html = editor.value.getHTML();
  }
  if (pendingReviewPayload.page_margins) {
    payload.page_margins = { ...pendingReviewPayload.page_margins };
  }

  const result = await documentStore.saveReview(payload);
  if (result !== null) {
    hasPendingDraftSave = false;
    pendingReviewPayload = {};
    reviewUiStore.setDirty(false);
  }
}

async function saveAndContinue(): Promise<void> {
  if (documentStore.saving) return;
  await saveDocument();
  if (reviewUiStore.isDirty) return;

  const progressed = await documentStore.completeWorkflowStep(2);
  if (!progressed) return;
  router.push(`/documents/${props.documentId}/rag`);
}

async function openPreview(): Promise<void> {
  if (hasPendingSave()) {
    await saveDocument();
  }

  router.push(`/documents/${props.documentId}/preview`);
}

function applyPageMargins(nextMargins: PageMargins, syncInputs = true): void {
  const normalized = normalizePageMargins(nextMargins);
  pageMargins.value = normalized;
  if (syncInputs) {
    marginInputsMm.value = pageMarginsToInputs(normalized);
  }

  if (documentStore.review?.compose_state) {
    documentStore.review.compose_state.page_margins = { ...normalized };
  }

  pendingReviewPayload = {
    ...pendingReviewPayload,
    page_margins: { ...normalized },
  };
  reviewUiStore.setDirty(true);
  scheduleAutoSave();
  queueMicrotask(refreshPageHeight);
}

function syncToolbarState(): void {
  if (!editor.value) return;

  activeHeadingLevel.value = detectActiveHeadingLevel();
  activeFontFamilyOption.value = normalizeFontFamilyValue(
    ((editor.value.getAttributes('textStyle') as { fontFamily?: string }).fontFamily) ?? '',
  );
  fontSizeInput.value = String(resolveSelectionFontSize());
}

function detectActiveHeadingLevel(): string {
  if (!editor.value) return '0';

  for (const level of [1, 2, 3] as const) {
    if (editor.value.isActive('heading', { level })) return String(level);
  }

  return '0';
}

function resolveSelectionFontSize(): number {
  if (!editor.value) return 16;

  const attributes = editor.value.getAttributes('textStyle') as { fontSize?: string };
  const explicit = parsePointSize(attributes.fontSize ?? '');
  if (explicit !== null) return explicit;

  return activeHeadingLevel.value === '1'
    ? 22
    : activeHeadingLevel.value === '2'
      ? 18
      : 16;
}

function attachPageObserver(): void {
  pageResizeObserver?.disconnect();
  if (!pageFrameRef.value) return;

  pageResizeObserver = new ResizeObserver(() => {
    refreshPageHeight();
  });
  pageResizeObserver.observe(pageFrameRef.value);
}

function refreshPageHeight(): void {
  if (!pageFrameRef.value) return;
  pageHeightPx.value = Math.max(pageFrameRef.value.offsetHeight, PAGE_MIN_HEIGHT_MM * MM_TO_CSS_PX);
}

function hasPendingSave(): boolean {
  return hasPendingDraftSave || pendingReviewPayload.page_margins !== undefined;
}

function normalizePageMargins(rawMargins?: Partial<PageMargins>): PageMargins {
  return {
    top: clampMarginTwips(rawMargins?.top, DEFAULT_PAGE_MARGINS.top),
    bottom: clampMarginTwips(rawMargins?.bottom, DEFAULT_PAGE_MARGINS.bottom),
    left: clampMarginTwips(rawMargins?.left, DEFAULT_PAGE_MARGINS.left),
    right: clampMarginTwips(rawMargins?.right, DEFAULT_PAGE_MARGINS.right),
  };
}

function detectMarginPreset(margins: PageMargins): MarginPreset {
  return (Object.entries(MARGIN_PRESETS).find(([, preset]) => marginsEqual(margins, preset))?.[0] as MarginPreset | undefined)
    ?? 'custom';
}

function marginsEqual(left: PageMargins, right: PageMargins): boolean {
  return left.top === right.top
    && left.bottom === right.bottom
    && left.left === right.left
    && left.right === right.right;
}

function pageMarginsToInputs(margins: PageMargins): Record<MarginKey, string> {
  return {
    top: formatMillimeters(twipsToMm(margins.top)),
    bottom: formatMillimeters(twipsToMm(margins.bottom)),
    left: formatMillimeters(twipsToMm(margins.left)),
    right: formatMillimeters(twipsToMm(margins.right)),
  };
}

function twipsToMm(twips: number): number {
  return (twips / 1440) * 25.4;
}

function mmToTwips(millimeters: number): number {
  return clampMarginTwips(Math.round((millimeters / 25.4) * 1440), 0);
}

function clampMarginTwips(value: unknown, fallback: number): number {
  const numeric = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(numeric)) return fallback;
  return Math.min(5760, Math.max(0, Math.round(numeric)));
}

function clampFontSizePt(value: number): number {
  return Math.min(72, Math.max(8, Math.round(value)));
}

function parsePointSize(raw: string): number | null {
  const trimmed = raw.trim().toLowerCase();
  if (trimmed === '') return null;

  if (trimmed.endsWith('pt')) {
    const value = Number(trimmed.slice(0, -2));
    return Number.isFinite(value) ? clampFontSizePt(value) : null;
  }

  const numeric = Number(trimmed);
  return Number.isFinite(numeric) ? clampFontSizePt(numeric) : null;
}

function normalizeFontFamilyValue(value: string): string {
  const normalized = stripFontFamily(value);

  for (const option of [
    "'TH Sarabun New', 'Sarabun', sans-serif",
    "'TH Sarabun PSK', 'Sarabun', sans-serif",
    "'TH SarabunIT9', 'Sarabun', sans-serif",
    "'CordiaNew', 'Sarabun', sans-serif",
    "'Sarabun', sans-serif",
  ]) {
    if (stripFontFamily(option) === normalized) {
      return option;
    }
  }

  return '';
}

function stripFontFamily(value: string): string {
  return value
    .replace(/['"]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
}

function formatMillimeters(value: number): string {
  const rounded = Math.round(value * 100) / 100;
  return Number.isInteger(rounded)
    ? String(rounded)
    : rounded.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}
</script>

<style scoped>
.toolbar-select,
.toolbar-number-input,
.toolbar-mm-input {
  height: 28px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 0 8px;
  font-size: 12px;
  background: #f8fafc;
  color: #0f172a;
}

.toolbar-select {
  cursor: pointer;
  min-width: 90px;
}

.toolbar-select--font {
  min-width: 150px;
}

.toolbar-select--preset {
  min-width: 96px;
}

.toolbar-number-input {
  width: 70px;
}

.toolbar-mm-input {
  width: 76px;
}

.editor-shell-scroll {
  overflow: auto;
  padding: 0 24px 24px;
}

.editor-stage {
  margin: 0 auto;
  position: relative;
}

.ruler-stage {
  position: sticky;
  top: 0;
  z-index: 5;
  margin: 0 auto;
  box-sizing: border-box;
  background: #f8fafc;
  padding-top: 4px;
  padding-bottom: 6px;
}

.a4-page {
  --page-margin-top: 25.4mm;
  --page-margin-bottom: 25.4mm;
  --page-margin-left: 31.75mm;
  --page-margin-right: 31.75mm;
  width: 210mm;
  min-height: 297mm;
  padding: var(--page-margin-top) var(--page-margin-right) var(--page-margin-bottom) var(--page-margin-left);
  background: #fff;
  box-shadow: 0 0 0 1px #e2e8f0, 0 18px 48px rgba(15, 23, 42, 0.08);
  box-sizing: border-box;
  transform-origin: top center;
  position: relative;
}


.editor-shell-content {
  width: 100%;
  height: 100%;
}

.editor-shell-content :deep(.ProseMirror) {
  min-height: calc(297mm - var(--page-margin-top) - var(--page-margin-bottom));
  font-family: 'TH Sarabun PSK', 'TH Sarabun New', 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 16pt;
  line-height: 1.85;
  color: #1e293b;
  outline: none;
}

.editor-shell-content :deep(.ProseMirror h1) {
  font-size: 22pt;
  font-weight: 700;
  margin: 16pt 0 8pt;
}

.editor-shell-content :deep(.ProseMirror h2) {
  font-size: 18pt;
  font-weight: 700;
  margin: 14pt 0 7pt;
}

.editor-shell-content :deep(.ProseMirror h3) {
  font-size: 16pt;
  font-weight: 700;
  margin: 12pt 0 6pt;
}

.editor-shell-content :deep(.ProseMirror p) {
  margin: 0 0 8pt;
}

.editor-shell-content :deep(.ProseMirror [data-page-break]) {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 12pt 0;
  color: #94a3b8;
  font-size: 10pt;
  font-family: sans-serif;
}

.editor-shell-content :deep(.ProseMirror [data-page-break])::before,
.editor-shell-content :deep(.ProseMirror [data-page-break])::after {
  content: '';
  flex: 1;
  height: 0;
  border-top: 1.5px dashed #cbd5e1;
}

.editor-shell-content :deep(.ProseMirror ul),
.editor-shell-content :deep(.ProseMirror ol) {
  padding-left: 18pt;
  margin: 8pt 0;
}

.editor-shell-content :deep(.ProseMirror li) {
  margin: 4pt 0;
}

.editor-shell-content :deep(.ProseMirror mark) {
  background: #fff085;
  border-radius: 2px;
}

.editor-shell-content :deep(.ProseMirror table) {
  border-collapse: collapse;
  width: 100%;
  margin: 12pt 0;
  table-layout: fixed;
  word-break: break-word;
}

.editor-shell-content :deep(.ProseMirror th),
.editor-shell-content :deep(.ProseMirror td) {
  border: 1pt solid #000;
  padding: 7pt 10pt;
  vertical-align: top;
  position: relative;
}

.editor-shell-content :deep(.ProseMirror th) {
  background: #fff;
  font-weight: 700;
}

.editor-shell-content :deep(.ProseMirror .tableWrapper) {
  overflow-x: auto;
  margin: 12pt 0;
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
  margin: 8pt 0;
  border: 1px solid #e1e8f5;
  border-radius: 4px;
}

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

.editor-shell-content :deep(.ProseMirror .doc-tab) {
  display: inline-block;
  min-width: 2rem;
  height: 1em;
  line-height: 1;
  vertical-align: text-bottom;
  border-bottom: 1px dotted rgba(25, 118, 210, 0.25);
}

@media (max-width: 960px) {
  .editor-shell-scroll {
    padding: 16px 8px 24px;
  }
}
</style>
