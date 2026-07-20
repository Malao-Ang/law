# ComposePage Word-Like Document View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the card-based block layout on ComposePage with a continuous Word-like document view that defaults to read-only preview and switches to TipTap editing when the user clicks [แก้ไข] in the toolbar.

**Architecture:** Four targeted changes — `ComposeSectionEditor.vue` drops all card chrome (header chips, footer buttons) and gains an `editMode` prop that gates block clicking; `ComposeToolbar.vue` gains [แก้ไข] / [บันทึก] + [ยกเลิก] buttons that appear based on whether TipTap is active; `DocumentComposeWorkspace.vue` owns the `editMode` ref and wires the toolbar↔editor event flow; `app.css` replaces `.compose-card` styles with `.doc-block` Word-like styles. No new components, no new API calls.

**Tech Stack:** Vue 3 · Vuetify 4 · TipTap 2 · CSS

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `apps/app-laravel/resources/js/components/ComposeSectionEditor.vue` | Block rendering + TipTap; add `editMode` prop, remove card chrome | Modify |
| `apps/app-laravel/resources/js/components/ComposeToolbar.vue` | Add [แก้ไข] / [บันทึก] / [ยกเลิก] buttons | Modify |
| `apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue` | Own `editMode` state, wire toolbar↔editor events | Modify |
| `apps/app-laravel/resources/css/app.css` | Replace `.compose-card` CSS with `.doc-block` CSS | Modify |

No other files change.

---

## Task 1: Rewrite ComposeSectionEditor — Word-like layout + editMode prop

**Files:**
- Modify: `apps/app-laravel/resources/js/components/ComposeSectionEditor.vue`

**Why:** The card layout (`article.compose-card` with chips header + footer buttons) is replaced by `article.doc-block` — plain content elements that flow as a continuous document. An `editMode` prop gates whether clicking a block activates TipTap. New toolbar commands `startEdit`, `save`, `cancel` let the parent drive the editor lifecycle. A new `editCancelled` event lets the workspace reset its `editMode` state when the user cancels. `SectionActionBar` is removed (it belonged to the footer that we're eliminating).

- [ ] **Step 1: Replace the entire `<template>` block**

Open `apps/app-laravel/resources/js/components/ComposeSectionEditor.vue` and replace the entire content between `<template>` and `</template>` with:

```html
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
```

- [ ] **Step 2: Replace the entire `<script setup lang="ts">` block**

Replace everything between `<script setup lang="ts">` and `</script>` (lines 99–484 in the current file) with:

```typescript
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import DOMPurify from 'dompurify';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import { patchBlock } from '../api/client';
import type { BlockType, DocumentBlock, ThaiFont } from '../types/document';

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
    const target =
      props.blocks.find((item) => item.block.block_id === props.selectedBlockId && isEditable(item.block))
      ?? props.blocks.find((item) => isEditable(item.block));
    if (target) startEdit(target);
    return;
  }

  if (command.type === 'save') {
    void saveActiveBlock();
    return;
  }

  if (command.type === 'cancel') {
    cancelEdit();
    emit('editCancelled');
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
```

- [ ] **Step 3: Verify no `<style>` block exists**

The component has no `<style>` block — all styles live in `app.css`. Do not add one.

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/components/ComposeSectionEditor.vue
git commit -m "feat(compose): replace card layout with Word-like doc-block

Removes card chrome (header chips, page labels, per-block footer
buttons). Blocks render as continuous document text. New editMode prop
gates block clicking. Extends ToolbarCommand type to include startEdit /
save / cancel so the toolbar can drive the editor lifecycle.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 2: Add preview/edit toggle buttons to ComposeToolbar

**Files:**
- Modify: `apps/app-laravel/resources/js/components/ComposeToolbar.vue`

**Why:** The toolbar needs two new button states on the right side. When `editorState.active` is false (no TipTap block active) the toolbar shows [✏️ แก้ไข] which emits `toggle:editMode`. When `editorState.active` is true (a block is being edited) the toolbar shows [💾 บันทึก] + [✕ ยกเลิก] which emit `action: 'saveActiveBlock'` / `action: 'cancelActiveBlock'`.

- [ ] **Step 1: Extend `ToolbarAction` type**

In the `<script setup lang="ts">` block, find:

```typescript
type ToolbarAction = 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'export';
```

Replace with:

```typescript
type ToolbarAction = 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'export' | 'saveActiveBlock' | 'cancelActiveBlock';
```

- [ ] **Step 2: Add `toggle:editMode` to `defineEmits`**

Find:

```typescript
defineEmits<{
  reload: [];
  action: [ToolbarAction];
  'toggle:navigator': [];
  'toggle:details': [];
  'update:font': [ThaiFont];
  'update:fontSize': [number];
}>();
```

Replace with:

```typescript
defineEmits<{
  reload: [];
  action: [ToolbarAction];
  'toggle:navigator': [];
  'toggle:details': [];
  'toggle:editMode': [];
  'update:font': [ThaiFont];
  'update:fontSize': [number];
}>();
```

- [ ] **Step 3: Replace the entire `.compose-toolbar-actions` div in the template**

Find the `<div class="compose-toolbar-actions">` block (currently ends at line 88 `</div>`) and replace it entirely with:

```html
<div class="compose-toolbar-actions">
  <v-btn
    v-for="button in commandButtons"
    :key="button.action"
    :active="isButtonActive(button.action)"
    :disabled="isDisabled(button.action)"
    :icon="button.icon"
    :title="button.label"
    size="small"
    variant="text"
    @click="$emit('action', button.action)"
  />

  <v-divider vertical class="compose-toolbar-divider" />

  <v-btn
    :disabled="props.correctionInProgress"
    prepend-icon="mdi-download-outline"
    size="small"
    variant="tonal"
    color="primary"
    :title="props.correctionInProgress ? 'รอ AI correction เสร็จ' : 'ส่งออก RAG JSON'"
    @click="$emit('action', 'export')"
  >
    Export
  </v-btn>

  <v-btn
    :to="alternateRoute"
    :text="alternateRouteLabel"
    prepend-icon="mdi-swap-horizontal"
    size="small"
    variant="tonal"
  />
  <v-btn
    icon="mdi-refresh"
    size="small"
    title="รีโหลดข้อมูล"
    variant="text"
    @click="$emit('reload')"
  />
  <v-btn
    icon="mdi-file-document-edit-outline"
    size="small"
    title="ข้อมูลหนังสือ"
    variant="text"
    @click="$emit('toggle:details')"
  />

  <v-divider vertical class="compose-toolbar-divider" />

  <v-btn
    v-if="!props.editorState.active"
    size="small"
    variant="tonal"
    color="primary"
    prepend-icon="mdi-pencil-outline"
    @click="$emit('toggle:editMode')"
  >
    แก้ไข
  </v-btn>

  <template v-else>
    <v-btn
      size="small"
      variant="tonal"
      color="success"
      prepend-icon="mdi-content-save-outline"
      @click="$emit('action', 'saveActiveBlock')"
    >
      บันทึก
    </v-btn>
    <v-btn
      size="small"
      variant="outlined"
      prepend-icon="mdi-close"
      @click="$emit('action', 'cancelActiveBlock')"
    >
      ยกเลิก
    </v-btn>
  </template>
</div>
```

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/components/ComposeToolbar.vue
git commit -m "feat(compose): add edit mode toggle and save/cancel to toolbar

Adds [แก้ไข] button when no block is being edited; shows [บันทึก] +
[ยกเลิก] when TipTap is active. These drive the preview ↔ edit mode
flow for the Word-like document view.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 3: Add editMode state and event wiring to DocumentComposeWorkspace

**Files:**
- Modify: `apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue`

**Why:** The workspace owns `editMode: Ref<boolean>`. Clicking [แก้ไข] sets `editMode = true` and sends a `startEdit` toolbar command to the editor. The editor then activates TipTap on the selected/first block. After save or cancel, the workspace resets `editMode = false`.

- [ ] **Step 1: Add `editMode` ref after existing refs**

In `<script setup>`, after the line `const toolbarCommand = ref<ToolbarCommand | null>(null);` (around line 147), add:

```typescript
const editMode = ref(false);
```

- [ ] **Step 2: Extend `ToolbarCommand` interface**

Find the `ToolbarCommand` interface in `DocumentComposeWorkspace.vue` (around lines 107–110):

```typescript
interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList';
}
```

Replace with:

```typescript
interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'startEdit' | 'save' | 'cancel';
}
```

- [ ] **Step 3: Replace `dispatchToolbarAction` to handle the new action types**

Find the existing function (around lines 309–318):

```typescript
function dispatchToolbarAction(type: ToolbarCommand['type'] | 'export'): void {
  if (type === 'export') {
    void triggerExport();
    return;
  }

  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type };
}
```

Replace with:

```typescript
function dispatchToolbarAction(type: ToolbarCommand['type'] | 'export' | 'saveActiveBlock' | 'cancelActiveBlock'): void {
  if (type === 'export') {
    void triggerExport();
    return;
  }

  if (type === 'saveActiveBlock') {
    toolbarCommandId += 1;
    toolbarCommand.value = { id: toolbarCommandId, type: 'save' };
    return;
  }

  if (type === 'cancelActiveBlock') {
    toolbarCommandId += 1;
    toolbarCommand.value = { id: toolbarCommandId, type: 'cancel' };
    return;
  }

  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type };
}
```

- [ ] **Step 4: Add `handleToggleEditMode` and `handleEditCancelled` functions**

Add these two functions directly after `dispatchToolbarAction`:

```typescript
function handleToggleEditMode(): void {
  editMode.value = true;
  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type: 'startEdit' };
}

function handleEditCancelled(): void {
  editMode.value = false;
}
```

- [ ] **Step 5: Add `onBlockSaved` function to also reset editMode**

Add after `handleEditCancelled`:

```typescript
function onBlockSaved(blockId: string): void {
  editMode.value = false;
  void reloadReview();
}
```

- [ ] **Step 6: Update the template — ComposeToolbar**

Find the `<ComposeToolbar` block and add `@toggle:editMode`:

```html
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
  :correction-in-progress="correctionInProgress || correctionFailed"
  @action="dispatchToolbarAction"
  @reload="reloadReview"
  @toggle:navigator="leftDrawer = !leftDrawer"
  @toggle:details="rightDrawer = !rightDrawer"
  @toggle:editMode="handleToggleEditMode"
  @update:font="font = $event"
  @update:font-size="fontSize = $event"
/>
```

- [ ] **Step 7: Update the template — ComposeSectionEditor**

Find the `<ComposeSectionEditor` block and add `:edit-mode`, `@block-saved`, and `@edit-cancelled`:

```html
<ComposeSectionEditor
  :document-id="documentId"
  :blocks="flatBlocks"
  :selected-block-id="selectedBlockId"
  :scroll-target="scrollTarget"
  :font="font"
  :font-size="fontSize"
  :toolbar-command="toolbarCommand"
  :edit-mode="editMode"
  :mode="mode"
  @select-block="selectedBlockId = $event"
  @visible-block-change="selectedBlockId = $event"
  @block-saved="onBlockSaved"
  @edit-cancelled="handleEditCancelled"
  @editor-state="editorState = $event"
/>
```

- [ ] **Step 8: Commit**

```bash
git add apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue
git commit -m "feat(compose): wire editMode state between toolbar and editor

DocumentComposeWorkspace now owns editMode ref. Clicking [แก้ไข] enters
edit mode and auto-activates the selected block in TipTap. Saving or
cancelling a block returns to preview mode.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 4: Replace compose-card CSS with doc-block Word-like styles

**Files:**
- Modify: `apps/app-laravel/resources/css/app.css`

**Why:** The visual appearance moves from floating cards (rounded borders, box-shadow per block, header/footer chrome) to a continuous Word-like document (white A4 paper container, no borders between blocks, active block gets a blue outline focus ring, edit-mode hover hint).

- [ ] **Step 1: Update `.compose-paper` to white A4 container**

Find:

```css
.compose-paper {
  max-width: 980px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 18px;
}
```

Replace with:

```css
.compose-paper {
  max-width: 900px;
  margin: 0 auto;
  background: #fff;
  border-radius: 4px;
  box-shadow: 0 2px 16px rgba(18, 63, 140, 0.10);
  padding: 60px 72px;
  display: flex;
  flex-direction: column;
  gap: 0;
}
```

- [ ] **Step 2: Replace the entire `.compose-card` block and all child rules with `.doc-block`**

Find and delete the block from `.compose-card {` through `.compose-card__table table { width: 100%; }` (lines 1358–1469 in the current file). This removes all of:

```
.compose-card { ... }
.compose-card.is-selected { ... }
.compose-card.needs-review { ... }
.compose-card__header, .compose-card__footer { ... }
.compose-card__chips { ... }
.compose-card__chip { ... }
.compose-card__chip--primary { ... }
.compose-card__chip--warning { ... }
.compose-card__meta { ... }
.compose-card__body, .compose-card__html, .compose-card__editor, .compose-card__table { ... }
.compose-card__html p { ... }
.compose-card__html p:last-child { ... }
.compose-card__editor { ... }
.compose-card__editor .ProseMirror { ... }
.compose-card__actions { ... }
.compose-card__image img { ... }
.compose-card__table { ... }
.compose-card__table table { ... }
```

In their place insert:

```css
.doc-block {
  position: relative;
}

.doc-block.needs-review {
  border-left: 3px solid #d9a23e;
  padding-left: 10px;
}

.doc-block.is-editing {
  outline: 2px solid #1976d2;
  outline-offset: 4px;
  border-radius: 2px;
}

.is-edit-mode .doc-block:not(.is-editing) .doc-block__body:hover {
  background: rgba(25, 118, 210, 0.04);
  border-radius: 2px;
  cursor: text;
}

.doc-block__html,
.doc-block__editor,
.doc-block__table {
  font-family: var(--compose-font-family, var(--doc-font-sarabun));
  font-size: var(--compose-font-size, 16pt);
  line-height: 1.75;
}

.doc-block__html p {
  margin: 0 0 0.8em;
}

.doc-block__html p:last-child {
  margin-bottom: 0;
}

.doc-block__editor .ProseMirror {
  outline: none;
  min-height: 2em;
}

.doc-block__image img {
  width: 100%;
  border: 1px solid #e1e8f5;
}

.doc-block__table {
  overflow-x: auto;
}

.doc-block__table table {
  width: 100%;
}

.doc-edit-error {
  position: sticky;
  bottom: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  background: #fef2f2;
  color: #b91c1c;
  border-top: 1px solid #fecaca;
  font-size: 14px;
}
```

- [ ] **Step 3: Remove `.compose-card` from responsive override at ~line 1518**

Find in the `@media (max-width: 720px)` block:

```css
  .compose-card,
  .compose-sidebar-card,
  .compose-toolbar-card {
    padding: 16px;
    border-radius: 18px;
  }
```

Replace with:

```css
  .compose-sidebar-card,
  .compose-toolbar-card {
    padding: 16px;
    border-radius: 18px;
  }
```

- [ ] **Step 4: Remove remaining `.compose-card` occurrences in the second theme block**

Search for any remaining `compose-card` occurrences in `app.css` (around the second theme/dark-mode block at lines 1735–1770) and delete those rules entirely. They were styling the card in alternate color schemes; the new `.doc-block` inherits paper background from `.compose-paper` which needs no theme override.

Run a quick check to confirm no `compose-card` remains:

```bash
grep -n "compose-card" apps/app-laravel/resources/css/app.css
```

Expected: no output (zero matches).

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/css/app.css
git commit -m "feat(compose): replace compose-card CSS with Word-like doc-block styles

compose-paper is now a white A4 sheet (white bg, shadow, 72px side
padding). Individual blocks lose card borders/chips and flow as continuous
text. Active block gets a blue outline. Edit-mode adds a cursor-text hover
hint via the .is-edit-mode parent class on the surface.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 5: Browser verification

- [ ] **Step 1: Start or verify dev server**

Ensure the Vite dev server is running:

```bash
docker compose ps
# laravel-vite should be Up; or run: npm run dev in apps/app-laravel/
```

- [ ] **Step 2: Run TypeScript check**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: zero errors.

- [ ] **Step 3: Open ComposePage in browser**

Navigate to `http://localhost:8000/documents/{any-existing-id}/compose`

- [ ] **Step 4: Verify preview mode (default)**

- Blocks render as continuous flowing text — no card borders, no rounded cards, no chips, no page labels
- Document appears as a white A4 sheet centered on the gray surface background
- Clicking anywhere in the document does NOT activate TipTap (cursor stays `default`)
- Toolbar right side shows [✏️ แก้ไข] button
- Formatter buttons (B, I, U, lists, undo/redo) are all grayed out / disabled

- [ ] **Step 5: Verify entering edit mode**

- Click [✏️ แก้ไข]
- The first (or currently selected) editable block activates — blue outline appears, TipTap cursor blinks
- Toolbar right side now shows [💾 บันทึก] and [✕ ยกเลิก] buttons (replacing [แก้ไข])
- Formatter buttons (B, I, U) are now enabled
- Hovering over other text blocks shows `cursor: text` and a faint blue hover background

- [ ] **Step 6: Verify switching blocks within edit mode**

- While one block is active, click another text block
- TipTap moves to the clicked block (blue outline moves, content updates)
- The previous block's unsaved edits are discarded (this is expected behavior)

- [ ] **Step 7: Verify save**

- In edit mode, make a text change → click [💾 บันทึก]
- A PATCH request fires to `/api/documents/{id}/blocks/{blockId}`
- Toolbar returns to showing [✏️ แก้ไข]
- The saved text is visible in the block's read-only view
- No blue outline on any block

- [ ] **Step 8: Verify cancel**

- Click [✏️ แก้ไข] → make a change → click [✕ ยกเลิก]
- No save request fires
- Toolbar returns to showing [✏️ แก้ไข]
- Original text is shown (change discarded)

- [ ] **Step 9: Verify error state**

- Disconnect from the network (disable Wi-Fi or kill Laravel container) while in edit mode
- Make a change → click [💾 บันทึก]
- An error bar appears at the bottom of the document area (red background, "บันทึกไม่สำเร็จ")
- The toolbar still shows [💾 บันทึก] and [✕ ยกเลิก] (user stays in edit mode to retry)
- Reconnect → click [💾 บันทึก] again → save succeeds, returns to preview

- [ ] **Step 10: Verify needs-review blocks**

- Blocks with `needs_review: true` should show a left amber border (`border-left: 3px solid #d9a23e`) even in the Word-like view

---

## Self-Review

**1. Spec coverage:**

| Requirement | Task |
|---|---|
| ComposePage defaults to read-only preview (no editing) | Task 1 (`editMode = false` initial, no TipTap activation) |
| Button to enter edit mode | Task 2 ([แก้ไข] in ComposeToolbar) |
| Continuous Word-like document view (no cards) | Tasks 1 + 4 (`.doc-block` CSS + template) |
| Clicking a block in edit mode activates TipTap | Task 1 (`startEdit` guarded by `editMode`) |
| [บันทึก] saves and returns to preview | Tasks 2 + 3 (`saveActiveBlock` → `onBlockSaved` → `editMode = false`) |
| [ยกเลิก] discards and returns to preview | Tasks 2 + 3 (`cancelActiveBlock` → `handleEditCancelled` → `editMode = false`) |
| Save error stays in edit mode (no silent redirect) | Task 1 (`saveActiveBlock` only emits `blockSaved` on success) |
| `needs-review` blocks still visually flagged | Task 4 (`.doc-block.needs-review` amber left border) |

No spec requirement is unimplemented.

**2. Placeholder scan:** No "TBD", "TODO", or vague steps. Every code block is complete.

**3. Type consistency:**
- `ToolbarCommand.type` extended to `'startEdit' | 'save' | 'cancel'` in BOTH `ComposeSectionEditor.vue` (Task 1) and `DocumentComposeWorkspace.vue` (Task 3) — consistent.
- `editMode: boolean` prop added to `ComposeSectionEditor` (Task 1) and passed from workspace (Task 3) — consistent.
- `editCancelled` emit in `ComposeSectionEditor` (Task 1) handled as `@edit-cancelled` in workspace template (Task 3) — consistent.
- `onBlockSaved(blockId: string)` in workspace (Task 3) matches `blockSaved: [string]` emit in editor (Task 1) — consistent.
- `ToolbarAction` type in `ComposeToolbar` (Task 2) adds `'saveActiveBlock' | 'cancelActiveBlock'`; workspace `dispatchToolbarAction` parameter includes these (Task 3) — consistent.
