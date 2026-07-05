<template>
  <section
    ref="scrollContainer"
    class="compose-editor-surface"
    :class="[fontClass, { 'is-edit-mode': editMode }]"
    :style="{ '--compose-font-size': `${fontSize}pt` }"
  >
    <div class="compose-paper">
      <article
        v-for="item in blocks"
        :id="`compose-block-${item.block.block_id}`"
        :key="item.block.block_id"
        :data-type="item.block.type"
        class="doc-block"
        :class="{
          'is-selected': item.block.block_id === selectedBlockId,
          'is-multi-selected': selectedBlockIds.has(item.block.block_id),
          'needs-review': item.block.needs_review,
        }"
        @click="editMode && selectBlock(item.block.block_id)"
      >
        <button
          class="doc-block__select-check"
          :class="{ 'is-checked': selectedBlockIds.has(item.block.block_id) }"
          @click.stop="toggleBlockSelection(item.block.block_id, $event)"
        >
          <v-icon
            :icon="selectedBlockIds.has(item.block.block_id) ? 'mdi-checkbox-marked-circle' : 'mdi-checkbox-blank-circle-outline'"
            size="18"
          />
        </button>
        <div v-if="item.block.type === 'image'" class="doc-block__image">
          <ResizableImage
            v-if="item.block.meta.image?.src_url"
            :src="item.block.meta.image.src_url"
            :alt="item.block.meta.image.caption ?? 'document image'"
            :edit-mode="editMode"
          />
          <div v-else class="text-caption text-medium-emphasis">ไม่พบรูปภาพสำหรับบล็อกนี้</div>
        </div>

        <div
          v-else-if="item.block.type === 'table'"
          class="doc-block__html doc-block__table"
          v-html="renderReadOnlyHtml(item.block)"
        ></div>

        <div v-else class="doc-block__body">
          <EditorContent
            v-if="editMode && isEditable(item.block) && editors[item.block.block_id]"
            :editor="editors[item.block.block_id]"
            class="doc-block__editor"
          />
          <div
            v-else
            class="doc-block__html"
            v-html="renderReadOnlyHtml(item.block)"
          ></div>
        </div>
      </article>
    </div>

    <div v-if="errorMessage" class="doc-edit-error">
      <v-icon icon="mdi-alert-circle-outline" size="16" color="error" />
      <span>{{ errorMessage }}</span>
      <v-btn size="x-small" variant="text" @click="errorMessage = ''">ปิด</v-btn>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import DOMPurify from 'dompurify';
import { Editor, type Extensions } from '@tiptap/core';
import { EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import { useBlockStore } from '../../stores/blockStore';
import { IndentExtension } from '../../extensions/IndentExtension';

const blockStore = useBlockStore();
import ResizableImage from '../shared/ResizableImage.vue';
import type { DocumentBlock, ThaiFont } from '../../types/document';

interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'saveAll' | 'cancelAll' | 'indent' | 'outdent' | 'setAlignment' | 'splitBlock';
  value?: string;
}

interface BlockItem {
  page_no: number;
  block: DocumentBlock;
}

interface ScrollTarget {
  blockId: string;
  requestId: number;
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

const props = defineProps<{
  documentId: string;
  blocks: BlockItem[];
  selectedBlockId: string | null;
  selectedBlockIds: Set<string>;
  scrollTarget: ScrollTarget | null;
  font: ThaiFont;
  fontSize: number;
  toolbarCommand: ToolbarCommand | null;
  editMode: boolean;
  mode?: 'review' | 'compose';
}>();

const emit = defineEmits<{
  selectBlock: [string];
  visibleBlockChange: [string];
  allBlocksSaved: [];
  editCancelled: [];
  editorState: [EditorStateSnapshot];
  selectedBlocksChange: [Set<string>];
  splitBlock: [{ blockId: string; pageNo: number; beforeText: string; beforeHtml: string; afterText: string; afterHtml: string }];
}>();

const scrollContainer = ref<HTMLElement | null>(null);
const busy = ref(false);
const errorMessage = ref('');
const focusedBlockId = ref<string | null>(null);
const dirtyBlockIds = ref(new Set<string>());
const editors = shallowRef<Record<string, Editor>>({});
const visibleEntries = new Map<string, { offset: number; ratio: number }>();
const lastCheckedId = ref<string | null>(null);

const editorExtensions: Extensions = [
  StarterKit,
  Underline,
  TextAlign.configure({ types: ['heading', 'paragraph'] }),
  IndentExtension,
];

const fontClass = computed(() => `doc-font--${props.font}`);

let observer: IntersectionObserver | null = null;

watch(
  () => props.blocks.map((item) => item.block.block_id).join('|'),
  (next, prev) => {
    setupBlockObserver();
    if (props.editMode && next !== prev) {
      const current = editors.value;
      const newEditors = { ...current };
      let changed = false;
      props.blocks.forEach((item) => {
        if (isEditable(item.block) && !newEditors[item.block.block_id]) {
          newEditors[item.block.block_id] = createEditor(item);
          changed = true;
        }
      });
      if (changed) editors.value = newEditors;
    }
  },
  { flush: 'post', immediate: true },
);

watch(
  () => props.scrollTarget?.requestId,
  (next) => {
    if (!next) return;
    nextTick(() => {
      if (props.scrollTarget) scrollToBlock(props.scrollTarget.blockId);
    });
  },
  { immediate: true },
);

watch(
  () => props.toolbarCommand?.id,
  () => applyToolbarCommand(props.toolbarCommand),
);

watch(
  () => props.editMode,
  (next) => {
    if (next) {
      nextTick(() => {
        Object.values(editors.value).forEach((e) => e.destroy());
        const newEditors: Record<string, Editor> = {};
        props.blocks.forEach((item) => {
          if (!isEditable(item.block)) return;
          newEditors[item.block.block_id] = createEditor(item);
        });
        editors.value = newEditors;
      });
    } else {
      Object.values(editors.value).forEach((e) => e.destroy());
      editors.value = {};
      focusedBlockId.value = null;
      dirtyBlockIds.value = new Set();
      emitEditorState();
    }
  },
);

onBeforeUnmount(() => {
  observer?.disconnect();
  visibleEntries.clear();
  Object.values(editors.value).forEach((e) => e.destroy());
});

function createEditor(item: BlockItem): Editor {
  return new Editor({
    extensions: editorExtensions,
    content: readOnlyHtml(item.block),
    editable: true,
    onFocus: () => {
      focusedBlockId.value = item.block.block_id;
      emitEditorState();
    },
    onUpdate: () => {
      dirtyBlockIds.value = new Set([...dirtyBlockIds.value, item.block.block_id]);
      emitEditorState();
    },
    onSelectionUpdate: emitEditorState,
  });
}

function setupBlockObserver(): void {
  observer?.disconnect();
  visibleEntries.clear();

  nextTick(() => {
    const root = scrollContainer.value;
    if (!root) return;

    observer = new IntersectionObserver(onBlockIntersection, {
      root,
      threshold: [0, 0.1, 0.25, 0.5, 0.75],
    });

    props.blocks.forEach((item) => {
      const element = document.getElementById(`compose-block-${item.block.block_id}`);
      if (element) observer?.observe(element);
    });
  });
}

function onBlockIntersection(entries: IntersectionObserverEntry[]): void {
  entries.forEach((entry) => {
    const blockId = entry.target.id.replace('compose-block-', '');
    if (!entry.isIntersecting) {
      visibleEntries.delete(blockId);
      return;
    }
    const rootTop = entry.rootBounds?.top ?? scrollContainer.value?.getBoundingClientRect().top ?? 0;
    visibleEntries.set(blockId, {
      offset: Math.abs(entry.boundingClientRect.top - rootTop),
      ratio: entry.intersectionRatio,
    });
  });

  const [nearest] = [...visibleEntries.entries()].sort((left, right) => {
    const offsetDelta = left[1].offset - right[1].offset;
    if (Math.abs(offsetDelta) > 8) return offsetDelta;
    return right[1].ratio - left[1].ratio;
  });

  if (nearest && nearest[0] !== props.selectedBlockId) {
    emit('visibleBlockChange', nearest[0]);
  }
}

function scrollToBlock(blockId: string): void {
  const container = scrollContainer.value;
  const element = document.getElementById(`compose-block-${blockId}`);
  if (!container || !element) return;

  const containerRect = container.getBoundingClientRect();
  const elementRect = element.getBoundingClientRect();
  const top = container.scrollTop + elementRect.top - containerRect.top - 18;
  const distance = Math.abs(top - container.scrollTop);
  container.scrollTo({ top, behavior: distance < 900 ? 'smooth' : 'auto' });
}

function emitEditorState(): void {
  const focusedEditor = focusedBlockId.value ? editors.value[focusedBlockId.value] : null;
  const active = Boolean(focusedEditor);
  const alignment: EditorStateSnapshot['alignment'] = active ? (
    focusedEditor?.isActive({ textAlign: 'center' }) ? 'center' :
    focusedEditor?.isActive({ textAlign: 'right' })  ? 'right'  :
    focusedEditor?.isActive({ textAlign: 'justify' }) ? 'justify' : 'left'
  ) : 'left';
  emit('editorState', {
    active,
    canUndo: active ? focusedEditor?.can().undo() ?? false : false,
    canRedo: active ? focusedEditor?.can().redo() ?? false : false,
    isBold: active ? focusedEditor?.isActive('bold') ?? false : false,
    isItalic: active ? focusedEditor?.isActive('italic') ?? false : false,
    isUnderline: active ? focusedEditor?.isActive('underline') ?? false : false,
    isBulletList: active ? focusedEditor?.isActive('bulletList') ?? false : false,
    isOrderedList: active ? focusedEditor?.isActive('orderedList') ?? false : false,
    alignment,
  });
}

function selectBlock(blockId: string): void {
  emit('selectBlock', blockId);
}

function isEditable(block: DocumentBlock): boolean {
  return block.type !== 'image' && block.type !== 'table';
}

async function saveAllBlocks(): Promise<void> {
  if (busy.value || dirtyBlockIds.value.size === 0) return;
  busy.value = true;
  errorMessage.value = '';

  const dirtyArray = [...dirtyBlockIds.value];
  const results = await Promise.allSettled(
    dirtyArray.map((blockId) => {
      const item = props.blocks.find((b) => b.block.block_id === blockId);
      const ed = editors.value[blockId];
      if (!item || !ed) return Promise.resolve();
      return blockStore.patch(props.documentId, blockId, {
        page_no: item.page_no,
        approved_text: ed.getText({ blockSeparator: '\n' }),
        reviewed_html: ed.getHTML(),
        mark_uncertain: false,
        type: item.block.type,
        reading_order: item.block.reading_order,
        bbox: item.block.bbox,
      });
    }),
  );

  const newDirty = new Set(dirtyBlockIds.value);
  results.forEach((result, i) => {
    if (result.status === 'fulfilled') newDirty.delete(dirtyArray[i]);
  });
  dirtyBlockIds.value = newDirty;

  const failCount = results.filter((r) => r.status === 'rejected').length;
  if (failCount > 0) {
    errorMessage.value = `บันทึกไม่สำเร็จ ${failCount} block — กดบันทึกอีกครั้ง`;
  } else {
    emit('allBlocksSaved');
  }

  busy.value = false;
}

function cancelEdit(): void {
  Object.values(editors.value).forEach((e) => e.destroy());
  editors.value = {};
  focusedBlockId.value = null;
  dirtyBlockIds.value = new Set();
  emitEditorState();
  emit('editCancelled');
}

function applyToolbarCommand(command: ToolbarCommand | null): void {
  if (!command) return;

  if (command.type === 'saveAll') {
    if (!busy.value) void saveAllBlocks();
    return;
  }

  if (command.type === 'cancelAll') {
    cancelEdit();
    return;
  }

  const focusedEditor = focusedBlockId.value ? editors.value[focusedBlockId.value] : null;
  if (!focusedEditor) return;

  const chain = focusedEditor.chain().focus();
  if (command.type === 'undo') chain.undo().run();
  else if (command.type === 'redo') chain.redo().run();
  else if (command.type === 'bold') chain.toggleBold().run();
  else if (command.type === 'italic') chain.toggleItalic().run();
  else if (command.type === 'underline') chain.toggleUnderline().run();
  else if (command.type === 'bulletList') chain.toggleBulletList().run();
  else if (command.type === 'orderedList') chain.toggleOrderedList().run();
  else if (command.type === 'indent') chain.increaseIndent().run();
  else if (command.type === 'outdent') chain.decreaseIndent().run();
  else if (command.type === 'setAlignment' && command.value) {
    chain.setTextAlign(command.value).run();
  }

  emitEditorState();
}

function toggleBlockSelection(blockId: string, event: MouseEvent): void {
  const next = new Set(props.selectedBlockIds);
  if (event.shiftKey && lastCheckedId.value) {
    const ids = props.blocks.map((b) => b.block.block_id);
    const a = ids.indexOf(lastCheckedId.value);
    const b = ids.indexOf(blockId);
    const [start, end] = a < b ? [a, b] : [b, a];
    for (let i = start; i <= end; i++) next.add(ids[i]);
  } else if (next.has(blockId)) {
    next.delete(blockId);
  } else {
    next.add(blockId);
  }
  lastCheckedId.value = blockId;
  emit('selectedBlocksChange', next);
}

function executeSplitAtCursor(): void {
  const blockId = focusedBlockId.value;
  if (!blockId) return;
  const ed = editors.value[blockId];
  if (!ed) return;
  const item = props.blocks.find((b) => b.block.block_id === blockId);
  if (!item) return;

  const { from } = ed.state.selection;
  const doc = ed.state.doc;

  const tempBefore = new Editor({
    extensions: editorExtensions,
    content: doc.cut(0, from).toJSON(),
  });
  const tempAfter = new Editor({
    extensions: editorExtensions,
    content: doc.cut(from, doc.content.size).toJSON(),
  });

  emit('splitBlock', {
    blockId,
    pageNo: item.page_no,
    beforeText: tempBefore.getText({ blockSeparator: '\n' }),
    beforeHtml: tempBefore.getHTML(),
    afterText: tempAfter.getText({ blockSeparator: '\n' }),
    afterHtml: tempAfter.getHTML(),
  });

  tempBefore.destroy();
  tempAfter.destroy();
}

function renderReadOnlyHtml(block: DocumentBlock): string {
  return DOMPurify.sanitize(readOnlyHtml(block), {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span', 'div', 'sub', 'sup'],
    ALLOWED_ATTR: ['class', 'style', 'colspan', 'rowspan'],
  });
}

function readOnlyHtml(block: DocumentBlock): string {
  if (block.type === 'table') {
    return String(block.meta.table_html ?? block.meta.reviewed_html ?? '<p></p>');
  }
  return String(block.meta.reviewed_html ?? `<p>${escapeHtml(block.approved_text || block.normalized_text).replaceAll('\n', '<br>')}</p>`);
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

defineExpose({ executeSplitAtCursor });
</script>

<style scoped>
.doc-block {
  position: relative;
}
.doc-block__select-check {
  position: absolute;
  top: 6px;
  left: -28px;
  opacity: 0;
  transition: opacity 0.15s;
  background: none;
  border: none;
  cursor: pointer;
  color: rgb(var(--v-theme-primary));
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.doc-block:hover .doc-block__select-check,
.doc-block.is-multi-selected .doc-block__select-check {
  opacity: 1;
}
.doc-block.is-multi-selected {
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 8%, transparent);
  border-left: 3px solid rgb(var(--v-theme-primary));
}
</style>
