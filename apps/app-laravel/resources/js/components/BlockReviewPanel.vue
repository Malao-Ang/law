<template>
  <section class="panel" v-if="block">
    <h3>Block Review</h3>
    <p class="hint">{{ block.block_id }} · {{ block.type }}</p>

    <label>Raw Text</label>
    <textarea :value="block.raw_text" readonly></textarea>

    <label>Normalized Text</label>
    <textarea :value="block.normalized_text" readonly></textarea>

    <label>AI Suggested Text</label>
    <textarea :value="block.ai_suggested_text" readonly></textarea>

    <label>Approved Text</label>
    <textarea v-model="approvedText"></textarea>

    <div class="layout-grid">
      <div>
        <label>Block Type</label>
        <select v-model="selectedType">
          <option v-for="option in blockTypes" :key="option" :value="option">{{ option }}</option>
        </select>
      </div>
      <div>
        <label>Reading Order</label>
        <input v-model.number="readingOrder" type="number" min="0" />
      </div>
      <div>
        <label>X</label>
        <input v-model.number="bbox[0]" type="number" min="0" />
      </div>
      <div>
        <label>Y</label>
        <input v-model.number="bbox[1]" type="number" min="0" />
      </div>
      <div>
        <label>Width</label>
        <input v-model.number="bbox[2]" type="number" min="0" />
      </div>
      <div>
        <label>Height</label>
        <input v-model.number="bbox[3]" type="number" min="0" />
      </div>
    </div>

    <label>Reviewed HTML</label>
    <textarea v-model="reviewedHtml" class="html-editor"></textarea>

    <div v-if="selectedType === 'table'" class="table-editor">
      <label>Table Headers</label>
      <input v-model="tableHeadersText" type="text" placeholder="Header 1 | Header 2 | Header 3" />

      <label>Table Rows</label>
      <textarea
        v-model="tableRowsText"
        class="table-textarea"
        placeholder="row1-col1 | row1-col2&#10;row2-col1 | row2-col2"
      ></textarea>
    </div>

    <div class="actions">
      <button class="btn" @click="approvedText = block.normalized_text">Accept Normalized</button>
      <button class="btn" @click="approvedText = block.ai_suggested_text">Accept AI</button>
      <button class="btn" @click="syncReviewedHtml">Sync HTML</button>
      <button class="btn btn-primary" :disabled="busy" @click="saveBlock">Save Review</button>
      <button class="btn" :disabled="busy" @click="runReprocess">Re-run AI</button>
    </div>

    <div class="html-preview">
      <p class="hint">HTML Preview</p>
      <div class="html-preview-card" v-html="reviewedHtml"></div>
    </div>

    <p v-if="message" class="hint">{{ message }}</p>
  </section>

  <section class="panel" v-else>
    <h3>Block Review</h3>
    <p class="hint">Select a block from the list.</p>
  </section>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import { patchBlock, reprocessBlock } from '../api/client';
import type { DocumentBlock, BlockType, ReviewedTable } from '../types/document';

const blockTypes: BlockType[] = [
  'title',
  'section_header',
  'paragraph',
  'list_item',
  'table',
  'figure_caption',
  'footnote',
  'unknown',
];

const props = defineProps<{
  documentId: string;
  pageNo: number;
  block: DocumentBlock | null;
}>();

const emit = defineEmits<{
  saved: [];
  reprocessed: [];
}>();

const approvedText = ref('');
const busy = ref(false);
const message = ref('');
const selectedType = ref<BlockType>('paragraph');
const readingOrder = ref(0);
const bbox = ref<[number, number, number, number]>([24, 24, 760, 112]);
const reviewedHtml = ref('');
const tableHeadersText = ref('');
const tableRowsText = ref('');

watch(
  () => props.block,
  (next) => {
    approvedText.value = next?.approved_text ?? '';
    selectedType.value = next?.type ?? 'paragraph';
    readingOrder.value = next?.meta.layout?.reading_order ?? next?.reading_order ?? 0;
    bbox.value = normalizeBbox(next?.meta.layout?.bbox ?? next?.bbox ?? null);
    reviewedHtml.value = String(next?.meta.reviewed_html ?? buildDefaultHtml(next?.type ?? 'paragraph', next?.approved_text ?? ''));
    tableHeadersText.value = (next?.meta.table?.headers ?? []).join(' | ');
    tableRowsText.value = (next?.meta.table?.rows ?? []).map((row) => row.join(' | ')).join('\n');
    message.value = '';
  },
  { immediate: true },
);

function normalizeBbox(input: [number, number, number, number] | null): [number, number, number, number] {
  if (!input) {
    return [24, 24, 760, 112];
  }

  const [x1, y1, x2, y2] = input;
  return [x1, y1, x2, y2];
}

function buildDefaultHtml(type: BlockType, text: string): string {
  const safe = escapeHtml(text);

  if (type === 'table') {
    return `<table><tbody><tr><td>${safe}</td></tr></tbody></table>`;
  }

  const tag = type === 'title' ? 'h1' : type === 'section_header' ? 'h2' : type === 'list_item' ? 'li' : 'p';
  return `<${tag}>${safe.replaceAll('\n', '<br>')}</${tag}>`;
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function parseTable(): ReviewedTable | null {
  if (selectedType.value !== 'table') {
    return null;
  }

  const headers = tableHeadersText.value
    .split('|')
    .map((value) => value.trim())
    .filter(Boolean);
  const rows = tableRowsText.value
    .split('\n')
    .map((line) => line.split('|').map((value) => value.trim()))
    .filter((row) => row.some(Boolean));

  return {
    headers,
    rows,
  };
}

function buildTableHtml(table: ReviewedTable | null): string {
  if (!table) {
    return buildDefaultHtml(selectedType.value, approvedText.value);
  }

  const headers = table.headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('');
  const rows = table.rows
    .map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`)
    .join('');

  return `<table>${headers ? `<thead><tr>${headers}</tr></thead>` : ''}<tbody>${rows}</tbody></table>`;
}

function syncReviewedHtml(): void {
  const table = parseTable();
  reviewedHtml.value = selectedType.value === 'table'
    ? buildTableHtml(table)
    : buildDefaultHtml(selectedType.value, approvedText.value);
}

async function saveBlock(): Promise<void> {
  if (!props.block || busy.value) return;
  busy.value = true;

  try {
    const table = parseTable();
    await patchBlock(props.documentId, props.block.block_id, {
      page_no: props.pageNo,
      approved_text: approvedText.value,
      mark_uncertain: false,
      type: selectedType.value,
      reading_order: readingOrder.value,
      bbox: bbox.value,
      reviewed_html: reviewedHtml.value,
      table,
    });
    message.value = 'Saved review layout';
    emit('saved');
  } catch (err) {
    message.value = err instanceof Error ? err.message : 'Save failed';
  } finally {
    busy.value = false;
  }
}

async function runReprocess(): Promise<void> {
  if (!props.block || busy.value) return;
  busy.value = true;

  try {
    await reprocessBlock(props.documentId, props.block.block_id, {
      page_no: props.pageNo,
      mode: 'ai_correction',
    });
    message.value = 'Reprocess queued';
    emit('reprocessed');
  } catch (err) {
    message.value = err instanceof Error ? err.message : 'Reprocess failed';
  } finally {
    busy.value = false;
  }
}
</script>
