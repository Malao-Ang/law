<template>
  <section class="panel" v-if="block">
    <h3>Block Review</h3>
    <p class="hint">{{ block.block_id }} · {{ block.type }}</p>

  

    <label>Block Review Approved Text</label>
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

    <div v-if="block.meta.layout" class="layout-metadata">
      <p class="hint">Alignment: {{ block.meta.layout.alignment ?? 'left' }}</p>
      <p class="hint">
        Indent L/F/H: {{ block.meta.layout.indent_left ?? 0 }} / {{ block.meta.layout.indent_first_line ?? 0 }} /
        {{ block.meta.layout.indent_hanging ?? 0 }}
      </p>
      <p class="hint">Tabs: {{ layoutTabsText }}</p>
    </div>

    <label>Reviewed HTML</label>
    <textarea v-model="reviewedHtml" class="html-editor"></textarea>

    <div v-if="selectedType === 'table'" class="table-editor">
      <label>Structured Table</label>
      <table class="structured-table-editor">
        <tbody>
          <tr v-for="(row, rowIndex) in tableCells" :key="`row-${rowIndex}`">
            <td
              v-for="(cell, cellIndex) in row"
              :key="`cell-${rowIndex}-${cellIndex}`"
              :colspan="cell.colspan"
              :rowspan="cell.rowspan"
            >
              <textarea v-model="tableCells[rowIndex][cellIndex].text" class="table-cell-input"></textarea>
              <p class="hint">merge {{ cell.rowspan }}x{{ cell.colspan }}</p>
            </td>
          </tr>
        </tbody>
      </table>
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
import { computed, ref, watch } from 'vue';
import { patchBlock, reprocessBlock } from '../api/client';
import type { BlockLayout, BlockType, DocumentBlock, ReviewedTable, ReviewedTableCell } from '../types/document';

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
const tableCells = ref<ReviewedTableCell[][]>([]);

const layoutTabsText = computed(() => {
  const tabs = props.block?.meta.layout?.tabs ?? [];
  if (tabs.length === 0) {
    return 'none';
  }

  return tabs.map((tab) => `${tab.align}@${tab.position}`).join(', ');
});

watch(
  () => props.block,
  (next) => {
    approvedText.value = next?.approved_text ?? '';
    selectedType.value = next?.type ?? 'paragraph';
    readingOrder.value = next?.meta.layout?.reading_order ?? next?.reading_order ?? 0;
    bbox.value = normalizeBbox(next?.meta.layout?.bbox ?? next?.bbox ?? null);
    tableCells.value = normalizeTableCells(next?.meta.table);
    reviewedHtml.value = String(
      next?.meta.reviewed_html
        ?? buildDefaultHtml(next?.type ?? 'paragraph', next?.approved_text ?? '', next?.meta.layout, tableCells.value),
    );
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

function normalizeTableCells(table: ReviewedTable | null | undefined): ReviewedTableCell[][] {
  if (table?.cells && table.cells.length > 0) {
    return table.cells.map((row) =>
      row.map((cell) => ({
        text: cell.text,
        colspan: cell.colspan ?? 1,
        rowspan: cell.rowspan ?? 1,
        alignment: cell.alignment ?? null,
      })),
    );
  }

  const fallbackRows = [table?.headers ?? [], ...(table?.rows ?? [])].filter((row) => row.length > 0);
  return fallbackRows.map((row) => row.map((text) => ({ text, colspan: 1, rowspan: 1, alignment: null })));
}

function buildLayoutStyle(layout?: BlockLayout): string {
  if (!layout) {
    return '';
  }

  const styles: string[] = [];
  if (layout.alignment) {
    styles.push(`text-align:${layout.alignment}`);
  }
  if (layout.indent_left) {
    styles.push(`margin-left:${layout.indent_left / 20}pt`);
  }
  if (layout.indent_first_line) {
    styles.push(`text-indent:${layout.indent_first_line / 20}pt`);
  } else if (layout.indent_hanging) {
    styles.push(`text-indent:-${layout.indent_hanging / 20}pt`);
  }

  return styles.join('; ');
}

function renderTextWithTabs(text: string, layout?: BlockLayout): string {
  const escapedSegments = text
    .split('\t')
    .map((segment) => escapeHtml(segment).replaceAll('\n', '<br>'));

  if (!text.includes('\t')) {
    return escapedSegments[0] ?? '';
  }

  return escapedSegments
    .map((segment, index) => {
      if (index >= escapedSegments.length - 1) {
        return segment;
      }
      const tabWidth = Math.max(((layout?.tabs?.[index]?.position ?? 960) / 20), 48);
      return `${segment}<span class="doc-tab" style="display:inline-block; width:${tabWidth}pt;"></span>`;
    })
    .join('');
}

function buildDefaultHtml(
  type: BlockType,
  text: string,
  layout?: BlockLayout,
  cells: ReviewedTableCell[][] = tableCells.value,
): string {
  if (type === 'table') {
    return buildTableHtml(cells);
  }

  const style = buildLayoutStyle(layout);
  const styleAttr = style ? ` style="${style}"` : '';
  const content = renderTextWithTabs(text, layout);
  const tag = 'p';
  const className = type === 'list_item' ? ' class="doc-paragraph doc-list-item"' : ' class="doc-paragraph"';
  return `<${tag}${className}${styleAttr}>${content}</${tag}>`;
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

  const cells = tableCells.value.map((row) =>
    row.map((cell) => ({
      text: cell.text,
      colspan: Math.max(1, cell.colspan ?? 1),
      rowspan: Math.max(1, cell.rowspan ?? 1),
      alignment: cell.alignment ?? null,
    })),
  );

  return {
    headers: cells[0]?.map((cell) => cell.text) ?? [],
    rows: cells.slice(1).map((row) => row.map((cell) => cell.text)),
    cells,
    html: buildTableHtml(cells),
  };
}

function buildTableHtml(cells: ReviewedTableCell[][]): string {
  if (cells.length === 0) {
    return '<table><tbody></tbody></table>';
  }

  const rows = cells
    .map((row, rowIndex) => {
      const cellTag = rowIndex === 0 ? 'th' : 'td';
      const html = row
        .map((cell) => {
          const colspan = Math.max(1, cell.colspan ?? 1);
          const rowspan = Math.max(1, cell.rowspan ?? 1);
          const attrs = [
            colspan > 1 ? ` colspan="${colspan}"` : '',
            rowspan > 1 ? ` rowspan="${rowspan}"` : '',
            cell.alignment ? ` style="text-align:${cell.alignment};"` : '',
          ].join('');
          return `<${cellTag}${attrs}>${escapeHtml(cell.text).replaceAll('\n', '<br>')}</${cellTag}>`;
        })
        .join('');
      return `<tr>${html}</tr>`;
    })
    .join('');

  return `<table><tbody>${rows}</tbody></table>`;
}

function syncReviewedHtml(): void {
  const table = parseTable();
  reviewedHtml.value = selectedType.value === 'table'
    ? table?.html ?? buildTableHtml(tableCells.value)
    : buildDefaultHtml(selectedType.value, approvedText.value, props.block?.meta.layout, tableCells.value);
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