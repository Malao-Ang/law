<template>
  <v-layout class="document-compose-workspace">
    <v-navigation-drawer
      v-model="leftDrawer"
      class="compose-drawer"
      :temporary="isCompact"
      width="340"
    >
      <ComposeSectionNavigator
        :items="navigatorItems"
        :selected-block-id="selectedBlockId"
        @select="selectBlockFromNavigator"
      />
    </v-navigation-drawer>

    <v-navigation-drawer
      v-model="rightDrawer"
      class="compose-drawer compose-drawer--right"
      :temporary="isCompact"
      location="right"
      width="390"
    >
      <ComposeMetadataPanel
        :model-value="metadata"
        :review="composeStore.review"
        :document-id="documentId"
        :save-message="autoSaveLabel"
        :correction-status="composeStore.docStatus?.correction_status ?? null"
        :export-busy="composeStore.exporting"
        @update:model-value="onMetadataUpdate"
        @export="triggerExport"
        @reload="reloadReview"
      />
    </v-navigation-drawer>

    <v-main class="document-compose-main">
      <div v-if="composeStore.loading" class="compose-state-card">
        <v-progress-circular indeterminate color="primary" />
        <p>กำลังโหลดเอกสารสำหรับจัดรูปแบบ...</p>
      </div>

      <div v-else-if="composeStore.error" class="compose-state-card compose-state-card--error">
        <v-icon icon="mdi-alert-circle-outline" size="32" />
        <p>{{ composeStore.error }}</p>
        <v-btn variant="outlined" prepend-icon="mdi-refresh" @click="reloadReview">รีโหลดข้อมูล</v-btn>
      </div>

      <section v-else-if="composeStore.review" class="document-compose-shell">
        <div v-if="correctionInProgress" class="compose-correction-banner">
          <v-progress-circular indeterminate size="18" color="primary" />
          <span>กำลังปรับปรุงด้วย AI ระบบจะรีเฟรชอัตโนมัติเมื่อเสร็จ</span>
        </div>
        <div v-else-if="correctionFailed" class="compose-correction-banner compose-correction-banner--error">
          <v-icon icon="mdi-alert-circle-outline" size="18" />
          <span>AI correction failed. คุณยังแก้ไขเอกสารต่อได้ แต่ Export จะถูกบล็อกไว้</span>
        </div>

        <ComposeBlockSelectionBar
          :count="selectedBlockIds.size"
          :busy="blockOpBusy"
          @merge="handleMergeSelected"
          @create-after="handleCreateAfterSelected"
          @delete="handleDeleteSelected"
          @clear="selectedBlockIds = new Set()"
        />

        <ComposeToolbar
          :title="pageTitle"
          :subtitle="pageSubtitle"
          :font="font"
          :font-size="fontSize"
          :editor-state="editorState"
          :auto-save-state="autoSaveState"
          :auto-save-label="autoSaveLabel"
          :alternate-route-label="alternateRouteLabel"
          :alternate-route="alternateRoute"
          :correction-in-progress="correctionInProgress || correctionFailed"
          :edit-mode="editMode"
          @action="dispatchToolbarAction"
          @reload="reloadReview"
          @toggle:navigator="leftDrawer = !leftDrawer"
          @toggle:details="rightDrawer = !rightDrawer"
          @toggle:editMode="handleToggleEditMode"
          @update:font="font = $event"
          @update:font-size="fontSize = $event"
        />

        <ComposeSectionEditor
          ref="sectionEditor"
          :document-id="documentId"
          :blocks="flatBlocks"
          :selected-block-id="selectedBlockId"
          :selected-block-ids="selectedBlockIds"
          :scroll-target="scrollTarget"
          :font="font"
          :font-size="fontSize"
          :toolbar-command="toolbarCommand"
          :edit-mode="editMode"
          :mode="mode"
          @select-block="selectedBlockId = $event"
          @visible-block-change="selectedBlockId = $event"
          @all-blocks-saved="onAllBlocksSaved"
          @edit-cancelled="handleEditCancelled"
          @editor-state="editorState = $event"
          @selected-blocks-change="selectedBlockIds = $event"
          @split-block="handleSplitBlock"
        />

        <ComposeFooterBar
          :saving="autoSaveState === 'saving'"
          :has-unsaved="hasUnsaved"
          :save-label="autoSaveLabel"
          :preview-href="`/documents/${documentId}/preview`"
          @save="handleFooterSave"
          @preview="handleFooterPreview"
        />
      </section>
    </v-main>
  </v-layout>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useDisplay } from 'vuetify';
import ComposeBlockSelectionBar from './ComposeBlockSelectionBar.vue';
import ComposeFooterBar from './ComposeFooterBar.vue';
import ComposeMetadataPanel from './ComposeMetadataPanel.vue';
import ComposeSectionEditor from './ComposeSectionEditor.vue';
import ComposeSectionNavigator from './ComposeSectionNavigator.vue';
import ComposeToolbar from './ComposeToolbar.vue';
import { useComposeStore } from '../../stores/composeStore';
import { useBlockStore } from '../../stores/blockStore';
import type { ComposeState, DocumentMetadata, ThaiFont } from '../../types/document';

interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'saveAll' | 'cancelAll' | 'indent' | 'outdent' | 'setAlignment' | 'splitBlock';
  value?: string;
}

interface EditorStateSnapshot {
  active: boolean;
  canUndo: boolean;
  canRedo: boolean;
  isBold: boolean;
  isItalic: boolean;
  isUnderline: boolean;
  isBulletList: boolean;
  isOrderedList: boolean;
  alignment: 'left' | 'center' | 'right' | 'justify';
}

interface ScrollTarget {
  blockId: string;
  requestId: number;
}

const props = withDefaults(defineProps<{
  documentId: string;
  mode?: 'review' | 'compose';
}>(), {
  mode: 'review',
});

const { mdAndDown } = useDisplay();
const vueRouter = useRouter();
const composeStore = useComposeStore();
const blockStore = useBlockStore();

const selectedBlockId = ref<string | null>(null);
const scrollTarget = ref<ScrollTarget | null>(null);
const font = ref<ThaiFont>('sarabun');
const fontSize = ref(16);
const metadata = ref<DocumentMetadata>(defaultMetadata());
const hydrating = ref(false);
const autoSaveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const autoSaveMessage = ref('ยังไม่มีการแก้ไข');
const toolbarCommand = ref<ToolbarCommand | null>(null);
const editMode = ref(false);
const selectedBlockIds = ref(new Set<string>());
const blockOpBusy = ref(false);
const sectionEditor = ref<InstanceType<typeof ComposeSectionEditor> | null>(null);
const editorState = ref<EditorStateSnapshot>({
  active: false,
  canUndo: false,
  canRedo: false,
  isBold: false,
  isItalic: false,
  isUnderline: false,
  isBulletList: false,
  isOrderedList: false,
  alignment: 'left',
});
const leftDrawer = ref(true);
const rightDrawer = ref(true);

let saveTimer: ReturnType<typeof setTimeout> | null = null;
let correctionPollTimer: ReturnType<typeof setTimeout> | null = null;
let toolbarCommandId = 0;
let scrollRequestId = 0;
let mounted = true;

const isCompact = computed(() => mdAndDown.value);
const correctionInProgress = computed(() =>
  ['pending', 'in_progress'].includes(composeStore.docStatus?.correction_status ?? ''),
);
const correctionFailed = computed(() =>
  composeStore.docStatus?.correction_status === 'failed',
);

const pageTitle = computed(() => (
  props.mode === 'compose' ? 'Compose Editor' : 'Review Editor'
));

const pageSubtitle = computed(() => {
  if (!composeStore.review) return 'กำลังโหลดข้อมูลเอกสาร';
  const status = composeStore.review.summary.review_required_count > 0
    ? `${composeStore.review.summary.review_required_count} รายการรอตรวจทาน`
    : 'ตรวจทานครบแล้ว';
  return `${composeStore.review.source_file} · ${composeStore.review.summary.block_count} บล็อก · ${status}`;
});

const alternateRouteLabel = computed(() => (
  props.mode === 'compose' ? 'Review' : 'Compose'
));

const alternateRoute = computed(() => (
  props.mode === 'compose'
    ? `/documents/${props.documentId}/review`
    : `/documents/${props.documentId}/compose`
));

const flatBlocks = computed(() =>
  composeStore.review?.pages.flatMap((page) => page.blocks.map((block) => ({ page_no: page.page_no, block }))) ?? [],
);

const navigatorItems = computed(() =>
  flatBlocks.value.map((item) => ({
    blockId: item.block.block_id,
    label: item.block.meta.list_marker?.text || item.block.approved_text || item.block.normalized_text || 'บล็อกเอกสาร',
    subtitle: `หน้า ${item.page_no} · ลำดับ ${item.block.reading_order}`,
    pageNo: item.page_no,
    type: item.block.type,
    status: item.block.needs_review ? 'warning' as const : (item.block.type === 'section_header' || item.block.type === 'title' ? 'info' as const : 'clean' as const),
  })),
);

const autoSaveLabel = computed(() => {
  if (autoSaveState.value === 'saving') return 'กำลังบันทึกอัตโนมัติ...';
  return autoSaveMessage.value;
});

const hasUnsaved = computed(() => autoSaveMessage.value === 'รอบันทึกการเปลี่ยนแปลง');

watch(isCompact, (next) => {
  leftDrawer.value = !next;
  rightDrawer.value = !next;
}, { immediate: true });

watch([font, fontSize], () => {
  if (!hydrating.value && composeStore.review) {
    scheduleComposeSave();
  }
});

onMounted(async () => {
  await reloadReview();
  void pollCorrectionStatus();
});

onBeforeUnmount(() => {
  mounted = false;
  if (saveTimer) clearTimeout(saveTimer);
  if (correctionPollTimer) clearTimeout(correctionPollTimer);
  composeStore.reset();
});

async function reloadReview(): Promise<void> {
  const currentSelected = selectedBlockId.value;
  await composeStore.fetch(props.documentId);
  if (!mounted || !composeStore.review) return;
  applyComposeState(composeStore.review.compose_state);

  const availableIds = new Set(
    composeStore.review.pages.flatMap((page) => page.blocks.map((block) => block.block_id)),
  );
  selectedBlockIds.value = new Set([...selectedBlockIds.value].filter((id) => availableIds.has(id)));
  selectedBlockId.value = currentSelected && availableIds.has(currentSelected)
    ? currentSelected
    : composeStore.review.pages.flatMap((page) => page.blocks)[0]?.block_id ?? null;
  if (selectedBlockId.value) requestScrollToBlock(selectedBlockId.value);
}

function applyComposeState(state?: ComposeState): void {
  hydrating.value = true;
  font.value = state?.font_family ?? 'sarabun';
  fontSize.value = state?.font_size_pt ?? 16;
  metadata.value = { ...defaultMetadata(), ...(state?.metadata ?? {}) };
  autoSaveState.value = 'idle';
  autoSaveMessage.value = 'พร้อมบันทึกอัตโนมัติ';
  queueMicrotask(() => {
    hydrating.value = false;
  });
}

function selectBlockFromNavigator(blockId: string): void {
  selectedBlockId.value = blockId;
  requestScrollToBlock(blockId);
  if (isCompact.value) leftDrawer.value = false;
}

function requestScrollToBlock(blockId: string): void {
  scrollRequestId += 1;
  scrollTarget.value = { blockId, requestId: scrollRequestId };
}

function onMetadataUpdate(next: DocumentMetadata): void {
  metadata.value = next;
  if (!hydrating.value && composeStore.review) scheduleComposeSave();
}

function dispatchToolbarAction(type: string, value?: string): void {
  if (type === 'export') { void triggerExport(); return; }
  if (type === 'splitBlock') { sectionEditor.value?.executeSplitAtCursor(); return; }
  if (type === 'saveAll') {
    toolbarCommandId += 1;
    toolbarCommand.value = { id: toolbarCommandId, type: 'saveAll' };
    return;
  }
  if (type === 'cancelAll') {
    toolbarCommandId += 1;
    toolbarCommand.value = { id: toolbarCommandId, type: 'cancelAll' };
    return;
  }
  const commandType = type as ToolbarCommand['type'];
  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type: commandType, value };
}

function handleToggleEditMode(): void {
  if (editMode.value) return;
  editMode.value = true;
}

function handleEditCancelled(): void {
  editMode.value = false;
  void reloadReview();
}

async function handleSplitBlock(payload: {
  blockId: string;
  pageNo: number;
  beforeText: string;
  beforeHtml: string;
  afterText: string;
  afterHtml: string;
}): Promise<void> {
  if (blockOpBusy.value) return;
  blockOpBusy.value = true;
  try {
    await blockStore.split(props.documentId, payload.blockId, {
      page_no: payload.pageNo,
      before_text: payload.beforeText,
      before_html: payload.beforeHtml,
      after_text: payload.afterText,
      after_html: payload.afterHtml,
    });
    await reloadReview();
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'แยกบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}

async function handleMergeSelected(): Promise<void> {
  if (selectedBlockIds.value.size < 2 || blockOpBusy.value) return;
  blockOpBusy.value = true;
  try {
    const ordered = flatBlocks.value
      .filter((item) => selectedBlockIds.value.has(item.block.block_id))
      .map((item) => item.block.block_id);
    await blockStore.merge(props.documentId, ordered);
    selectedBlockIds.value = new Set();
    await reloadReview();
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'รวมบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}

async function handleDeleteSelected(): Promise<void> {
  if (selectedBlockIds.value.size === 0 || blockOpBusy.value) return;
  blockOpBusy.value = true;
  try {
    for (const item of flatBlocks.value) {
      if (selectedBlockIds.value.has(item.block.block_id)) {
        await blockStore.remove(props.documentId, item.block.block_id, item.page_no);
      }
    }
    selectedBlockIds.value = new Set();
    await reloadReview();
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'ลบบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}

async function handleCreateAfterSelected(): Promise<void> {
  const lastSelected = flatBlocks.value
    .filter((item) => selectedBlockIds.value.has(item.block.block_id))
    .at(-1);
  if (!lastSelected) return;
  blockOpBusy.value = true;
  try {
    await blockStore.create(props.documentId, {
      page_no: lastSelected.page_no,
      after_block_id: lastSelected.block.block_id,
      type: 'paragraph',
      approved_text: '',
      reviewed_html: '<p></p>',
    });
    selectedBlockIds.value = new Set();
    await reloadReview();
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'เพิ่มบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}

function onAllBlocksSaved(): void {
  // Stay in edit mode — user can keep editing without a reload
}

function handleFooterSave(): void {
  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type: 'saveAll' };
}

function handleFooterPreview(): void {
  void vueRouter.push(`/documents/${props.documentId}/preview`);
}

async function pollCorrectionStatus(): Promise<void> {
  const ok = await composeStore.pollStatus(props.documentId);
  if (!ok) {
    correctionPollTimer = setTimeout(() => void pollCorrectionStatus(), 2500);
    return;
  }
  if (correctionInProgress.value) {
    correctionPollTimer = setTimeout(() => void pollCorrectionStatus(), 2500);
  } else if (composeStore.docStatus?.correction_status === 'done') {
    await reloadReview();
  }
}

async function triggerExport(): Promise<void> {
  if (composeStore.exporting || correctionInProgress.value || correctionFailed.value) return;
  await composeStore.triggerExport(props.documentId);
}

function scheduleComposeSave(): void {
  autoSaveState.value = 'idle';
  autoSaveMessage.value = 'รอบันทึกการเปลี่ยนแปลง';
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = setTimeout(() => void persistComposeState(), 600);
}

async function persistComposeState(): Promise<void> {
  if (!composeStore.review) return;
  autoSaveState.value = 'saving';
  const { saved, errorMessage } = await composeStore.saveComposeState(props.documentId, {
    font_family: font.value,
    font_size_pt: fontSize.value,
    metadata: metadata.value,
  });
  if (saved) {
    autoSaveState.value = 'saved';
    autoSaveMessage.value = 'บันทึกอัตโนมัติแล้ว';
  } else {
    autoSaveState.value = 'error';
    autoSaveMessage.value = errorMessage;
  }
}

function defaultMetadata(): DocumentMetadata {
  return {
    department: '',
    doc_number: '',
    date: '',
    subject: '',
    recipient: '',
    reference: '',
    attachments: '',
    urgency: '',
    confidentiality: '',
    signatory_name: '',
    signatory_position: '',
  };
}
</script>

<style scoped>
.compose-correction-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  background: var(--law-primary-soft);
  color: var(--law-primary);
  font-size: 13px;
  border-bottom: 1px solid var(--law-border);
}

.compose-correction-banner--error {
  background: #fef2f2;
  color: var(--law-danger);
}
</style>
