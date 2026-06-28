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
          'is-editing': item.block.block_id === editingBlockId,
          'needs-review': item.block.needs_review,
        }"
        @click="editMode && selectBlock(item.block.block_id)"
      >
        <div v-if="item.block.type === 'image'" class="doc-block__image">
          <img
            v-if="item.block.meta.image?.src_url"
            :src="item.block.meta.image.src_url"
            :alt="item.block.meta.image.caption ?? 'document image'"
          >
          <p v-else class="hint">ไม่พบรูปภาพสำหรับบล็อกนี้</p>
        </div>

        <div
          v-else-if="item.block.type === 'table'"
          class="doc-block__html doc-block__table"
          v-html="renderReadOnlyHtml(item.block)"
        ></div>

        <div
          v-else
          class="doc-block__body"
          @click.stop="editMode && isEditable(item.block) && startEdit(item)"
        >
          <EditorContent
            v-if="item.block.block_id === editingBlockId"
            :editor="editor"
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
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import DOMPurify from 'dompurify';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import { patchBlock } from '../api/client';
import type { DocumentBlock, ThaiFont } from '../types/document';

interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'startEdit' | 'save' | 'cancel';
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
}

const props = defineProps<{
  documentId: string;
  blocks: BlockItem[];
  selectedBlockId: string | null;
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
  blockSaved: [string];
  editCancelled: [];
  editorState: [EditorStateSnapshot];
}>();

const scrollContainer = ref<HTMLElement | null>(null);
const editingBlockId = ref<string | null>(null);
const busy = ref(false);
const errorMessage = ref('');
const visibleEntries = new Map<string, { offset: number; ratio: number }>();

const editor = useEditor({
  extensions: [StarterKit, Underline],
  content: '<p></p>',
  editable: false,
  onUpdate: emitEditorState,
  onSelectionUpdate: emitEditorState,
  onTransaction: emitEditorState,
});

const fontClass = computed(() => `doc-font--${props.font}`);

const activeItem = computed(() =>
  props.blocks.find((item) => item.block.block_id === editingBlockId.value) ?? null,
);

let observer: IntersectionObserver | null = null;

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
  () => props.blocks.map((item) => item.block.block_id).join('|'),
  () => setupBlockObserver(),
  { flush: 'post', immediate: true },
);

watch(
  () => props.toolbarCommand?.id,
  () => applyToolbarCommand(props.toolbarCommand),
);

watch(
  () => props.editMode,
  (next) => {
    if (!next && editingBlockId.value !== null) {
      cancelEdit();
    }
  },
);

onBeforeUnmount(() => {
  observer?.disconnect();
  editor.value?.destroy();
});

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
  const active = Boolean(editingBlockId.value && editor.value);
  emit('editorState', {
    active,
    canUndo: active ? editor.value?.can().undo() ?? false : false,
    canRedo: active ? editor.value?.can().redo() ?? false : false,
    isBold: active ? editor.value?.isActive('bold') ?? false : false,
    isItalic: active ? editor.value?.isActive('italic') ?? false : false,
    isUnderline: active ? editor.value?.isActive('underline') ?? false : false,
    isBulletList: active ? editor.value?.isActive('bulletList') ?? false : false,
    isOrderedList: active ? editor.value?.isActive('orderedList') ?? false : false,
  });
}

function selectBlock(blockId: string): void {
  emit('selectBlock', blockId);
}

function isEditable(block: DocumentBlock): boolean {
  return block.type !== 'image' && block.type !== 'table';
}

function startEdit(item: BlockItem): void {
  if (busy.value) return;
  if (!isEditable(item.block) || !editor.value) return;

  editingBlockId.value = item.block.block_id;
  errorMessage.value = '';
  emit('selectBlock', item.block.block_id);
  editor.value.commands.setContent(readOnlyHtml(item.block), false);
  editor.value.setEditable(true);

  nextTick(() => {
    editor.value?.commands.focus('end');
    emitEditorState();
  });
}

function cancelEdit(): void {
  editingBlockId.value = null;
  errorMessage.value = '';
  editor.value?.commands.clearContent();
  editor.value?.setEditable(false);
  emitEditorState();
  emit('editCancelled');
}

async function saveActiveBlock(): Promise<void> {
  if (!editor.value || !activeItem.value) return;

  busy.value = true;
  errorMessage.value = '';

  try {
    await patchBlock(props.documentId, activeItem.value.block.block_id, {
      page_no: activeItem.value.page_no,
      approved_text: editor.value.getText({ blockSeparator: '\n' }),
      reviewed_html: editor.value.getHTML(),
      mark_uncertain: false,
      type: activeItem.value.block.type,
      reading_order: activeItem.value.block.reading_order,
      bbox: activeItem.value.block.bbox,
    });
    emit('blockSaved', activeItem.value.block.block_id);
    cancelEdit();
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'บันทึกไม่สำเร็จ';
  } finally {
    busy.value = false;
  }
}

function applyToolbarCommand(command: ToolbarCommand | null): void {
  if (!command || !editor.value) return;

  if (command.type === 'startEdit') {
    const target = props.blocks.find(
      (item) => item.block.block_id === props.selectedBlockId && isEditable(item.block),
    );
    if (target) startEdit(target);
    return;
  }

  if (command.type === 'save') {
    if (busy.value) return;
    void saveActiveBlock();
    return;
  }

  if (command.type === 'cancel') {
    cancelEdit();
    return;
  }

  if (editingBlockId.value === null) return;

  const chain = editor.value.chain().focus();
  if (command.type === 'undo') chain.undo().run();
  else if (command.type === 'redo') chain.redo().run();
  else if (command.type === 'bold') chain.toggleBold().run();
  else if (command.type === 'italic') chain.toggleItalic().run();
  else if (command.type === 'underline') chain.toggleUnderline().run();
  else if (command.type === 'bulletList') chain.toggleBulletList().run();
  else if (command.type === 'orderedList') chain.toggleOrderedList().run();

  emitEditorState();
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
</script>
