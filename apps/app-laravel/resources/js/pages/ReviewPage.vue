<template>
  <section>
    <div class="panel review-header">
      <div>
        <h2>Review Document</h2>
        <p class="hint" v-if="review">{{ review.source_file }} · {{ review.source_type }}</p>
        <p class="hint" v-else>Loading review data...</p>
      </div>
      <div>
        <button class="btn" :disabled="!review" @click="onExport">Export RAG JSON</button>
        <p v-if="exportMessage" class="hint">{{ exportMessage }}</p>
      </div>
    </div>

    <div class="grid review-grid" v-if="review">
      <section class="panel list-panel">
        <h3>Blocks</h3>
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

      <DocumentViewer
        :page="selectedPage"
        :block="selected?.block ?? null"
        @select-block="selectBlockById"
        @sync-selected="syncSelectedHtml"
      />

      <BlockReviewPanel
        :document-id="documentId"
        :page-no="selected?.page_no ?? 1"
        :block="selected?.block ?? null"
        @saved="reloadReview"
        @reprocessed="reloadReview"
      />

      <section class="panel rag-panel">
        <h3>RAG Preview</h3>
        <p class="hint">What will be exported for the selected block.</p>
        <div v-if="selected" class="rag-preview-card">
          <pre class="code">{{ ragPreview }}</pre>
          <div class="html-preview-card" v-html="selected.block.meta.reviewed_html ?? ''"></div>
        </div>
        <p v-else class="hint">Select a block to inspect its RAG preview.</p>
      </section>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { exportDocument, fetchReview } from '../api/client';
import type { DocumentBlock, DocumentPage, ReviewDocument } from '../types/document';
import DocumentViewer from '../components/DocumentViewer.vue';
import BlockReviewPanel from '../components/BlockReviewPanel.vue';

const props = defineProps<{ documentId: string }>();

const review = ref<ReviewDocument | null>(null);
const exportMessage = ref('');
const selected = ref<{ page_no: number; block: DocumentBlock } | null>(null);

const flatBlocks = computed(() => {
  if (!review.value) return [];
  return review.value.pages.flatMap((page) => page.blocks.map((block) => ({ page_no: page.page_no, block })));
});

const selectedPage = computed<DocumentPage | null>(() => {
  if (!review.value || !selected.value) return null;
  return review.value.pages.find((page) => page.page_no === selected.value?.page_no) ?? null;
});

const ragPreview = computed(() => {
  if (!selected.value) {
    return '';
  }

  const block = selected.value.block;
  return JSON.stringify(
    {
      text: block.approved_text,
      html: block.meta.reviewed_html ?? null,
      layout: block.meta.layout ?? {
        bbox: block.bbox,
        reading_order: block.reading_order,
      },
      table: block.meta.table ?? null,
    },
    null,
    2,
  );
});

async function reloadReview(): Promise<void> {
  review.value = await fetchReview(props.documentId);

  if (!selected.value && flatBlocks.value.length > 0) {
    selected.value = flatBlocks.value[0];
    return;
  }

  if (selected.value) {
    const match = flatBlocks.value.find((item) => item.block.block_id === selected.value?.block.block_id);
    selected.value = match ?? flatBlocks.value[0] ?? null;
  }
}

function selectBlockById(blockId: string): void {
  const match = flatBlocks.value.find((item) => item.block.block_id === blockId);
  if (match) {
    selected.value = match;
  }
}

function syncSelectedHtml(): void {
  if (!selected.value) {
    return;
  }

  selected.value = {
    ...selected.value,
    block: {
      ...selected.value.block,
      meta: {
        ...selected.value.block.meta,
        reviewed_html: selected.value.block.meta.reviewed_html ?? `<p>${selected.value.block.approved_text}</p>`,
      },
    },
  };
}

async function onExport(): Promise<void> {
  try {
    const result = await exportDocument(props.documentId);
    exportMessage.value = `Exported: ${result.export_path}`;
  } catch (err) {
    exportMessage.value = err instanceof Error ? err.message : 'Export failed';
  }
}

onMounted(async () => {
  await reloadReview();
});
</script>
