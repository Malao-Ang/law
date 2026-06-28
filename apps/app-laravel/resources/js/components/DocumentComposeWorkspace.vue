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
        :review="review"
        :document-id="documentId"
        :save-message="autoSaveLabel"
        :correction-status="docStatus?.correction_status ?? null"
        :export-busy="exporting"
        @update:model-value="onMetadataUpdate"
        @export="triggerExport"
        @reload="reloadReview"
      />
    </v-navigation-drawer>

    <v-main class="document-compose-main">
      <div v-if="loading" class="compose-state-card">
        <v-progress-circular indeterminate color="primary" />
        <p>กำลังโหลดเอกสารสำหรับจัดรูปแบบ...</p>
      </div>

      <div v-else-if="error" class="compose-state-card compose-state-card--error">
        <v-icon icon="mdi-alert-circle-outline" size="32" />
        <p>{{ error }}</p>
        <v-btn variant="outlined" prepend-icon="mdi-refresh" @click="reloadReview">รีโหลดข้อมูล</v-btn>
      </div>

      <section v-else-if="review" class="document-compose-shell">
        <div v-if="correctionInProgress" class="compose-correction-banner">
          <v-progress-circular indeterminate size="18" color="primary" />
          <span>กำลังปรับปรุงด้วย AI ระบบจะรีเฟรชอัตโนมัติเมื่อเสร็จ</span>
        </div>
        <div v-else-if="correctionFailed" class="compose-correction-banner compose-correction-banner--error">
          <v-icon icon="mdi-alert-circle-outline" size="18" />
          <span>AI correction failed. คุณยังแก้ไขเอกสารต่อได้ แต่ Export จะถูกบล็อกไว้</span>
        </div>

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
          :document-id="documentId"
          :blocks="flatBlocks"
          :selected-block-id="selectedBlockId"
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
        />
      </section>
    </v-main>
  </v-layout>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useDisplay } from 'vuetify';
import ComposeMetadataPanel from './ComposeMetadataPanel.vue';
import ComposeSectionEditor from './ComposeSectionEditor.vue';
import ComposeSectionNavigator from './ComposeSectionNavigator.vue';
import ComposeToolbar from './ComposeToolbar.vue';
import { exportDocument, fetchReview, fetchStatus, updateComposeState } from '../api/client';
import type { ComposeState, DocumentMetadata, DocumentStatus, ReviewDocument, ThaiFont } from '../types/document';

interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'saveAll' | 'cancelAll' | 'indent' | 'outdent' | 'setAlignment';
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
const loading = ref(true);
const error = ref('');
const review = ref<ReviewDocument | null>(null);
const docStatus = ref<DocumentStatus | null>(null);
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
const exporting = ref(false);

let saveTimer: ReturnType<typeof setTimeout> | null = null;
let correctionPollTimer: ReturnType<typeof setTimeout> | null = null;
let toolbarCommandId = 0;
let scrollRequestId = 0;

const isCompact = computed(() => mdAndDown.value);
const correctionInProgress = computed(() =>
  ['pending', 'in_progress'].includes(docStatus.value?.correction_status ?? ''),
);
const correctionFailed = computed(() =>
  docStatus.value?.correction_status === 'failed',
);

const pageTitle = computed(() => (
  props.mode === 'compose' ? 'Compose Editor' : 'Review Editor'
));

const pageSubtitle = computed(() => {
  if (!review.value) {
    return 'กำลังโหลดข้อมูลเอกสาร';
  }

  const status = review.value.summary.review_required_count > 0
    ? `${review.value.summary.review_required_count} รายการรอตรวจทาน`
    : 'ตรวจทานครบแล้ว';

  return `${review.value.source_file} · ${review.value.summary.block_count} บล็อก · ${status}`;
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
  review.value?.pages.flatMap((page) => page.blocks.map((block) => ({ page_no: page.page_no, block }))) ?? [],
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
  if (autoSaveState.value === 'saved') return autoSaveMessage.value;
  if (autoSaveState.value === 'error') return autoSaveMessage.value;
  return autoSaveMessage.value;
});

watch(isCompact, (next) => {
  leftDrawer.value = !next;
  rightDrawer.value = !next;
}, { immediate: true });

watch([font, fontSize], () => {
  if (!hydrating.value && review.value) {
    scheduleComposeSave();
  }
});

onMounted(async () => {
  await reloadReview();
  void pollCorrectionStatus();
});

onBeforeUnmount(() => {
  if (saveTimer) {
    clearTimeout(saveTimer);
  }
  if (correctionPollTimer) {
    clearTimeout(correctionPollTimer);
  }
});

async function reloadReview(): Promise<void> {
  loading.value = true;
  error.value = '';
  const currentSelected = selectedBlockId.value;

  try {
    review.value = await fetchReview(props.documentId);
    applyComposeState(review.value.compose_state);

    const availableIds = new Set(
      review.value.pages.flatMap((page) => page.blocks.map((block) => block.block_id)),
    );

    selectedBlockId.value = currentSelected && availableIds.has(currentSelected)
      ? currentSelected
      : review.value.pages.flatMap((page) => page.blocks)[0]?.block_id ?? null;

    if (selectedBlockId.value) {
      requestScrollToBlock(selectedBlockId.value);
    }
  } catch (nextError) {
    error.value = nextError instanceof Error ? nextError.message : 'Failed to load compose editor';
  } finally {
    loading.value = false;
  }
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

  if (isCompact.value) {
    leftDrawer.value = false;
  }
}

function requestScrollToBlock(blockId: string): void {
  scrollRequestId += 1;
  scrollTarget.value = { blockId, requestId: scrollRequestId };
}

function onMetadataUpdate(next: DocumentMetadata): void {
  metadata.value = next;
  if (!hydrating.value && review.value) {
    scheduleComposeSave();
  }
}

function dispatchToolbarAction(type: string, value?: string): void {
  if (type === 'export') {
    void triggerExport();
    return;
  }

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

  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type: type as ToolbarCommand['type'], value };
}

function handleToggleEditMode(): void {
  editMode.value = true;
}

function handleEditCancelled(): void {
  editMode.value = false;
  void reloadReview();
}

function onAllBlocksSaved(): void {
  // Stay in edit mode — user can keep editing without a reload
}

async function pollCorrectionStatus(): Promise<void> {
  try {
    docStatus.value = await fetchStatus(props.documentId);
  } catch {
    correctionPollTimer = setTimeout(() => {
      void pollCorrectionStatus();
    }, 2500);
    return;
  }

  if (correctionInProgress.value) {
    correctionPollTimer = setTimeout(() => {
      void pollCorrectionStatus();
    }, 2500);
  } else if (docStatus.value?.correction_status === 'done') {
    await reloadReview();
  }
}

async function triggerExport(): Promise<void> {
  if (exporting.value || correctionInProgress.value || correctionFailed.value) {
    return;
  }

  exporting.value = true;

  try {
    await exportDocument(props.documentId);
    docStatus.value = await fetchStatus(props.documentId);
  } catch (nextError) {
    error.value = nextError instanceof Error ? nextError.message : 'Export failed';
  } finally {
    exporting.value = false;
  }
}

function scheduleComposeSave(): void {
  autoSaveState.value = 'idle';
  autoSaveMessage.value = 'รอบันทึกการเปลี่ยนแปลง';

  if (saveTimer) {
    clearTimeout(saveTimer);
  }

  saveTimer = setTimeout(() => {
    void persistComposeState();
  }, 600);
}

async function persistComposeState(): Promise<void> {
  if (!review.value) {
    return;
  }

  autoSaveState.value = 'saving';

  try {
    const response = await updateComposeState(props.documentId, {
      font_family: font.value,
      font_size_pt: fontSize.value,
      metadata: metadata.value,
    });

    if (response.compose_state) {
      review.value.compose_state = response.compose_state;
    }

    autoSaveState.value = 'saved';
    autoSaveMessage.value = 'บันทึกอัตโนมัติแล้ว';
  } catch (nextError) {
    autoSaveState.value = 'error';
    autoSaveMessage.value = nextError instanceof Error ? nextError.message : 'บันทึกอัตโนมัติไม่สำเร็จ';
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
