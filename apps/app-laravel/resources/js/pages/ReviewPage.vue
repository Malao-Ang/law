<template>
  <section class="review-shell">
    <header class="review-topbar">
      <button
        class="pane-toggle"
        :title="leftCollapsed ? 'Show blocks' : 'Hide blocks'"
        @click="leftCollapsed = !leftCollapsed"
      >{{ leftCollapsed ? '▶' : '◀' }}</button>

      <div class="review-title">
        <h2>Review Document</h2>
        <p v-if="review" class="hint">
          {{ review.source_file }} · {{ review.source_type }} · {{ review.summary.block_count }} blocks
          <span v-if="documentStatus"> · Pipeline: {{ documentStatus.status }}</span>
        </p>
        <p v-else class="hint">Loading review data...</p>
      </div>

      <div class="review-topbar-actions">
        <p v-if="exportMessage" class="hint">{{ exportMessage }}</p>
      </div>

      <button
        class="pane-toggle"
        :title="rightCollapsed ? 'Show metadata' : 'Hide metadata'"
        @click="rightCollapsed = !rightCollapsed"
      >{{ rightCollapsed ? '◀' : '▶' }}</button>
    </header>

    <div
      v-if="review"
      class="review-grid"
      :class="{ 'left-collapsed': leftCollapsed, 'right-collapsed': rightCollapsed }"
    >
      <section class="list-panel">
        <div class="pane-inner">
          <MatraToc
            :items="matraBlocks"
            :current-block-id="currentMatraBlockId"
            @jump="scrollToBlock"
          />
          <p class="hint" style="margin-bottom:0.25rem;">คลิก block เพื่อแก้ไข · ใช้ปุ่ม +/− ปรับระดับ indent</p>
          <DocumentBlockEditor
            :document-id="documentId"
            :pages="review.pages"
            :selected-block-id="selected?.block.block_id ?? null"
            @select-block="(pageNo, block) => { selected = { page_no: pageNo, block } }"
            @layout-updated="() => reloadReview({ preserveLocalHtml: documentHtmlDirty })"
            @block-saved="() => reloadReview({ preserveLocalHtml: documentHtmlDirty })"
            @current-block-change="onCurrentBlockChange"
          />
        </div>
      </section>

      <section class="editor-panel">
        <div class="pane-inner">
          <section class="panel review-center-panel">
            <div class="review-center-toolbar">
              <div class="review-mode-toggle">
                <button
                  class="btn btn-tiny"
                  :class="{ 'btn-primary': centerMode === 'viewer' }"
                  @click="centerMode = 'viewer'"
                >Page Review</button>
                <button
                  class="btn btn-tiny"
                  :class="{ 'btn-primary': centerMode === 'document' }"
                  @click="centerMode = 'document'"
                >Document HTML</button>
              </div>

              <div v-if="centerMode === 'viewer'" class="review-mode-toggle">
                <button
                  class="btn btn-tiny"
                  :class="{ 'btn-primary': pageViewerMode === 'original' }"
                  :disabled="!selectedPage?.image_url"
                  @click="pageViewerMode = 'original'"
                >Original</button>
                <button
                  class="btn btn-tiny"
                  :class="{ 'btn-primary': pageViewerMode === 'overlay' }"
                  :disabled="!selectedPage?.image_url"
                  @click="pageViewerMode = 'overlay'"
                >OCR Overlay</button>
                <button
                  class="btn btn-tiny"
                  :class="{ 'btn-primary': pageViewerMode === 'html' }"
                  @click="pageViewerMode = 'html'"
                >Extracted HTML</button>
              </div>
            </div>

            <DocumentViewer
              v-if="centerMode === 'viewer'"
              :page="selectedPage"
              :block="selected?.block ?? null"
              :mode="pageViewerMode"
              @select-block="selectBlockById"
            />

            <DocumentHtmlEditor
              v-else
              v-model="documentHtml"
              :selected-block-id="selected?.block.block_id ?? null"
              :html-mode="review.document_review.html_mode"
              :out-of-sync="review.document_review.out_of_sync"
              @update:modelValue="onEditorInput"
              @select-block="selectBlockById"
            />
          </section>
        </div>
      </section>

      <div class="review-side-column">
        <div class="pane-inner">
          <section class="panel document-review-meta">
            <h3>Document Draft</h3>
            <p class="hint">Current mode: {{ review.document_review.html_mode }}</p>
            <p class="hint">Unsaved changes: {{ documentHtmlDirty ? 'yes' : 'no' }}</p>
            <p class="hint">Out of sync with block edits: {{ review.document_review.out_of_sync ? 'yes' : 'no' }}</p>
            <p v-if="review.extraction?.scan_extraction_mode_requested" class="hint">
              Scan mode requested: {{ review.extraction.scan_extraction_mode_requested }}
            </p>
            <p v-if="review.extraction?.scan_extraction_mode_effective" class="hint">
              Scan mode effective: {{ review.extraction.scan_extraction_mode_effective }}
            </p>
            <p v-if="review.extraction?.path?.length" class="hint">
              Extraction path: {{ review.extraction.path.join(' -> ') }}
            </p>
            <p v-if="review.extraction?.landingai?.job_id" class="hint">
              LandingAI job: {{ review.extraction.landingai.job_id }}
            </p>
            <p v-if="review.extraction?.landingai?.page_count != null" class="hint">
              LandingAI pages: {{ review.extraction.landingai.page_count }}
              <span v-if="review.extraction.landingai.duration_ms != null"> · {{ review.extraction.landingai.duration_ms }}ms</span>
            </p>
            <p v-if="review.extraction?.landingai?.failed_pages?.length" class="hint">
              LandingAI failed pages: {{ review.extraction.landingai.failed_pages.join(', ') }}
            </p>
            <p v-if="review.timings" class="hint">
              Timings: {{ formatTimings(review.timings) }}
            </p>
            <p v-if="documentStatus?.ingest_path" class="hint">RAG artifact: {{ documentStatus.ingest_path }}</p>
          </section>

          <BlockReviewPanel
            :document-id="documentId"
            :page-no="selected?.page_no ?? 1"
            :block="selected?.block ?? null"
            @saved="handleBlockSaved"
            @reprocessed="handleBlockReprocessed"
          />
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { exportDocument, fetchReview, fetchStatus, saveDocumentReview } from '../api/client';
import type { DocumentBlock, DocumentStatus, ListMarker, ReviewDocument } from '../types/document';
import BlockReviewPanel from '../components/BlockReviewPanel.vue';
import DocumentBlockEditor from '../components/DocumentBlockEditor.vue';
import DocumentHtmlEditor from '../components/DocumentHtmlEditor.vue';
import DocumentViewer from '../components/DocumentViewer.vue';
import MatraToc from '../components/MatraToc.vue';

const props = defineProps<{ documentId: string }>();

const leftCollapsed = ref(localStorage.getItem('review.leftCollapsed') === '1');
const rightCollapsed = ref(localStorage.getItem('review.rightCollapsed') === '1');
watch(leftCollapsed, (v) => localStorage.setItem('review.leftCollapsed', v ? '1' : '0'));
watch(rightCollapsed, (v) => localStorage.setItem('review.rightCollapsed', v ? '1' : '0'));

const review = ref<ReviewDocument | null>(null);
const documentStatus = ref<DocumentStatus | null>(null);
const exportMessage = ref('');
const selected = ref<{ page_no: number; block: DocumentBlock } | null>(null);
const documentHtml = ref('');
const documentHtmlDirty = ref(false);
const busy = ref(false);
const centerMode = ref<'viewer' | 'document'>('document');
const pageViewerMode = ref<'original' | 'overlay' | 'html'>('html');
let statusPollTimer: ReturnType<typeof setTimeout> | null = null;

const flatBlocks = computed(() => {
  if (!review.value) return [];
  return review.value.pages.flatMap((page) => page.blocks.map((block) => ({ page_no: page.page_no, block })));
});

const matraBlocks = computed(() =>
  flatBlocks.value.filter((item) => item.block.meta.list_marker?.type === 'legal-มาตรา'),
);

const currentMatraBlockId = ref<string | null>(null);

function scrollToBlock(blockId: string): void {
  document.getElementById(`block-${blockId}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  selectBlockById(blockId);
}

function onCurrentBlockChange(blockId: string, _matra: ListMarker | null): void {
  const matraItem = matraBlocks.value.find((item) => item.block.block_id === blockId);
  if (matraItem) {
    currentMatraBlockId.value = blockId;
  } else {
    // Find the closest preceding มาตรา block.
    const allIds = flatBlocks.value.map((item) => item.block.block_id);
    const idx = allIds.indexOf(blockId);
    for (let i = idx; i >= 0; i--) {
      const candidate = flatBlocks.value[i];
      if (candidate.block.meta.list_marker?.type === 'legal-มาตรา') {
        currentMatraBlockId.value = candidate.block.block_id;
        return;
      }
    }
    currentMatraBlockId.value = null;
  }
}

const serverDraftHtml = computed(() => review.value?.document_review.draft_html ?? '');
const selectedPage = computed(() => {
  if (!review.value || !selected.value) {
    return null;
  }
  return review.value.pages.find((page) => page.page_no === selected.value?.page_no) ?? null;
});
watch(selectedPage, () => {
  syncCenterMode();
});

async function reloadReview(options: { preserveLocalHtml?: boolean } = {}): Promise<void> {
  const preserveLocalHtml = options.preserveLocalHtml ?? false;
  const localHtml = documentHtml.value;
  const currentSelectedBlockId = selected.value?.block.block_id ?? null;

  review.value = await fetchReview(props.documentId);
  await refreshStatus();

  if (!preserveLocalHtml || !documentHtmlDirty.value) {
    documentHtml.value = review.value.document_review.draft_html;
    documentHtmlDirty.value = false;
  } else {
    documentHtml.value = localHtml;
  }

  if (!currentSelectedBlockId && flatBlocks.value.length > 0) {
    selected.value = flatBlocks.value[0];
    syncCenterMode();
    return;
  }

  if (currentSelectedBlockId) {
    const match = flatBlocks.value.find((item) => item.block.block_id === currentSelectedBlockId);
    selected.value = match ?? flatBlocks.value[0] ?? null;
  }

  syncCenterMode();
}

async function refreshStatus(): Promise<void> {
  documentStatus.value = await fetchStatus(props.documentId);
}

function scheduleStatusPoll(): void {
  if (statusPollTimer) {
    clearTimeout(statusPollTimer);
  }

  if (!['ingesting', 'processing', 'queued'].includes(documentStatus.value?.status ?? '')) {
    return;
  }

  statusPollTimer = setTimeout(async () => {
    await refreshStatus();
    scheduleStatusPoll();
  }, 1500);
}

function normalizeHtml(value: string): string {
  return value.replace(/\s+/g, ' ').trim();
}

function formatTimings(timings: Record<string, number>): string {
  return Object.entries(timings)
    .map(([stage, ms]) => `${stage}=${ms}ms`)
    .join(', ');
}

function onEditorInput(value: string): void {
  documentHtml.value = value;
  documentHtmlDirty.value = normalizeHtml(value) !== normalizeHtml(serverDraftHtml.value);
}

function selectBlockById(blockId: string): void {
  const match = flatBlocks.value.find((item) => item.block.block_id === blockId);
  if (match) {
    selected.value = match;
    syncCenterMode();
  }
}

function syncCenterMode(): void {
  const page = selectedPage.value;
  const isScanPage = page?.source_kind === 'pdf_scan';
  centerMode.value = isScanPage ? 'viewer' : 'document';
  pageViewerMode.value = isScanPage ? 'original' : 'html';
}

async function persistDocumentReview(resetToGenerated = false): Promise<void> {
  busy.value = true;

  try {
    await saveDocumentReview(props.documentId, {
      draft_html: resetToGenerated ? undefined : documentHtml.value,
      reset_to_generated: resetToGenerated,
    });
    await reloadReview({ preserveLocalHtml: false });
  } finally {
    busy.value = false;
  }
}

async function onSaveDraft(): Promise<void> {
  try {
    await persistDocumentReview(false);
    exportMessage.value = 'Draft HTML saved';
  } catch (err) {
    exportMessage.value = err instanceof Error ? err.message : 'Save failed';
  }
}

async function onResetToGenerated(): Promise<void> {
  try {
    await persistDocumentReview(true);
    exportMessage.value = 'Document HTML reset from latest blocks';
  } catch (err) {
    exportMessage.value = err instanceof Error ? err.message : 'Reset failed';
  }
}

async function onSaveAndExport(): Promise<void> {
  try {
    if (documentHtmlDirty.value) {
      await persistDocumentReview(false);
    }

    busy.value = true;
    const result = await exportDocument(props.documentId);
    exportMessage.value = `Exported and queued RAG: ${result.export_path}`;
    await reloadReview({ preserveLocalHtml: false });
    scheduleStatusPoll();
  } catch (err) {
    exportMessage.value = err instanceof Error ? err.message : 'Export failed';
  } finally {
    busy.value = false;
  }
}

async function handleBlockSaved(): Promise<void> {
  await reloadReview({ preserveLocalHtml: documentHtmlDirty.value });
}

async function handleBlockReprocessed(): Promise<void> {
  await reloadReview({ preserveLocalHtml: documentHtmlDirty.value });
}

onMounted(async () => {
  await reloadReview();
  scheduleStatusPoll();
});

onBeforeUnmount(() => {
  if (statusPollTimer) {
    clearTimeout(statusPollTimer);
  }
});
</script>
