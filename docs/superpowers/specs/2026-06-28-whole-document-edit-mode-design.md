# Whole-Document Edit Mode Design

**Goal:** Replace per-block edit/save with a Word-like experience — enter edit mode once, click anywhere to type, press "บันทึกทั้งหมด" once when done.

**Agreed:** 2026-06-28

---

## Scope

Replace the current block-by-block editing flow in `ComposePage` with a document-level edit mode. Image and table blocks remain non-editable (unchanged). No persistent width/layout changes — only text content is saved.

**Out of scope:** real-time collaboration, conflict resolution, undo across blocks, autosave.

---

## User Flow (new)

```
กด "แก้ไข"
  → editors ทุก text block ถูก mount พร้อมกัน
  → คลิกที่ block ไหนก็พิมพ์ได้เลย (no double-click needed)
  → ย้าย cursor ข้าม block ได้อิสระ
  → toolbar formatting (Bold/Italic/Align/Indent) ทำงานกับ block ที่ focus อยู่

กด "บันทึกทั้งหมด"
  → PATCH เฉพาะ block ที่มีการเปลี่ยนแปลง (dirty) พร้อมกัน
  → ยังอยู่ใน edit mode — แก้ต่อได้เลย
  → ไม่ reload หน้า

กด "ออก" (หรือ navigate away)
  → destroy editors ทั้งหมด
  → reload document จาก server (canonical state)
  → กลับ read-only
```

**ไม่มี dirty indicator** — ผู้ใช้กด Save ครั้งเดียวโดยไม่ต้องรู้ว่า block ไหนค้าง เหมือน Word

---

## Architecture

### Approach: Per-block TipTap instances

เมื่อ `editMode = true` → สร้าง `Editor` instance หนึ่งตัวต่อหนึ่ง text block  
เก็บใน `editors: Map<blockId, Editor>`

ข้อดี: dirty tracking ง่าย, แต่ละ block ยังแมปกับ API เดิม, ไม่ต้องรื้อ schema

### Save strategy

- **Save** (`saveAllBlocks`): `Promise.all` PATCH dirty blocks → clear dirty set → **stay in edit mode, no reload**
- **Exit** (`cancelEdit`): destroy all editors → reload from server → `editMode = false`
- Save without any changes → no-op (nothing to PATCH)

---

## File Changes

### `ComposeSectionEditor.vue` — major refactor

**State changes:**
```typescript
// REMOVE
const editor = useEditor(...)          // single instance
const editingBlockId = ref(null)       // per-block edit cursor

// ADD
const editors = new Map<string, Editor>()   // one per text block
const dirtyBlockIds = new Set<string>()     // blocks changed since last save
const focusedBlockId = ref<string | null>(null)  // which block has cursor
```

**Watch `editMode`:**
```typescript
watch(() => props.editMode, (next) => {
  if (next) {
    nextTick(() => {
      props.blocks.forEach(item => {
        if (!isEditable(item.block)) return;
        editors.set(item.block.block_id, createEditor(item));
      });
    });
  } else {
    editors.forEach(e => e.destroy());
    editors.clear();
    dirtyBlockIds.clear();
    focusedBlockId.value = null;
  }
});
```

**`createEditor(item: BlockItem): Editor`:**
```typescript
return new Editor({
  extensions: [StarterKit, Underline, TextAlign.configure(...), IndentExtension],
  content: readOnlyHtml(item.block),
  editable: true,
  onFocus: () => { focusedBlockId.value = item.block.block_id; emitEditorState(); },
  onUpdate: () => { dirtyBlockIds.add(item.block.block_id); emitEditorState(); },
  onSelectionUpdate: emitEditorState,
  onTransaction: emitEditorState,
});
```

**`emitEditorState`:** ใช้ `editors.get(focusedBlockId.value)` แทน `editor.value`

**`applyToolbarCommand`:** ทุก formatting command apply กับ `editors.get(focusedBlockId.value)` แทน `editor.value`. Remove `startEdit` handler.

**ToolbarCommand type:** remove `'startEdit' | 'save' | 'cancel'`; add `'saveAll' | 'cancelAll'`

**Template — text block branch:**
```html
<div v-else class="doc-block__body">
  <EditorContent
    v-if="editMode && isEditable(item.block)"
    :editor="editors.get(item.block.block_id)!"
    class="doc-block__editor"
  />
  <div
    v-else
    class="doc-block__html"
    v-html="renderReadOnlyHtml(item.block)"
  />
</div>
```
ลบ `@click.stop` ที่เรียก `startEdit` ออก — ไม่ต้องใช้แล้ว

**`saveAllBlocks`:**
```typescript
async function saveAllBlocks(): Promise<void> {
  if (busy.value || dirtyBlockIds.size === 0) return;
  busy.value = true;
  try {
    await Promise.all([...dirtyBlockIds].map(blockId => {
      const item = props.blocks.find(b => b.block.block_id === blockId);
      const ed = editors.get(blockId);
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
    }));
    dirtyBlockIds.clear();
    emit('allBlocksSaved');
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'บันทึกไม่สำเร็จ';
  } finally {
    busy.value = false;
  }
}
```

**Emits changes:**
- Remove `blockSaved: [string]`
- Add `allBlocksSaved: []`
- Keep `editCancelled: []`

---

### `DocumentComposeWorkspace.vue` — simplify save/edit lifecycle

**Remove:** `onBlockSaved` (exits editMode + reloads per block)

**Add:**
```typescript
function onAllBlocksSaved(): void {
  // No editMode change, no reload — stay editing
}

function onEditCancelled(): void {
  editMode.value = false;
  void reloadReview();   // reload only on exit
}
```

**`dispatchToolbarAction`:** handle `saveAll` → dispatch `saveAll` command; handle `cancelAll` → dispatch `cancelAll` command. Remove `startEdit` case.

---

### `ComposeToolbar.vue` — toolbar buttons

**Add prop:** `editMode: boolean`

**Change save/cancel section:** replace `v-if="!editorState.active"` with `v-if="!editMode"`

```html
<v-btn v-if="!editMode" @click="emit('toggle:editMode')">แก้ไข</v-btn>

<template v-else>
  <v-btn color="success" @click="emit('action', 'saveAll')">บันทึกทั้งหมด</v-btn>
  <v-btn @click="emit('action', 'cancelAll')">ออก</v-btn>
</template>
```

**ToolbarAction type:** add `'saveAll' | 'cancelAll'`, remove `'saveActiveBlock' | 'cancelActiveBlock'`

---

## Error Handling

ใช้ `Promise.allSettled` (ไม่ใช่ `Promise.all`) เพื่อให้ block ที่ save สำเร็จถูก clear ออกจาก dirty set แม้ block อื่น fail:

```typescript
const results = await Promise.allSettled([...dirtyBlockIds].map(blockId => ...));
results.forEach((result, i) => {
  if (result.status === 'fulfilled') dirtyBlockIds.delete([...dirtyBlockIds][i]);
});
const failed = results.filter(r => r.status === 'rejected');
if (failed.length) errorMessage.value = `บันทึกไม่สำเร็จ ${failed.length} block`;
```

ผู้ใช้กด "บันทึกทั้งหมด" อีกครั้งเพื่อ retry เฉพาะ block ที่ยังค้าง

---

## Memory Note

N TipTap instances ใน memory พร้อมกัน — สำหรับเอกสาร 200 block แต่ละ instance ~50 KB ≈ 10 MB ซึ่งเบาพอสำหรับ desktop browser
