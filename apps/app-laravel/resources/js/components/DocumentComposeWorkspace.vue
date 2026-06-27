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
        @update:model-value="onMetadataUpdate"
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
          @action="dispatchToolbarAction"
          @reload="reloadReview"
          @toggle:navigator="leftDrawer = !leftDrawer"
          @toggle:details="rightDrawer = !rightDrawer"
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
          @select-block="selectedBlockId = $event"
          @visible-block-change="selectedBlockId = $event"
          @block-saved="reloadReview"
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
import { fetchReview, updateComposeState } from '../api/client';
import type { ComposeState, DocumentMetadata, ReviewDocument, ThaiFont } from '../types/document';

interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList';
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
const selectedBlockId = ref<string | null>(null);
const scrollTarget = ref<ScrollTarget | null>(null);
const font = ref<ThaiFont>('sarabun');
const fontSize = ref(16);
const metadata = ref<DocumentMetadata>(defaultMetadata());
const hydrating = ref(false);
const autoSaveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const autoSaveMessage = ref('ยังไม่มีการแก้ไข');
const toolbarCommand = ref<ToolbarCommand | null>(null);
const editorState = ref<EditorStateSnapshot>({
  active: false,
  canUndo: false,
  canRedo: false,
  isBold: false,
  isItalic: false,
  isUnderline: false,
  isBulletList: false,
  isOrderedList: false,
});
const leftDrawer = ref(true);
const rightDrawer = ref(true);

let saveTimer: ReturnType<typeof setTimeout> | null = null;
let toolbarCommandId = 0;
let scrollRequestId = 0;

const isCompact = computed(() => mdAndDown.value);

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
});

onBeforeUnmount(() => {
  if (saveTimer) {
    clearTimeout(saveTimer);
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

function dispatchToolbarAction(type: ToolbarCommand['type']): void {
  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type };
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
