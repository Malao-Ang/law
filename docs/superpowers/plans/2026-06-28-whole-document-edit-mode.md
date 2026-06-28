# Whole-Document Edit Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace per-block double-click-edit/save with a Word-like whole-document edit mode — enter once, click any block to type, press "บันทึกทั้งหมด" once to save all changes.

**Architecture:** When `editMode` is true, each text block gets its own TipTap `Editor` instance (stored in a `shallowRef<Record<string, Editor>>`). A `dirtyBlockIds` set tracks changed blocks. "บันทึกทั้งหมด" runs `Promise.allSettled` over dirty blocks; editing continues without page reload. "ออก" destroys all editors and reloads from server.

**Tech Stack:** Vue 3 · TipTap 2 (`@tiptap/core` direct instantiation) · TypeScript · Vuetify 4

---

## File Structure

| Action | Path | What changes |
|---|---|---|
| Modify | `apps/app-laravel/resources/js/components/ComposeSectionEditor.vue` | Core: replace single editor with per-block editor Map, new save/cancel flow |
| Modify | `apps/app-laravel/resources/js/components/ComposeToolbar.vue` | Add `editMode` prop, replace save/cancel buttons, update ToolbarAction type |
| Modify | `apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue` | Wire new event names, simplify edit/save handlers |

---

## Task 1: Refactor ComposeSectionEditor to multi-editor architecture

**Files:**
- Modify: `apps/app-laravel/resources/js/components/ComposeSectionEditor.vue`

Context: Currently one shared `editor = useEditor(...)` instance swapped between blocks via `editingBlockId`. Replace with `editors: shallowRef<Record<string, Editor>>` (one instance per block) and `focusedBlockId` (tracks which block has cursor for toolbar state). Images and tables are unchanged (still static / ResizableImage). The `IndentExtension` was already added in a prior commit.

- [ ] **Step 1: Read the current file to confirm line numbers**

```bash
# In PowerShell from project root:
(Get-Content apps/app-laravel/resources/js/components/ComposeSectionEditor.vue).Count
```

Expected: ~384 lines. Confirm the script section starts at line 65.

- [ ] **Step 2: Replace the entire `<script setup>` block**

Replace from `<script setup lang="ts">` (line 65) to the closing `</script>` (line 383) with:

```typescript
<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import DOMPurify from 'dompurify';
import { Editor, type Extensions } from '@tiptap/core';
import { EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import { patchBlock } from '../api/client';
import { IndentExtension } from '../extensions/IndentExtension';
import ResizableImage from './ResizableImage.vue';
import type { DocumentBlock, ThaiFont } from '../types/document';

interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'saveAll' | 'cancelAll' | 'indent' | 'outdent' | 'setAlignment';
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
}>();

const scrollContainer = ref<HTMLElement | null>(null);
const busy = ref(false);
const errorMessage = ref('');
const focusedBlockId = ref<string | null>(null);
const dirtyBlockIds = ref(new Set<string>());
const editors = shallowRef<Record<string, Editor>>({});
const visibleEntries = new Map<string, { offset: number; ratio: number }>();

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
    onTransaction: emitEditorState,
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
      return patchBlock(props.documentId, blockId, {
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
```

- [ ] **Step 3: Update the template's text-block branch**

Find this block in the template (lines 38–52):

```html
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
```

Replace with:

```html
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
```

- [ ] **Step 4: Remove `is-editing` from the article `:class` binding**

Find (line ~15–19):

```html
        :class="{
          'is-selected': item.block.block_id === selectedBlockId,
          'is-editing': item.block.block_id === editingBlockId,
          'needs-review': item.block.needs_review,
        }"
```

Replace with:

```html
        :class="{
          'is-selected': item.block.block_id === selectedBlockId,
          'needs-review': item.block.needs_review,
        }"
```

- [ ] **Step 5: Run typecheck and verify no project-source errors**

```powershell
cd apps/app-laravel; $out = npm run typecheck 2>&1; $out | Where-Object { $_ -match "error TS" -and $_ -notmatch "node_modules" }
```

Expected output: *(empty — no project source errors)*

If you see errors in `resources/`, fix them before committing. Common issues:
- `Editor` import: use `import { Editor, type Extensions } from '@tiptap/core'` not from `@tiptap/vue-3`
- `shallowRef`: import from `vue`
- `editors[blockId]` in template: Vue auto-unwraps `shallowRef` so this is correct (no `.value` needed in template)

- [ ] **Step 6: Commit**

```powershell
cd D:\workspace\outside\docling-thai-poc
git add apps/app-laravel/resources/js/components/ComposeSectionEditor.vue
git commit -m "refactor(editor): replace single TipTap instance with per-block editor map

Whole-document edit mode: all text blocks get individual Editor
instances when editMode is on. saveAllBlocks uses Promise.allSettled
so partial failures keep dirty blocks for retry.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 2: Update ComposeToolbar for whole-document save/cancel

**Files:**
- Modify: `apps/app-laravel/resources/js/components/ComposeToolbar.vue`

Context: The toolbar currently shows "แก้ไข" when `!editorState.active` and "บันทึก/ยกเลิก" when active. In the new model these buttons are driven by `editMode` (a new prop), not `editorState.active`. Action types `saveActiveBlock`/`cancelActiveBlock` become `saveAll`/`cancelAll`.

- [ ] **Step 1: Update `ToolbarAction` type (line 197)**

Find:
```typescript
type ToolbarAction = 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'export' | 'saveActiveBlock' | 'cancelActiveBlock' | 'indent' | 'outdent' | 'setAlignment';
```

Replace with:
```typescript
type ToolbarAction = 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'export' | 'saveAll' | 'cancelAll' | 'indent' | 'outdent' | 'setAlignment';
```

- [ ] **Step 2: Add `editMode` prop (around line 199–210)**

Find the `defineProps` block:
```typescript
const props = defineProps<{
  title: string;
  subtitle: string;
  font: ThaiFont;
  fontSize: number;
  editorState: EditorStateSnapshot;
  autoSaveState: 'idle' | 'saving' | 'saved' | 'error';
  autoSaveLabel: string;
  alternateRouteLabel: string;
  alternateRoute: string;
  correctionInProgress?: boolean;
}>();
```

Replace with:
```typescript
const props = defineProps<{
  title: string;
  subtitle: string;
  font: ThaiFont;
  fontSize: number;
  editorState: EditorStateSnapshot;
  editMode: boolean;
  autoSaveState: 'idle' | 'saving' | 'saved' | 'error';
  autoSaveLabel: string;
  alternateRouteLabel: string;
  alternateRoute: string;
  correctionInProgress?: boolean;
}>();
```

- [ ] **Step 3: Update save/cancel template section**

Find this block in the template (lines ~144–177):

```html
      <v-btn
        v-if="!props.editorState.active"
        size="small"
        variant="tonal"
        color="primary"
        prepend-icon="mdi-pencil-outline"
        title="แก้ไขบล็อกที่เลือก"
        @click="emit('toggle:editMode')"
      >
        แก้ไข
      </v-btn>

      <template v-else>
        <v-btn
          size="small"
          variant="tonal"
          color="success"
          prepend-icon="mdi-content-save-outline"
          title="บันทึกการแก้ไข"
          @click="emit('action', 'saveActiveBlock')"
        >
          บันทึก
        </v-btn>
        <v-btn
          size="small"
          variant="outlined"
          prepend-icon="mdi-close"
          title="ยกเลิกการแก้ไข"
          @click="emit('action', 'cancelActiveBlock')"
        >
          ยกเลิก
        </v-btn>
      </template>
```

Replace with:

```html
      <v-btn
        v-if="!props.editMode"
        size="small"
        variant="tonal"
        color="primary"
        prepend-icon="mdi-pencil-outline"
        title="เข้าสู่โหมดแก้ไข"
        @click="emit('toggle:editMode')"
      >
        แก้ไข
      </v-btn>

      <template v-else>
        <v-btn
          size="small"
          variant="tonal"
          color="success"
          prepend-icon="mdi-content-save-outline"
          title="บันทึกทุก block ที่แก้ไข"
          @click="emit('action', 'saveAll')"
        >
          บันทึกทั้งหมด
        </v-btn>
        <v-btn
          size="small"
          variant="outlined"
          prepend-icon="mdi-exit-to-app"
          title="ออกจากโหมดแก้ไข"
          @click="emit('action', 'cancelAll')"
        >
          ออก
        </v-btn>
      </template>
```

- [ ] **Step 4: Update `isDisabled` to not reference `saveActiveBlock`/`cancelActiveBlock`**

Check the `isDisabled` function (line ~257–261). It currently references `'export'`, `'undo'`, `'redo'`. The `saveActiveBlock`/`cancelActiveBlock` cases were not there — no change needed. Verify it compiles.

- [ ] **Step 5: Run typecheck**

```powershell
cd apps/app-laravel; $out = npm run typecheck 2>&1; $out | Where-Object { $_ -match "error TS" -and $_ -notmatch "node_modules" }
```

Expected: *(empty)*

- [ ] **Step 6: Commit**

```powershell
cd D:\workspace\outside\docling-thai-poc
git add apps/app-laravel/resources/js/components/ComposeToolbar.vue
git commit -m "feat(toolbar): switch to whole-document save/cancel buttons

editMode prop drives button visibility instead of editorState.active.
saveAll/cancelAll replace per-block saveActiveBlock/cancelActiveBlock.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 3: Update DocumentComposeWorkspace to wire the new event model

**Files:**
- Modify: `apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue`

Context: Three handler changes + two template binding changes + ToolbarCommand type update. No new logic — just wiring.

- [ ] **Step 1: Update `ToolbarCommand` type (line 109–113)**

Find:
```typescript
interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'startEdit' | 'save' | 'cancel' | 'indent' | 'outdent' | 'setAlignment';
  value?: string;
}
```

Replace with:
```typescript
interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'saveAll' | 'cancelAll' | 'indent' | 'outdent' | 'setAlignment';
  value?: string;
}
```

- [ ] **Step 2: Add `:edit-mode` to `ComposeToolbar` in the template (line 58–76)**

Find the `<ComposeToolbar` block. Add `:edit-mode="editMode"` after `:editor-state="editorState"`:

```html
        <ComposeToolbar
          :title="pageTitle"
          :subtitle="pageSubtitle"
          :font="font"
          :font-size="fontSize"
          :editor-state="editorState"
          :edit-mode="editMode"
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

- [ ] **Step 3: Update `ComposeSectionEditor` event bindings in the template (line 78–93)**

Find `@block-saved="onBlockSaved"` and replace with `@all-blocks-saved="onAllBlocksSaved"`:

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
          @all-blocks-saved="onAllBlocksSaved"
          @edit-cancelled="handleEditCancelled"
          @editor-state="editorState = $event"
        />
```

- [ ] **Step 4: Replace `handleToggleEditMode` (line 338–342)**

Find:
```typescript
function handleToggleEditMode(): void {
  editMode.value = true;
  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type: 'startEdit' };
}
```

Replace with:
```typescript
function handleToggleEditMode(): void {
  editMode.value = true;
}
```

- [ ] **Step 5: Replace `onBlockSaved` with `onAllBlocksSaved` (line 348–351)**

Find:
```typescript
function onBlockSaved(blockId: string): void {
  editMode.value = false;
  void reloadReview();
}
```

Replace with:
```typescript
function onAllBlocksSaved(): void {
  // Stay in edit mode after saving — user continues editing
}
```

- [ ] **Step 6: Update `handleEditCancelled` to reload (line 344–346)**

Find:
```typescript
function handleEditCancelled(): void {
  editMode.value = false;
}
```

Replace with:
```typescript
function handleEditCancelled(): void {
  editMode.value = false;
  void reloadReview();
}
```

- [ ] **Step 7: Update `dispatchToolbarAction` — replace `saveActiveBlock`/`cancelActiveBlock` with `saveAll`/`cancelAll` (line 316–336)**

Find:
```typescript
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
```

Replace with:
```typescript
  if (type === 'saveAll') {
    toolbarCommandId += 1;
    toolbarCommand.value = { id: toolbarCommandId, type: 'saveAll' };
    return;
  }

  if (type === 'cancelAll') {
    toolbarCommandId += 1;
    toolbarCommand.value = { id: toolbarCommandId, type: 'cancelAll' };
    return;
  }
```

- [ ] **Step 8: Run typecheck**

```powershell
cd apps/app-laravel; $out = npm run typecheck 2>&1; $out | Where-Object { $_ -match "error TS" -and $_ -notmatch "node_modules" }
```

Expected: *(empty)*

- [ ] **Step 9: Commit**

```powershell
cd D:\workspace\outside\docling-thai-poc
git add apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue
git commit -m "feat(workspace): wire whole-document edit/save event model

handleToggleEditMode no longer dispatches startEdit command.
onAllBlocksSaved keeps editMode alive (no reload on save).
handleEditCancelled reloads from server to restore canonical state.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Self-Review

**1. Spec coverage:**

| Requirement | Task |
|---|---|
| Enter edit mode → all text blocks editable | Task 1 (watch editMode creates all editors) |
| Click any block to type, no double-click | Task 1 (EditorContent always shown in editMode) |
| Toolbar formatting applies to focused block | Task 1 (applyToolbarCommand uses focusedBlockId) |
| "บันทึกทั้งหมด" saves dirty blocks, stays in editMode | Task 1 (saveAllBlocks + allBlocksSaved no-op) + Task 2 (button) + Task 3 (handler) |
| "ออก" destroys editors, reloads from server | Task 1 (cancelEdit) + Task 2 (button) + Task 3 (handleEditCancelled reloads) |
| Promise.allSettled — partial failure keeps dirty | Task 1 (saveAllBlocks logic) |
| No dirty indicator | *(intentionally omitted — correct)* |

All requirements covered.

**2. Placeholder scan:** No TBD, no TODO, no vague steps. All code blocks are complete.

**3. Type consistency:**
- `ToolbarCommand.type` union updated in ComposeSectionEditor (Task 1), ComposeToolbar uses `ToolbarAction` (different type — correct), DocumentComposeWorkspace (Task 3). All have `saveAll`/`cancelAll`, none have `startEdit`/`save`/`cancel`.
- `emit('allBlocksSaved')` in Task 1 matches `@all-blocks-saved="onAllBlocksSaved"` in Task 3 template (Vue converts camelCase emit to kebab-case event name automatically).
- `editors` is `shallowRef<Record<string, Editor>>` — in template, Vue auto-unwraps to `Record<string, Editor>`. Template access `editors[blockId]` is correct.
- `editMode` prop added to ComposeToolbar in Task 2 matches `:edit-mode="editMode"` binding added in Task 3.
