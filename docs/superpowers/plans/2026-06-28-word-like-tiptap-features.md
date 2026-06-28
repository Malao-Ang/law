# Word-like TipTap Features Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add text alignment, indent/outdent, drag-to-resize images, seamless editing (no border), clean table style, and remove the yellow needs-review indicator from the ComposePage editor.

**Architecture:** Four independent change groups executed in order: (1) CSS-only cleanup, (2) npm install, (3) alignment + indent wired through all three layers (editor → workspace → toolbar), (4) Vue-level resizable image component. Images in `ComposeSectionEditor` are rendered outside TipTap (the `v-if type==='image'` branch), so image resize is a Vue component, not a TipTap NodeView.

**Tech Stack:** Vue 3 · Vuetify 4 · TipTap 2 (`@tiptap/extension-text-align` new) · TypeScript · CSS

---

## File Structure

| Action | Path | Responsibility |
|---|---|---|
| Modify | `apps/app-laravel/resources/css/app.css` | Remove outline/yellow, add table border rules, add resize handle CSS |
| Modify | `apps/app-laravel/resources/js/components/ComposeSectionEditor.vue` | TextAlign extension, new command types, alignment in editorState, use ResizableImage |
| Modify | `apps/app-laravel/resources/js/components/ComposeToolbar.vue` | Alignment v-btn-toggle, indent/outdent buttons, updated interfaces |
| Modify | `apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue` | Extended ToolbarCommand/EditorStateSnapshot, dispatch new actions |
| Create | `apps/app-laravel/resources/js/components/ResizableImage.vue` | Vue component: static img + drag handle, local width state |

---

## Task 1: CSS Cleanup

**Files:**
- Modify: `apps/app-laravel/resources/css/app.css`

Context: `.doc-block.needs-review` is at line 1366, `.doc-block.is-editing` at line 1371, `.doc-block__editor .ProseMirror` at line 1399.

- [ ] **Step 1: Remove the blue outline from `.doc-block.is-editing` and clear the yellow from `.doc-block.needs-review`**

Find these two blocks (lines 1366–1375) and replace them:

```css
/* BEFORE */
.doc-block.needs-review {
  border-left: 3px solid #d9a23e;
  padding-left: 10px;
}

.doc-block.is-editing {
  outline: 2px solid #1976d2;
  outline-offset: 4px;
  border-radius: 2px;
}

/* AFTER */
.doc-block.needs-review {
  /* yellow left-border removed — clean document view */
}

.doc-block.is-editing {
  /* no outline — Word-like seamless editing */
}
```

- [ ] **Step 2: Add table border rules for the TipTap ProseMirror editor**

After the `.doc-block__editor .ProseMirror` rule (currently ending at line 1402), add:

```css
.doc-block__editor .ProseMirror table {
  border-collapse: collapse;
  width: 100%;
}

.doc-block__editor .ProseMirror th,
.doc-block__editor .ProseMirror td {
  border: 1px solid #000;
  padding: 6px 8px;
  background: none !important;
}

.doc-block__editor .ProseMirror th {
  font-weight: bold;
}
```

- [ ] **Step 3: Add resize-handle CSS (used by Task 5's ResizableImage.vue)**

After the `.doc-block__image img` rule (currently at line 1404), add:

```css
.resizable-image-container {
  position: relative;
  display: inline-block;
  max-width: 100%;
}

.resizable-image-container img {
  display: block;
  width: 100%;
  border: 1px solid #e1e8f5;
}

.resize-handle {
  position: absolute;
  bottom: -4px;
  right: -4px;
  width: 12px;
  height: 12px;
  background: #1976d2;
  border: 2px solid #fff;
  border-radius: 2px;
  cursor: se-resize;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}

.resize-tooltip {
  position: absolute;
  bottom: 18px;
  right: 0;
  background: rgba(0, 0, 0, 0.75);
  color: #fff;
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 3px;
  white-space: nowrap;
  pointer-events: none;
}
```

- [ ] **Step 4: Verify in browser**

Open `http://localhost:8000/documents/{any-id}/compose` and click แก้ไข — the active block should have no blue border around it. Any block with `needs_review=true` should show no yellow left bar.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/css/app.css
git commit -m "style(compose): remove edit outline and yellow indicator, add Word-like table borders

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 2: Install @tiptap/extension-text-align

**Files:**
- Modify: `apps/app-laravel/package.json` (auto-updated by npm)
- Modify: `apps/app-laravel/package-lock.json` (auto-updated by npm)

- [ ] **Step 1: Install the package**

```bash
cd apps/app-laravel && npm install @tiptap/extension-text-align
```

Expected: `added 1 package` (or similar — the package version should match the existing `^2.11.5` range).

- [ ] **Step 2: Verify**

```bash
ls node_modules/@tiptap/extension-text-align
```

Expected: directory listing (index.js, etc.).

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/package.json apps/app-laravel/package-lock.json
git commit -m "chore: install @tiptap/extension-text-align

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 3: Text Alignment + Indent/Outdent — Editor Layer

**Files:**
- Modify: `apps/app-laravel/resources/js/components/ComposeSectionEditor.vue`

This task wires alignment and indent/outdent into the TipTap editor. It extends the shared interfaces (`ToolbarCommand`, `EditorStateSnapshot`) and handles the new commands. The toolbar and workspace are updated in Task 4.

- [ ] **Step 1: Add TextAlign import after the existing TipTap imports (line 69)**

```typescript
import TextAlign from '@tiptap/extension-text-align';
```

The import block should now read:

```typescript
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import { patchBlock } from '../api/client';
import type { DocumentBlock, ThaiFont } from '../types/document';
```

- [ ] **Step 2: Replace the `ToolbarCommand` interface (lines 73–76)**

```typescript
// BEFORE
interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'startEdit' | 'save' | 'cancel';
}

// AFTER
interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'startEdit' | 'save' | 'cancel' | 'indent' | 'outdent' | 'setAlignment';
  value?: string;
}
```

- [ ] **Step 3: Replace the `EditorStateSnapshot` interface (lines 88–97)**

```typescript
// BEFORE
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

// AFTER
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
```

- [ ] **Step 4: Add `TextAlign` to the `useEditor` extensions array (line 126)**

```typescript
// BEFORE
const editor = useEditor({
  extensions: [StarterKit, Underline],

// AFTER
const editor = useEditor({
  extensions: [
    StarterKit,
    Underline,
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
  ],
```

- [ ] **Step 5: Replace `emitEditorState` (lines 235–247) to include `alignment`**

```typescript
function emitEditorState(): void {
  const active = Boolean(editingBlockId.value && editor.value);
  const alignment: EditorStateSnapshot['alignment'] = active ? (
    editor.value?.isActive({ textAlign: 'center' }) ? 'center' :
    editor.value?.isActive({ textAlign: 'right' })  ? 'right'  :
    editor.value?.isActive({ textAlign: 'justify' }) ? 'justify' : 'left'
  ) : 'left';
  emit('editorState', {
    active,
    canUndo: active ? editor.value?.can().undo() ?? false : false,
    canRedo: active ? editor.value?.can().redo() ?? false : false,
    isBold: active ? editor.value?.isActive('bold') ?? false : false,
    isItalic: active ? editor.value?.isActive('italic') ?? false : false,
    isUnderline: active ? editor.value?.isActive('underline') ?? false : false,
    isBulletList: active ? editor.value?.isActive('bulletList') ?? false : false,
    isOrderedList: active ? editor.value?.isActive('orderedList') ?? false : false,
    alignment,
  });
}
```

- [ ] **Step 6: Replace `applyToolbarCommand` (lines 307–341) to handle new command types**

```typescript
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
  else if (command.type === 'indent') chain.sinkListItem('listItem').run();
  else if (command.type === 'outdent') chain.liftListItem('listItem').run();
  else if (command.type === 'setAlignment' && command.value) {
    chain.setTextAlign(command.value).run();
  }

  emitEditorState();
}
```

- [ ] **Step 7: Run typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors. If TypeScript complains about `setTextAlign` not existing on the chain, verify that the `@tiptap/extension-text-align` package was installed in Task 2.

- [ ] **Step 8: Commit**

```bash
git add apps/app-laravel/resources/js/components/ComposeSectionEditor.vue
git commit -m "feat(editor): add TextAlign extension and indent/outdent command handlers

Extends ToolbarCommand with indent/outdent/setAlignment, extends
EditorStateSnapshot with alignment field, and handles all new
command types in applyToolbarCommand.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 4: Text Alignment + Indent/Outdent — Toolbar and Workspace

**Files:**
- Modify: `apps/app-laravel/resources/js/components/ComposeToolbar.vue`
- Modify: `apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue`

- [ ] **Step 1: Update `EditorStateSnapshot` in `ComposeToolbar.vue` (lines 132–141)**

```typescript
// BEFORE
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

// AFTER
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
```

- [ ] **Step 2: Extend `ToolbarAction` type in `ComposeToolbar.vue` (line 143)**

```typescript
// BEFORE
type ToolbarAction = 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'export' | 'saveActiveBlock' | 'cancelActiveBlock';

// AFTER
type ToolbarAction = 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'export' | 'saveActiveBlock' | 'cancelActiveBlock' | 'indent' | 'outdent' | 'setAlignment';
```

- [ ] **Step 3: Change `defineEmits` to `const emit = defineEmits` and add `value?` to the `action` event (lines 158–166)**

```typescript
// BEFORE
defineEmits<{
  reload: [];
  action: [ToolbarAction];
  'toggle:navigator': [];
  'toggle:details': [];
  'toggle:editMode': [];
  'update:font': [ThaiFont];
  'update:fontSize': [number];
}>();

// AFTER
const emit = defineEmits<{
  reload: [];
  action: [type: ToolbarAction, value?: string];
  'toggle:navigator': [];
  'toggle:details': [];
  'toggle:editMode': [];
  'update:font': [ThaiFont];
  'update:fontSize': [number];
}>();
```

Note: all existing `$emit('action', button.action)` calls in the template remain valid because `value` is optional.

- [ ] **Step 4: Add alignment toggle and indent/outdent buttons to the template**

In the template, find the `<v-divider vertical class="compose-toolbar-divider" />` that separates the command buttons from the Export button (currently line 53). Insert the new controls **before** that divider:

```html
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

      <!-- NEW: alignment toggle -->
      <v-btn-toggle
        :model-value="props.editorState.alignment"
        density="compact"
        variant="text"
        @update:model-value="emit('action', 'setAlignment', $event)"
      >
        <v-btn
          value="left"
          icon="mdi-format-align-left"
          size="small"
          title="ชิดซ้าย"
          :disabled="!props.editorState.active"
        />
        <v-btn
          value="center"
          icon="mdi-format-align-center"
          size="small"
          title="กึ่งกลาง"
          :disabled="!props.editorState.active"
        />
        <v-btn
          value="right"
          icon="mdi-format-align-right"
          size="small"
          title="ชิดขวา"
          :disabled="!props.editorState.active"
        />
        <v-btn
          value="justify"
          icon="mdi-format-align-justify"
          size="small"
          title="เต็มบรรทัด"
          :disabled="!props.editorState.active"
        />
      </v-btn-toggle>

      <!-- NEW: indent / outdent -->
      <v-btn
        icon="mdi-format-indent-decrease"
        size="small"
        title="ลดย่อหน้า"
        variant="text"
        :disabled="!props.editorState.active"
        @click="emit('action', 'outdent')"
      />
      <v-btn
        icon="mdi-format-indent-increase"
        size="small"
        title="เพิ่มย่อหน้า"
        variant="text"
        :disabled="!props.editorState.active"
        @click="emit('action', 'indent')"
      />

      <v-divider vertical class="compose-toolbar-divider" />
```

- [ ] **Step 5: Update `EditorStateSnapshot` in `DocumentComposeWorkspace.vue` (lines 114–123)**

```typescript
// BEFORE
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

// AFTER
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
```

- [ ] **Step 6: Update `ToolbarCommand` in `DocumentComposeWorkspace.vue` (lines 109–112)**

```typescript
// BEFORE
interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'startEdit' | 'save' | 'cancel';
}

// AFTER
interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'startEdit' | 'save' | 'cancel' | 'indent' | 'outdent' | 'setAlignment';
  value?: string;
}
```

- [ ] **Step 7: Add `alignment: 'left'` to the `editorState` ref initial value in `DocumentComposeWorkspace.vue` (lines 152–160)**

```typescript
const editorState = ref<EditorStateSnapshot>({
  active: false,
  canUndo: false,
  canRedo: false,
  isBold: false,
  isItalic: false,
  isUnderline: false,
  isBulletList: false,
  isOrderedList: false,
  alignment: 'left',
});
```

- [ ] **Step 8: Replace `dispatchToolbarAction` in `DocumentComposeWorkspace.vue` (line 313)**

```typescript
// BEFORE
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

// AFTER
function dispatchToolbarAction(type: string, value?: string): void {
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
  toolbarCommand.value = { id: toolbarCommandId, type: type as ToolbarCommand['type'], value };
}
```

- [ ] **Step 9: Run typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 10: Test alignment in browser**

1. Open `http://localhost:8000/documents/{id}/compose`
2. Click **แก้ไข**
3. Click inside a paragraph block
4. Click the Center align button (`mdi-format-align-center`) — the paragraph text centers
5. The `v-btn-toggle` highlights the Center button
6. Click Justify (`mdi-format-align-justify`) — text stretches to full width
7. Click **ยกเลิก** — block returns to read-only, alignment toggle resets to grey

- [ ] **Step 11: Commit**

```bash
git add apps/app-laravel/resources/js/components/ComposeToolbar.vue apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue
git commit -m "feat(toolbar): add alignment toggle and indent/outdent buttons

v-btn-toggle reflects current cursor alignment; indent/outdent buttons
dispatch sinkListItem/liftListItem via command bus.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 5: Resizable Image Component

**Files:**
- Create: `apps/app-laravel/resources/js/components/ResizableImage.vue`
- Modify: `apps/app-laravel/resources/js/components/ComposeSectionEditor.vue`

Context: Image blocks in `ComposeSectionEditor` are rendered in a dedicated `v-if="item.block.type === 'image'"` branch as a plain `<img>` — they are never loaded into TipTap. Resize is handled at the Vue component level with local `width` state (display-only; width is not persisted to the server).

- [ ] **Step 1: Create `ResizableImage.vue`**

Create `apps/app-laravel/resources/js/components/ResizableImage.vue`:

```vue
<template>
  <div
    class="resizable-image-container"
    :style="{ width: displayWidth ? displayWidth + 'px' : 'auto' }"
  >
    <img
      :src="src"
      :alt="alt"
      draggable="false"
    >
    <div
      v-if="editMode"
      class="resize-handle"
      @mousedown.prevent.stop="startResize"
    ></div>
    <div v-if="isResizing" class="resize-tooltip">{{ displayWidth }}px</div>
  </div>
</template>

<script setup lang="ts">
import { ref, onBeforeUnmount } from 'vue';

const props = defineProps<{
  src: string;
  alt?: string;
  editMode: boolean;
}>();

const displayWidth = ref<number | null>(null);
const isResizing = ref(false);
let startX = 0;
let startWidth = 0;

function startResize(event: MouseEvent): void {
  isResizing.value = true;
  startX = event.clientX;
  const container = (event.currentTarget as HTMLElement).parentElement;
  startWidth = container ? container.offsetWidth : (displayWidth.value ?? 400);
  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

function onMouseMove(event: MouseEvent): void {
  displayWidth.value = Math.max(40, Math.round(startWidth + (event.clientX - startX)));
}

function onMouseUp(): void {
  isResizing.value = false;
  document.removeEventListener('mousemove', onMouseMove);
  document.removeEventListener('mouseup', onMouseUp);
}

onBeforeUnmount(() => {
  document.removeEventListener('mousemove', onMouseMove);
  document.removeEventListener('mouseup', onMouseUp);
});
</script>
```

- [ ] **Step 2: Import `ResizableImage` in `ComposeSectionEditor.vue`**

After line 71 (`import { patchBlock } from '../api/client';`), add:

```typescript
import ResizableImage from './ResizableImage.vue';
```

- [ ] **Step 3: Replace the static image block in the template**

In `ComposeSectionEditor.vue`, find the image branch (lines 22–29):

```html
<div v-if="item.block.type === 'image'" class="doc-block__image">
  <img
    v-if="item.block.meta.image?.src_url"
    :src="item.block.meta.image.src_url"
    :alt="item.block.meta.image.caption ?? 'document image'"
  >
  <p v-else class="hint">ไม่พบรูปภาพสำหรับบล็อกนี้</p>
</div>
```

Replace with:

```html
<div v-if="item.block.type === 'image'" class="doc-block__image">
  <ResizableImage
    v-if="item.block.meta.image?.src_url"
    :src="item.block.meta.image.src_url"
    :alt="item.block.meta.image.caption ?? 'document image'"
    :edit-mode="editMode"
  />
  <p v-else class="hint">ไม่พบรูปภาพสำหรับบล็อกนี้</p>
</div>
```

- [ ] **Step 4: Run typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 5: Test in browser**

1. Upload a document that contains an image (or use one that already has an image block)
2. Open its compose page
3. In preview mode (not edit mode): the image renders normally with no resize handle
4. Click **แก้ไข** (enter edit mode): a small blue square appears at the bottom-right of the image
5. Drag the blue handle to the right — the image grows wider, tooltip shows "480px"
6. Release — image stays at new width
7. Scroll away and back — width is preserved within the session

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/resources/js/components/ResizableImage.vue apps/app-laravel/resources/js/components/ComposeSectionEditor.vue
git commit -m "feat(editor): add drag-to-resize image component in compose edit mode

Resize handle appears only in edit mode. Width is local display-only
state (not persisted to the server).

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Self-Review

**1. Spec coverage:**

| Requirement | Task |
|---|---|
| Text alignment L/C/R/J via v-btn-toggle + mdi icons | Tasks 3 + 4 |
| Indent / outdent buttons | Tasks 3 + 4 |
| Drag-to-resize image with corner handle | Task 5 |
| Seamless editing — no blue outline | Task 1 |
| Table: black borders only, no background | Task 1 |
| Remove yellow needs-review indicator | Task 1 |

All six requirements are covered.

**2. Placeholder scan:** No "TBD", no "TODO", no vague steps. Every code block is complete.

**3. Type consistency:**
- `ToolbarCommand.type` union includes `indent | outdent | setAlignment` in both `ComposeSectionEditor.vue` (Task 3 Step 2) and `DocumentComposeWorkspace.vue` (Task 4 Step 6) — identical.
- `EditorStateSnapshot.alignment` field added in `ComposeSectionEditor.vue` (Task 3 Step 3), `ComposeToolbar.vue` (Task 4 Step 1), and `DocumentComposeWorkspace.vue` (Task 4 Step 5) — identical type `'left' | 'center' | 'right' | 'justify'`.
- `dispatchToolbarAction(type: string, value?: string)` matches toolbar emit `action: [type: ToolbarAction, value?: string]` — Vue passes positional args correctly.
- `ResizableImage.vue` props `src`, `alt`, `editMode` match the template bindings in Task 5 Step 3.
