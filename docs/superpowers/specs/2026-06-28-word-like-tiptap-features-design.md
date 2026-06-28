# Word-like TipTap Features Design

**Goal:** Add Microsoft Word-style editing features to the TipTap editor in ComposePage — text alignment, indent/outdent, drag-to-resize images, seamless editing (no blue border), clean table style, and remove the yellow needs-review indicator.

**Agreed:** 2026-06-28 via brainstorming session with visual companion.

---

## Scope

Six features implemented across four change groups:

| Group | Feature | Files |
|---|---|---|
| Toolbar | Text alignment toggle (L/C/R/J) + Indent/Outdent buttons | `ComposeToolbar.vue`, `DocumentComposeWorkspace.vue` |
| Editor | TextAlign TipTap extension + indent/outdent commands | `ComposeSectionEditor.vue` |
| Image | Resizable image with drag handle (custom NodeView) | `ResizableImageNode.ts` (new), `ComposeSectionEditor.vue` |
| Style | Remove outline, remove yellow indicator, table black-borders-only | `app.css` |

**One new npm package:** `@tiptap/extension-text-align`

---

## Architecture

### Command Bus Extension

The existing command bus (`ToolbarCommand`) passes only a `type` string. Alignment needs a value. Extend the interface in both `DocumentComposeWorkspace.vue` and `ComposeSectionEditor.vue`:

```typescript
interface ToolbarCommand {
  id: number;
  type: 'undo' | 'redo' | 'bold' | 'italic' | 'underline'
      | 'bulletList' | 'orderedList'
      | 'startEdit' | 'save' | 'cancel'
      | 'indent' | 'outdent'
      | 'setAlignment';
  value?: string; // used by setAlignment: 'left' | 'center' | 'right' | 'justify'
}
```

`EditorStateSnapshot` gains one field so the toolbar's `v-btn-toggle` reflects the cursor's current alignment:

```typescript
interface EditorStateSnapshot {
  active: boolean;
  canUndo: boolean;
  canRedo: boolean;
  isBold: boolean;
  isItalic: boolean;
  isUnderline: boolean;
  isBulletList: boolean;
  isOrderedList: boolean;
  alignment: 'left' | 'center' | 'right' | 'justify'; // NEW
}
```

### Toolbar Changes (`ComposeToolbar.vue`)

New `ToolbarAction` values: `'indent'`, `'outdent'`, `'setAlignment'` (with value).

The existing `defineEmits` adds `'update:alignment': [string]` so the workspace can forward alignment to the editor.

New template additions inside `.editor-shell-actions` (after the lists group):

```html
<!-- Alignment toggle — v-btn-toggle exclusive -->
<v-btn-toggle
  :model-value="props.editorState.alignment"
  density="compact"
  variant="text"
  @update:model-value="$emit('action', 'setAlignment', $event)"
>
  <v-btn value="left"    icon="mdi-format-align-left"    size="small" :disabled="!props.editorState.active" />
  <v-btn value="center"  icon="mdi-format-align-center"  size="small" :disabled="!props.editorState.active" />
  <v-btn value="right"   icon="mdi-format-align-right"   size="small" :disabled="!props.editorState.active" />
  <v-btn value="justify" icon="mdi-format-align-justify" size="small" :disabled="!props.editorState.active" />
</v-btn-toggle>

<!-- Indent / Outdent -->
<v-btn icon="mdi-format-indent-decrease" size="small" :disabled="!props.editorState.active" @click="$emit('action', 'outdent')" />
<v-btn icon="mdi-format-indent-increase" size="small" :disabled="!props.editorState.active" @click="$emit('action', 'indent')" />
```

The `dispatchToolbarAction` signature changes to accept an optional second argument:

```typescript
// In ComposeToolbar.vue defineEmits:
'action': [type: string, value?: string]
```

### Workspace Changes (`DocumentComposeWorkspace.vue`)

`dispatchToolbarAction` handles the two new types:

```typescript
if (type === 'indent') {
  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type: 'indent' };
  return;
}
if (type === 'outdent') {
  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type: 'outdent' };
  return;
}
if (type === 'setAlignment') {
  toolbarCommandId += 1;
  toolbarCommand.value = { id: toolbarCommandId, type: 'setAlignment', value };
  return;
}
```

### Editor Changes (`ComposeSectionEditor.vue`)

**New TipTap extensions:**

```typescript
import TextAlign from '@tiptap/extension-text-align';

// In useEditor extensions array:
TextAlign.configure({ types: ['heading', 'paragraph'] }),
```

**Replace `@tiptap/extension-image`** with the custom `ResizableImageNode` (see below).

**`applyToolbarCommand` additions:**

```typescript
case 'indent':
  editor.value.chain().focus().sinkListItem('listItem').run();
  // fallback for non-list context: increase paragraph indent via custom attribute
  break;
case 'outdent':
  editor.value.chain().focus().liftListItem('listItem').run();
  break;
case 'setAlignment':
  if (cmd.value) {
    editor.value.chain().focus().setTextAlign(cmd.value).run();
  }
  break;
```

**`emitEditorState` additions:**

```typescript
const alignment = (
  editor.value.isActive({ textAlign: 'center' }) ? 'center' :
  editor.value.isActive({ textAlign: 'right' })  ? 'right'  :
  editor.value.isActive({ textAlign: 'justify' }) ? 'justify' :
  'left'
) as EditorStateSnapshot['alignment'];

emit('editor-state', { ...existing, alignment });
```

### ResizableImageNode (`ResizableImageNode.ts`)

Custom TipTap Node replacing `@tiptap/extension-image`. Implements a Vue NodeView with a drag handle at the bottom-right corner.

**Node attributes:** `src` (string, required), `alt` (string, default `''`), `width` (number | null, default `null`).

**NodeView component** (`ResizableImageNodeView.vue`):

- Renders `<img :src="src" :style="{ width: width ? width+'px' : 'auto' }" />`
- Absolutely-positioned `<div class="resize-handle">` at bottom-right (8×8px, `cursor: se-resize`)
- `mousedown` on handle → captures `startX` and `startWidth`; registers `mousemove` / `mouseup` on `document`
- `mousemove`: `newWidth = Math.max(40, startWidth + (e.clientX - startX))` → updates local reactive `currentWidth`
- `mouseup`: calls `updateAttributes({ width: currentWidth })` to persist to TipTap
- No blue selection outline; node is not `selectable` in the node spec (set `selectable: false`)

**Width is stored as a node attribute**, not inline style in HTML — serialised as `width="240"` on the `<img>` tag in ProseMirror's HTML output.

### CSS Changes (`app.css`)

Four targeted changes — no other rules touched:

```css
/* 1. Remove blue outline from active editing block */
.doc-block.is-editing {
  /* Remove: outline: 2px solid #1976d2; outline-offset: 4px; border-radius: 2px; */
  position: relative; /* keep */
}

/* 2. Remove ProseMirror focus ring */
.ProseMirror:focus {
  outline: none;
}

/* 3. Remove yellow needs-review left border */
.doc-block.needs-review {
  /* Remove: border-left: 3px solid #d9a23e; padding-left: 10px; */
}

/* 4. Table: black borders only, no background */
.ProseMirror table {
  border-collapse: collapse;
  width: 100%;
}
.ProseMirror th,
.ProseMirror td {
  border: 1px solid #000;
  padding: 6px 8px;
  background: none !important;
}
.ProseMirror th {
  font-weight: bold;
}
```

---

## File Map

| Action | Path |
|---|---|
| Modify | `apps/app-laravel/resources/js/components/ComposeToolbar.vue` |
| Modify | `apps/app-laravel/resources/js/components/DocumentComposeWorkspace.vue` |
| Modify | `apps/app-laravel/resources/js/components/ComposeSectionEditor.vue` |
| Create | `apps/app-laravel/resources/js/components/ResizableImageNodeView.vue` |
| Create | `apps/app-laravel/resources/js/extensions/ResizableImageNode.ts` |
| Modify | `apps/app-laravel/resources/css/app.css` |

---

## Out of Scope

- Table cell creation/deletion UI (no toolbar for add row/col)
- Font color, highlight, strikethrough
- Header/footer in the document
- Export of alignment/indent to RAG JSON (existing export flow unchanged)
