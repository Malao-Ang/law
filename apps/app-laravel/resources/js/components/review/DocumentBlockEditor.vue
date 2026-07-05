<template>
  <div class="block-editor" @click.self="deselectAll">
    <div v-if="pages.length === 0" class="block-editor__empty">
      <div class="text-caption text-medium-emphasis">No blocks to display.</div>
    </div>

    <div v-for="page in uniquePages" :key="page.page_no" class="block-editor__page">
      <div class="block-editor__page-header">
        <span class="text-caption text-medium-emphasis">Page {{ page.page_no }}</span>
        <v-btn
          v-if="page.image_url"
          size="x-small"
          variant="outlined"
          :title="thumbVisible[page.page_no] ? 'Hide preview' : 'Show preview'"
          @click.stop="thumbVisible[page.page_no] = !thumbVisible[page.page_no]"
        >{{ thumbVisible[page.page_no] ? '🖼 Hide' : '🖼 Preview' }}</v-btn>
        <v-btn
          size="x-small"
          variant="outlined"
          :disabled="reprocessingPage[page.page_no]"
          :title="'Re-run page ' + page.page_no + ' with Gemini OCR'"
          @click.stop="runGeminiOcr(page.page_no)"
        >{{ reprocessingPage[page.page_no] ? 'Running…' : 'Gemini ↺' }}</button>
      </div>

      <!-- Page image thumbnail (togglable) -->
      <div v-if="thumbVisible[page.page_no] && page.image_url" class="block-editor__thumb">
        <img :src="page.image_url" alt="Page preview" class="block-editor__thumb-img" />
      </div>

      <template v-for="block in page.blocks" :key="block.block_id">
        <!-- มาตรา section divider -->
        <div
          v-if="block.meta.list_marker?.type === 'legal-มาตรา'"
          class="block-editor__matra-divider"
        >
          <span class="block-editor__matra-divider-label">{{ block.meta.list_marker.text }}</span>
        </div>

      <div
        :id="`block-${block.block_id}`"
        class="block-editor__block"
        :class="[
          `block-type--${block.type}`,
          block.needs_review ? 'block--needs-review' : '',
          selectedBlockId === block.block_id ? 'block--selected' : '',
          `doc-indent-${block.meta.layout?.indent_level ?? 0}`,
        ]"
        @click="selectBlock(page.page_no, block)"
      >
        <!-- Sticky มาตรา pill for non-มาตรา blocks -->
        <div
          v-if="blockMatraMap.get(block.block_id) && block.meta.list_marker?.type !== 'legal-มาตรา'"
          class="block-editor__matra-pill"
        >{{ blockMatraMap.get(block.block_id)?.text }}</div>
        <!-- Image block -->
        <template v-if="block.type === 'image'">
          <figure class="block-editor__image">
            <ResizableDragBlock
              v-if="block.meta.image?.src_url || block.meta.image?.data_uri"
              :initial-width="block.meta.image?.display_width_px ?? block.meta.image?.width ?? null"
              :initial-height="block.meta.image?.display_height_px ?? block.meta.image?.height ?? null"
              :selected="dragSelectedBlockId === block.block_id"
              :lock-aspect-ratio="true"
              @update:selected="selectResizableBlock(page.page_no, block, $event)"
              @resize="({ widthPx, heightPx }) => saveBlockSize(page.page_no, block, widthPx, heightPx)"
            >
              <img
                :src="block.meta.image?.src_url ?? block.meta.image?.data_uri ?? ''"
                :alt="block.meta.image?.caption ?? ''"
                class="block-editor__image-img"
                draggable="false"
                style="display: block; width: 100%; height: auto;"
              />
            </ResizableDragBlock>
            <div v-else class="block-editor__image-missing text-caption text-medium-emphasis">
              [Image — no URL: {{ block.meta.image?.src_path ?? 'unknown' }}]
            </div>
            <figcaption v-if="block.meta.image?.caption" class="text-caption text-medium-emphasis">
              {{ block.meta.image.caption }}
            </figcaption>
          </figure>
        </template>

        <!-- Table block -->
        <template v-else-if="block.type === 'table'">
          <div class="block-editor__table-wrap">
            <ResizableDragBlock
              :initial-width="block.meta.table_display_width_px ?? null"
              :initial-height="null"
              :selected="dragSelectedBlockId === block.block_id"
              :lock-aspect-ratio="false"
              @update:selected="selectResizableBlock(page.page_no, block, $event)"
              @resize="({ widthPx }) => saveBlockSize(page.page_no, block, widthPx, null)"
            >
              <!-- eslint-disable-next-line vue/no-v-html -->
              <div
                v-html="sanitizeHtml(block.meta.table_html ?? '')"
                class="block-editor__table-html"
                style="overflow-x: auto;"
              />
            </ResizableDragBlock>
          </div>
        </template>

        <!-- Text block — TipTap inline editor when selected, plain text otherwise -->
        <template v-else>
          <!-- Inline TipTap editor when this block is actively editing -->
          <div
            v-if="editingBlockId === block.block_id"
            class="block-editor__tiptap-shell"
            @click.stop
          >
            <BlockRulerEditor
              :layout="block.meta.layout ?? null"
              @update="(patch) => sendLayoutPatch(page.page_no, block, patch)"
            />

            <!-- Formatting toolbar -->
            <div class="block-editor__toolbar">
              <v-btn
                size="x-small"
                variant="outlined"
                :color="editors[block.block_id]?.isActive('bold') ? 'primary' : undefined"
                @mousedown.prevent="editors[block.block_id]?.chain().focus().toggleBold().run()"
                title="Bold"
              >B</v-btn>
              <v-btn
                size="x-small"
                variant="outlined"
                :color="editors[block.block_id]?.isActive('italic') ? 'primary' : undefined"
                @mousedown.prevent="editors[block.block_id]?.chain().focus().toggleItalic().run()"
                title="Italic"
              ><em>I</em></v-btn>
              <v-btn
                size="x-small"
                variant="outlined"
                :color="editors[block.block_id]?.isActive('underline') ? 'primary' : undefined"
                @mousedown.prevent="editors[block.block_id]?.chain().focus().toggleUnderline().run()"
                title="Underline"
              ><u>U</u></v-btn>
              <v-divider vertical class="mx-1" style="height:16px; align-self:center" />
              <v-btn
                size="x-small"
                variant="outlined"
                :color="editors[block.block_id]?.isActive('heading', { level: 1 }) ? 'primary' : undefined"
                @mousedown.prevent="editors[block.block_id]?.chain().focus().toggleHeading({ level: 1 }).run()"
              >H1</v-btn>
              <v-btn
                size="x-small"
                variant="outlined"
                :color="editors[block.block_id]?.isActive('heading', { level: 2 }) ? 'primary' : undefined"
                @mousedown.prevent="editors[block.block_id]?.chain().focus().toggleHeading({ level: 2 }).run()"
              >H2</v-btn>
              <v-divider vertical class="mx-1" style="height:16px; align-self:center" />
              <div class="block-editor__alignment-group" role="group" aria-label="Alignment">
                <v-btn
                  size="x-small"
                  variant="outlined"
                  :color="block.meta.layout?.alignment === 'left' ? 'primary' : undefined"
                  @mousedown.prevent="setAlignment(page.page_no, block, 'left')"
                  title="Align left"
                >L</v-btn>
                <v-btn
                  size="x-small"
                  variant="outlined"
                  :color="block.meta.layout?.alignment === 'center' ? 'primary' : undefined"
                  @mousedown.prevent="setAlignment(page.page_no, block, 'center')"
                  title="Align center"
                >C</v-btn>
                <v-btn
                  size="x-small"
                  variant="outlined"
                  :color="block.meta.layout?.alignment === 'right' ? 'primary' : undefined"
                  @mousedown.prevent="setAlignment(page.page_no, block, 'right')"
                  title="Align right"
                >R</v-btn>
                <v-btn
                  size="x-small"
                  variant="outlined"
                  :color="block.meta.layout?.alignment === 'justify' ? 'primary' : undefined"
                  @mousedown.prevent="setAlignment(page.page_no, block, 'justify')"
                  title="Justify"
                >J</v-btn>
              </div>
              <v-divider vertical class="mx-1" style="height:16px; align-self:center" />
              <!-- Diff toggle -->
              <v-btn size="x-small" variant="outlined" @mousedown.prevent="toggleDiff(block.block_id)">
                {{ showDiff[block.block_id] ? 'Hide diff' : 'Diff' }}
              </v-btn>
              <v-divider vertical class="mx-1" style="height:16px; align-self:center" />
              <v-btn size="x-small" color="primary" :disabled="busy[block.block_id]" @mousedown.prevent="saveBlock(page.page_no, block)">
                Save
              </v-btn>
              <v-btn size="x-small" variant="outlined" @mousedown.prevent="cancelEdit">Cancel</v-btn>
            </div>

            <!-- Diff viewer (raw_text vs current approved_text) -->
            <div v-if="showDiff[block.block_id]" class="block-editor__diff">
              <div class="diff-col">
                <div class="text-caption text-medium-emphasis">raw_text</div>
                <pre class="code">{{ block.raw_text }}</pre>
              </div>
              <div class="diff-col">
                <div class="text-caption text-medium-emphasis">approved_text (before edit)</div>
                <pre class="code">{{ block.approved_text }}</pre>
              </div>
            </div>

            <editor-content
              :editor="editors[block.block_id]"
              class="block-editor__tiptap-content"
            />
            <div v-if="errors[block.block_id]" class="text-caption text-error mt-1">{{ errors[block.block_id] }}</div>
          </div>

          <!-- Read-only view -->
          <template v-else>
            <div class="block-editor__text-row" @dblclick.stop="startEdit(page.page_no, block)">
              <span
                v-if="block.meta.list_marker"
                class="block-editor__marker text-caption text-medium-emphasis"
                :title="`type=${block.meta.list_marker.type} level=${block.meta.list_marker.level}`"
              >{{ block.meta.list_marker.text }}</span>
              <span class="block-editor__text">{{ block.approved_text || block.normalized_text }}</span>
            </div>

            <!-- Spell suggestions -->
            <div
              v-if="spellSuggestions(block).length > 0"
              class="block-editor__spell-row"
            >
              <span class="text-caption text-medium-emphasis">Spell: </span>
              <v-btn
                v-for="(s, si) in spellSuggestions(block)"
                :key="si"
                size="x-small"
                color="warning"
                variant="tonal"
                :title="`Replace '${s.token}' → '${s.suggestion}'`"
                @click.stop="copySpellSuggestion(s)"
              >
                {{ s.token }} → {{ s.suggestion }}
              </v-btn>
            </div>
          </template>
        </template>

        <!-- Layout controls (shown for all non-editing blocks) -->
        <div v-if="editingBlockId !== block.block_id" class="block-editor__controls" @click.stop>
          <div class="block-editor__control-group">
            <span class="text-caption text-medium-emphasis">Indent</span>
            <v-btn
              icon
              size="x-small"
              variant="text"
              :disabled="(block.meta.layout?.indent_level ?? 0) <= 0 || busy[block.block_id]"
              @click="changeIndent(page.page_no, block, -1)"
            >−</v-btn>
            <span class="block-editor__level-badge">{{ block.meta.layout?.indent_level ?? 0 }}</span>
            <v-btn
              icon
              size="x-small"
              variant="text"
              :disabled="(block.meta.layout?.indent_level ?? 0) >= 10 || busy[block.block_id]"
              @click="changeIndent(page.page_no, block, +1)"
            >+</v-btn>
          </div>

          <div v-if="block.meta.list_marker" class="block-editor__control-group">
            <span class="text-caption text-medium-emphasis">List level</span>
            <select
              class="block-editor__level-select"
              :value="block.meta.list_marker.level"
              :disabled="busy[block.block_id]"
              @change="changeListLevel(page.page_no, block, Number(($event.target as HTMLSelectElement).value))"
            >
              <option v-for="n in 6" :key="n" :value="n">{{ n }}</option>
            </select>
          </div>

          <v-btn
            v-if="block.type !== 'image' && block.type !== 'table'"
            size="x-small"
            variant="outlined"
            @click.stop="startEdit(page.page_no, block)"
          >Edit</v-btn>

          <span v-if="errors[block.block_id]" class="text-caption text-error">{{ errors[block.block_id] }}</span>
        </div>
      </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import DOMPurify from 'dompurify';
import { patchBlockSize } from '../../api/client';
import { useBlockStore } from '../../stores/blockStore';
import BlockRulerEditor from './BlockRulerEditor.vue';
import ResizableDragBlock from '../shared/ResizableDragBlock.vue';

const blockStore = useBlockStore();
import type { DocumentBlock, DocumentPage, LayoutPatch, ListMarker } from '../../types/document';

function sanitizeHtml(html: string): string {
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span', 'div', 'sub', 'sup'],
    ALLOWED_ATTR: ['class', 'style', 'colspan', 'rowspan'],
  });
}

interface SpellSuggestion {
  token: string;
  suggestion: string;
  confidence: number;
  offset: number;
}

const props = defineProps<{
  documentId: string;
  pages: DocumentPage[];
  selectedBlockId?: string | null;
}>();

const emit = defineEmits<{
  'select-block': [pageNo: number, block: DocumentBlock];
  'layout-updated': [pageNo: number, block: DocumentBlock];
  'block-saved': [pageNo: number, block: DocumentBlock];
  'current-block-change': [blockId: string, matra: ListMarker | null];
}>();

// Maps every block_id to the closest preceding มาตรา marker (or null).
const blockMatraMap = computed(() => {
  const map = new Map<string, ListMarker>();
  let current: ListMarker | null = null;
  for (const page of props.pages) {
    for (const block of page.blocks) {
      if (block.meta.list_marker?.type === 'legal-มาตรา') {
        current = block.meta.list_marker;
      }
      if (current) map.set(block.block_id, current);
    }
  }
  return map;
});

// IntersectionObserver: emit which block is currently near the top of the viewport.
let observer: IntersectionObserver | null = null;

function setupObserver(): void {
  observer?.disconnect();
  observer = new IntersectionObserver(
    (entries) => {
      const top = entries
        .filter((e) => e.isIntersecting)
        .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
      if (top) {
        const blockId = top.target.id.replace(/^block-/, '');
        emit('current-block-change', blockId, blockMatraMap.value.get(blockId) ?? null);
      }
    },
    { rootMargin: '-10% 0px -75% 0px' },
  );
  document.querySelectorAll<HTMLElement>('.block-editor__block').forEach((el) => observer!.observe(el));
}

onMounted(() => nextTick(setupObserver));

watch(() => props.pages, () => nextTick(setupObserver), { deep: false });

const reprocessingPage = reactive<Record<number, boolean>>({});
const busy = reactive<Record<string, boolean>>({});
const errors = reactive<Record<string, string>>({});
const showDiff = reactive<Record<string, boolean>>({});

// Map from block_id → TipTap editor instance (only exists while editing).
const editors = reactive<Record<string, ReturnType<typeof useEditor>>>({});
const editingBlockId = ref<string | null>(null);
const editingPageNo = ref<number>(1);
const dragSelectedBlockId = ref<string | null>(null);

function deselectAll(): void {
  dragSelectedBlockId.value = null;
}

function selectBlock(pageNo: number, block: DocumentBlock): void {
  dragSelectedBlockId.value = block.type === 'image' || block.type === 'table'
    ? block.block_id
    : null;
  emit('select-block', pageNo, block);
}

function selectResizableBlock(pageNo: number, block: DocumentBlock, selected: boolean): void {
  dragSelectedBlockId.value = selected ? block.block_id : null;
  emit('select-block', pageNo, block);
}

function spellSuggestions(block: DocumentBlock): SpellSuggestion[] {
  const raw = block.meta.spell_suggestions;
  if (!Array.isArray(raw)) return [];
  return raw as SpellSuggestion[];
}

function copySpellSuggestion(s: SpellSuggestion): void {
  navigator.clipboard?.writeText(s.suggestion).catch(() => undefined);
}

// ── TipTap editing ────────────────────────────────────────────────────────────

function startEdit(pageNo: number, block: DocumentBlock): void {
  if (editingBlockId.value === block.block_id) return;
  cancelEdit();

  editingBlockId.value = block.block_id;
  editingPageNo.value = pageNo;

  const richHtml = block.meta.reviewed_html;
  const content = richHtml
    ? sanitizeHtml(richHtml)
    : block.approved_text
      ? `<p>${block.approved_text.replace(/\n/g, '<br>')}</p>`
      : '<p></p>';

  const editor = useEditor({
    extensions: [StarterKit, Underline],
    content,
  });

  editors[block.block_id] = editor;
  emit('select-block', pageNo, block);
}

function cancelEdit(): void {
  if (!editingBlockId.value) return;
  const id = editingBlockId.value;
  const ed = editors[id];
  if (ed && ed.value) {
    ed.value.destroy();
  }
  delete editors[id];
  editingBlockId.value = null;
}

function toggleDiff(blockId: string): void {
  showDiff[blockId] = !showDiff[blockId];
}

async function runGeminiOcr(pageNo: number): Promise<void> {
  reprocessingPage[pageNo] = true;
  try {
    await blockStore.reprocessPage(props.documentId, pageNo, 'gemini');
  } finally {
    reprocessingPage[pageNo] = false;
  }
}

async function saveBlock(pageNo: number, block: DocumentBlock): Promise<void> {
  const id = block.block_id;
  const editor = editors[id];
  if (!editor?.value || busy[id]) return;

  busy[id] = true;
  errors[id] = '';

  const approvedText = editor.value.getText({ blockSeparator: '\n' });
  const reviewedHtml = editor.value.getHTML();

  try {
    await blockStore.patch(props.documentId, id, {
      page_no: pageNo,
      approved_text: approvedText,
      reviewed_html: reviewedHtml,
      mark_uncertain: false,
      type: block.type,
      reading_order: block.reading_order,
      bbox: block.bbox,
    });

    block.approved_text = approvedText;
    if (!block.meta) block.meta = {};
    block.meta.reviewed_html = reviewedHtml;
    emit('block-saved', pageNo, block);
    cancelEdit();
  } catch (err) {
    errors[id] = err instanceof Error ? err.message : 'Save failed';
  } finally {
    busy[id] = false;
  }
}

async function saveBlockSize(
  pageNo: number,
  block: DocumentBlock,
  widthPx: number,
  heightPx: number | null,
): Promise<void> {
  if (!props.documentId) return;

  try {
    await patchBlockSize(props.documentId, block.block_id, {
      page_no: pageNo,
      display_width_px: widthPx,
      display_height_px: heightPx,
    });

    if (block.type === 'image' && block.meta.image) {
      block.meta.image.display_width_px = widthPx;
      block.meta.image.display_height_px = heightPx;
      return;
    }

    block.meta.table_display_width_px = widthPx;
  } catch {
    // Non-fatal.
  }
}

// ── Layout controls ───────────────────────────────────────────────────────────

async function changeIndent(pageNo: number, block: DocumentBlock, delta: number): Promise<void> {
  const current = block.meta.layout?.indent_level ?? 0;
  const next = Math.max(0, Math.min(10, current + delta));
  if (next === current) return;
  await sendLayoutPatch(pageNo, block, { indent_level: next });
}

async function changeListLevel(pageNo: number, block: DocumentBlock, level: number): Promise<void> {
  await sendLayoutPatch(pageNo, block, { list_marker_level: level });
}

async function setAlignment(pageNo: number, block: DocumentBlock, alignment: 'left' | 'center' | 'right' | 'justify'): Promise<void> {
  await sendLayoutPatch(pageNo, block, { alignment });
}

async function sendLayoutPatch(
  pageNo: number,
  block: DocumentBlock,
  patch: Omit<LayoutPatch, 'page_no'>,
): Promise<void> {
  const id = block.block_id;
  busy[id] = true;
  errors[id] = '';

  try {
    await blockStore.patchLayout(props.documentId, id, { page_no: pageNo, ...patch });

    if (!block.meta.layout) block.meta.layout = { bbox: null, reading_order: null };
    if ('indent_level' in patch && patch.indent_level !== undefined) block.meta.layout.indent_level = patch.indent_level;
    if ('alignment' in patch && patch.alignment !== undefined) block.meta.layout.alignment = patch.alignment;
    if ('indent_left' in patch && patch.indent_left !== undefined) block.meta.layout.indent_left = patch.indent_left;
    if ('indent_first_line' in patch && patch.indent_first_line !== undefined) block.meta.layout.indent_first_line = patch.indent_first_line;
    if ('indent_hanging' in patch && patch.indent_hanging !== undefined) block.meta.layout.indent_hanging = patch.indent_hanging;
    if ('tabs' in patch && patch.tabs !== undefined) block.meta.layout.tabs = patch.tabs ?? [];
    if ('list_marker_level' in patch && block.meta.list_marker && patch.list_marker_level != null) block.meta.list_marker.level = patch.list_marker_level;

    emit('layout-updated', pageNo, block);
  } catch (err) {
    errors[id] = err instanceof Error ? err.message : 'Update failed';
  } finally {
    busy[id] = false;
  }
}

onBeforeUnmount(() => {
  cancelEdit();
  observer?.disconnect();
});
</script>

<style scoped>
/* ponytail: document canvas & TipTap inline editor — keep all */
.block-editor__page {
  margin-bottom: 1.5rem;
}

.block-editor__page-header {
  border-bottom: 1px solid #e5e7eb;
  margin-bottom: 0.5rem;
  padding-bottom: 0.25rem;
}

.block-editor__block {
  position: relative;
  padding: 0.4rem 0.5rem;
  border-left: 3px solid transparent;
  cursor: pointer;
  transition: background 0.1s;
}

.block-editor__block:hover {
  background: #f9fafb;
}

.block--selected {
  border-left-color: #3b82f6;
  background: #eff6ff;
}

.block--needs-review {
  border-left-color: #f59e0b;
}

.block-type--title { font-weight: 700; font-size: 1.1em; }
.block-type--section_header { font-weight: 600; }
.block-type--footnote { font-size: 0.85em; color: #6b7280; }
.block-type--figure_caption { font-style: italic; color: #6b7280; }

.doc-indent-0 { margin-left: 0; }
.doc-indent-1 { margin-left: 1.5rem; }
.doc-indent-2 { margin-left: 3rem; }
.doc-indent-3 { margin-left: 4.5rem; }
.doc-indent-4 { margin-left: 6rem; }
.doc-indent-5 { margin-left: 7.5rem; }
.doc-indent-6 { margin-left: 9rem; }
.doc-indent-7 { margin-left: 10.5rem; }
.doc-indent-8 { margin-left: 12rem; }
.doc-indent-9 { margin-left: 13.5rem; }
.doc-indent-10 { margin-left: 15rem; }

.block-editor__text-row {
  display: flex;
  gap: 0.4rem;
  align-items: baseline;
  flex-wrap: wrap;
  cursor: text;
}

.block-editor__marker {
  white-space: nowrap;
  flex-shrink: 0;
}

.block-editor__controls {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.25rem;
  flex-wrap: wrap;
}

.block-editor__control-group {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.block-editor__level-badge {
  display: inline-block;
  min-width: 1.5rem;
  text-align: center;
  font-size: 0.8rem;
  background: #e5e7eb;
  border-radius: 4px;
  padding: 0 4px;
}

.block-editor__level-select {
  font-size: 0.8rem;
  padding: 1px 4px;
}

.block-editor__error {
  color: #ef4444;
}

.block-editor__image {
  margin: 0;
  text-align: center;
}

.block-editor__image-img {
  max-width: 100%;
  height: auto;
}

.block-editor__image-missing {
  padding: 1rem;
  background: #f3f4f6;
  border: 1px dashed #d1d5db;
}

.block-editor__table-wrap {
  display: flex;
  justify-content: center;
}

.block-editor__table-html {
  overflow-x: auto;
}

/* TipTap editor shell */
.block-editor__tiptap-shell {
  border: 1px solid #3b82f6;
  border-radius: 4px;
  padding: 0.25rem;
  background: #fff;
}

.block-editor__toolbar {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  padding: 4px;
  border-bottom: 1px solid #e5e7eb;
  margin-bottom: 4px;
}

.block-editor__toolbar .btn.active {
  background: #dbeafe;
  border-color: #3b82f6;
}

.block-editor__alignment-group {
  display: inline-flex;
  gap: 4px;
  align-items: center;
}

.block-editor__alignment-group .btn.active {
  background: #e7dfcf;
  border-color: #5f5240;
}

.block-editor__tiptap-content {
  min-height: 4rem;
  padding: 4px 8px;
  outline: none;
  line-height: 1.8;
}

.block-editor__tiptap-content :deep(.ProseMirror) {
  outline: none;
  min-height: 4rem;
}

.block-editor__diff {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  padding: 4px;
  margin-bottom: 4px;
  background: #f9fafb;
  border-radius: 4px;
  font-size: 0.8rem;
}

.diff-col pre {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

/* มาตรา sticky pill */
.block-editor__matra-pill {
  position: sticky;
  top: 0;
  z-index: 2;
  display: inline-block;
  margin-bottom: 2px;
  padding: 1px 8px;
  background: #d1fae5;
  color: #047857;
  border-radius: 10px;
  font-size: 0.72rem;
  font-weight: 600;
  pointer-events: none;
}

/* Spell suggestions */
.block-editor__spell-row {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  margin-top: 4px;
}
</style>
