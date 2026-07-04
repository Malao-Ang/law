<template>
  <v-card v-if="block" class="pa-4">
    <div class="text-h6 font-weight-bold mb-1">Block Review</div>
    <div class="text-caption text-medium-emphasis mb-3">{{ block.block_id }} · {{ block.type }}</div>

    <v-textarea v-model="approvedText" label="Block Review Approved Text" rows="4" />

    <v-row dense class="mt-2">
      <v-col cols="12" sm="6">
        <v-select v-model="selectedType" label="Block Type" :items="blockTypes" />
      </v-col>
      <v-col cols="12" sm="6">
        <v-text-field v-model.number="readingOrder" label="Reading Order" type="number" min="0" />
      </v-col>
      <v-col cols="6" sm="3">
        <v-text-field v-model.number="bbox[0]" label="X" type="number" min="0" />
      </v-col>
      <v-col cols="6" sm="3">
        <v-text-field v-model.number="bbox[1]" label="Y" type="number" min="0" />
      </v-col>
      <v-col cols="6" sm="3">
        <v-text-field v-model.number="bbox[2]" label="Width" type="number" min="0" />
      </v-col>
      <v-col cols="6" sm="3">
        <v-text-field v-model.number="bbox[3]" label="Height" type="number" min="0" />
      </v-col>
    </v-row>

    <div v-if="block.meta.layout" class="mt-2">
      <div class="text-caption text-medium-emphasis">Alignment: {{ block.meta.layout.alignment ?? 'left' }}</div>
      <div class="text-caption text-medium-emphasis">Indent level: {{ block.meta.layout.indent_level ?? 0 }}</div>
      <div class="text-caption text-medium-emphasis">
        Indent L/F/H: {{ block.meta.layout.indent_left ?? 0 }} / {{ block.meta.layout.indent_first_line ?? 0 }} /
        {{ block.meta.layout.indent_hanging ?? 0 }}
      </div>
      <div v-if="block.meta.layout.indent_source || block.meta.layout.indent_reason" class="text-caption text-medium-emphasis">
        Indent rule: {{ block.meta.layout.indent_source ?? 'unknown' }} / {{ block.meta.layout.indent_reason ?? 'none' }}
      </div>
      <div v-if="block.meta.layout.first_line_inferred" class="text-caption text-medium-emphasis">
        First-line indent inferred: {{ block.meta.layout.first_line_inferred }}
      </div>
      <div class="text-caption text-medium-emphasis">Tabs: {{ layoutTabsText }}</div>
      <div v-if="block.meta.table_confidence != null" class="text-caption text-medium-emphasis">
        Table confidence: {{ Number(block.meta.table_confidence).toFixed(2) }}
      </div>
      <div v-if="block.meta.table_detection_reason" class="text-caption text-medium-emphasis">
        Table reason: {{ block.meta.table_detection_reason }}
      </div>
    </div>

    <v-textarea v-model="reviewedHtml" label="Reviewed HTML" rows="6" class="mt-2" />

    <div v-if="selectedType === 'table'" class="mt-2">
      <div class="text-body-2 font-weight-medium mb-1">Structured Table</div>
      <table class="structured-table-editor">
        <tbody>
          <tr v-for="(row, rowIndex) in tableCells" :key="`row-${rowIndex}`">
            <td
              v-for="(cell, cellIndex) in row"
              :key="`cell-${rowIndex}-${cellIndex}`"
              :colspan="cell.colspan"
              :rowspan="cell.rowspan"
              class="table-cell-wrapper"
            >
              <textarea v-model="tableCells[rowIndex][cellIndex].text" class="table-cell-input"></textarea>
              <div class="cell-span-controls">
                <span class="text-caption">{{ cell.rowspan }}r × {{ cell.colspan }}c</span>
                <v-btn size="x-small" variant="outlined" title="Merge right (colspan +1)"
                  @click="adjustSpan(rowIndex, cellIndex, 'colspan', +1)">→</v-btn>
                <v-btn size="x-small" variant="outlined" title="Merge down (rowspan +1)"
                  @click="adjustSpan(rowIndex, cellIndex, 'rowspan', +1)">↓</v-btn>
                <v-btn size="x-small" variant="outlined" title="Split (reset to 1×1)"
                  :disabled="cell.colspan === 1 && cell.rowspan === 1"
                  @click="adjustSpan(rowIndex, cellIndex, 'reset', 0)">✕</v-btn>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="d-flex flex-wrap ga-2 mt-3">
      <v-btn @click="approvedText = block.normalized_text">Accept Normalized</v-btn>
      <v-btn @click="approvedText = block.ai_suggested_text">Accept AI</v-btn>
      <v-btn @click="syncReviewedHtml">Sync HTML</v-btn>
      <v-btn color="primary" :disabled="busy" @click="saveBlock">Save Review</v-btn>
      <v-btn :disabled="busy" @click="runReprocess">Re-run AI</v-btn>
    </div>

    <div class="mt-3">
      <div class="text-caption text-medium-emphasis mb-1">HTML Preview</div>
      <div class="html-preview-card" v-html="reviewedHtml"></div>
    </div>

    <div v-if="message" class="text-caption text-medium-emphasis mt-2">{{ message }}</div>
  </v-card>

  <v-card v-else class="pa-4">
    <div class="text-h6 font-weight-bold mb-1">Block Review</div>
    <div class="text-caption text-medium-emphasis">Select a block from the list.</div>
  </v-card>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useBlockStore } from '../../stores/blockStore';
import type { BlockLayout, BlockType, DocumentBlock, ReviewedTable, ReviewedTableCell } from '../../types/document';

const blockStore = useBlockStore();

const blockTypes: BlockType[] = [
  'title',
  'section_header',
  'paragraph',
  'list_item',
  'table',
  'image',
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
  
  // Handle indent properly with hanging indent support
  if (layout.indent_left) {
    // Convert DOCX twips (1/20 point) to points
    const leftMarginPt = layout.indent_left / 20;
    styles.push(`margin-left:${leftMarginPt}pt`);
    
    // Handle hanging indent properly
    if (layout.indent_hanging && layout.indent_hanging > 0) {
      // Hanging indent: first line is indented less than subsequent lines
      // text-indent: negative value pulls first line back
      const hangingPt = layout.indent_hanging / 20;
      styles.push(`text-indent:-${hangingPt}pt`);
    } else if (layout.indent_first_line && layout.indent_first_line > 0) {
      // First line indent: first line indented more than subsequent lines
      const firstLinePt = layout.indent_first_line / 20;
      styles.push(`text-indent:${firstLinePt}pt`);
    }
  } else if (layout.indent_first_line) {
    // First line indent without left margin
    const firstLinePt = layout.indent_first_line / 20;
    styles.push(`text-indent:${firstLinePt}pt`);
  } else if (layout.indent_hanging) {
    // Hanging indent without left margin
    const hangingPt = layout.indent_hanging / 20;
    styles.push(`text-indent:-${hangingPt}pt`);
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

function adjustSpan(rowIndex: number, cellIndex: number, axis: 'colspan' | 'rowspan' | 'reset', delta: number): void {
  const cell = tableCells.value[rowIndex]?.[cellIndex];
  if (!cell) return;
  if (axis === 'reset') {
    cell.colspan = 1;
    cell.rowspan = 1;
  } else {
    const current = cell[axis] ?? 1;
    cell[axis] = Math.max(1, current + delta);
  }
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
    await blockStore.patch(props.documentId, props.block.block_id, {
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
    await blockStore.reprocess(props.documentId, props.block.block_id, props.pageNo);
    message.value = 'Reprocess queued';
    emit('reprocessed');
  } catch (err) {
    message.value = err instanceof Error ? err.message : 'Reprocess failed';
  } finally {
    busy.value = false;
  }
}
</script>
