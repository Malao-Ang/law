<template>
  <section>
    <div class="panel review-header">
      <div>
        <h2>Review Document</h2>
        <p v-if="review" class="hint">
          {{ review.source_file }} · {{ review.source_type }} · {{ review.summary.block_count }} blocks
        </p>
        <p v-if="documentStatus" class="hint">
          Pipeline: {{ documentStatus.status }} · {{ documentStatus.current_step }}
          <span v-if="documentStatus.ingested_chunk_count">· {{ documentStatus.ingested_chunk_count }} chunks</span>
        </p>
        <p v-else class="hint">Loading review data...</p>
      </div>
      <div class="review-actions">
        <p v-if="exportMessage" class="hint">{{ exportMessage }}</p>
      </div>
    </div>

    <div class="grid review-grid" v-if="review">
      <section class="panel list-panel">
        <h3>Blocks</h3>
        <p class="hint">เลือก block เพื่อดูข้อความ OCR เดิมและปรับแก้แบบละเอียด</p>
        <ul class="block-list">
          <li
            v-for="item in flatBlocks"
            :key="`${item.page_no}-${item.block.block_id}`"
            :class="{ active: selected?.block.block_id === item.block.block_id }"
            @click="selected = item"
          >
            <div>
              <strong>P{{ item.page_no }}</strong> · {{ item.block.block_id }}
            </div>
            <div class="hint">{{ item.block.type }} · {{ item.block.needs_review ? 'needs review' : 'ok' }}</div>
          </li>
        </ul>
      </section>

      <section class="panel editor-panel">
        <DocumentHtmlEditor
          v-model="documentHtml"
          :selected-block-id="selected?.block.block_id ?? null"
          :html-mode="review.document_review.html_mode"
          :out-of-sync="review.document_review.out_of_sync"
          @update:modelValue="onEditorInput"
          @select-block="selectBlockById"
        />
      </section>

      <div class="review-side-column">
        <section class="panel document-review-meta">
          <h3>Document Draft</h3>
          <p class="hint">Current mode: {{ review.document_review.html_mode }}</p>
          <p class="hint">Unsaved changes: {{ documentHtmlDirty ? 'yes' : 'no' }}</p>
          <p class="hint">Out of sync with block edits: {{ review.document_review.out_of_sync ? 'yes' : 'no' }}</p>
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
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { exportDocument, fetchReview, fetchStatus, saveDocumentReview } from '../api/client';
import type { DocumentBlock, DocumentStatus, ReviewDocument } from '../types/document';
import BlockReviewPanel from '../components/BlockReviewPanel.vue';
import DocumentHtmlEditor from '../components/DocumentHtmlEditor.vue';
import HeaderComponent from '../components/HeaderComponent.vue';

const props = defineProps<{ documentId: string }>();

const review = ref<ReviewDocument | null>(null);
const documentStatus = ref<DocumentStatus | null>(null);
const exportMessage = ref('');
const selected = ref<{ page_no: number; block: DocumentBlock } | null>(null);
const documentHtml = ref('');
const documentHtmlDirty = ref(false);
const busy = ref(false);
let statusPollTimer: ReturnType<typeof setTimeout> | null = null;

const flatBlocks = computed(() => {
  if (!review.value) return [];
  return review.value.pages.flatMap((page) => page.blocks.map((block) => ({ page_no: page.page_no, block })));
});

const serverDraftHtml = computed(() => review.value?.document_review.draft_html ?? '');

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
    return;
  }

  if (currentSelectedBlockId) {
    const match = flatBlocks.value.find((item) => item.block.block_id === currentSelectedBlockId);
    selected.value = match ?? flatBlocks.value[0] ?? null;
  }
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

function onEditorInput(value: string): void {
  documentHtml.value = value;
  documentHtmlDirty.value = normalizeHtml(value) !== normalizeHtml(serverDraftHtml.value);
}

function selectBlockById(blockId: string): void {
  const match = flatBlocks.value.find((item) => item.block.block_id === blockId);
  if (match) {
    selected.value = match;
  }
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
