# Admin Upload Flow Redesign

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 3-screen admin import flow (Upload → Review/Edit → RAG Management) to match the Figma design at `UG1BurzHw8yIbqQNP8eGZc`, retiring the per-block compose flow.

**Architecture:** Extend `DocumentEditorShell` (already a whole-doc TipTap editor) with a richer toolbar; simplify `AdminUploadPage`; add a new RAG Management page that reuses existing block store operations. The `/compose` route stays on disk but is unlinked from the new flow.

**Tech Stack:** Vue 3, Pinia 2, TypeScript strict, Vuetify 3, TipTap 2, `@tiptap/*` extensions.

**Figma reference:** `https://www.figma.com/design/UG1BurzHw8yIbqQNP8eGZc/Untitled`
- Frame `1:9537` — LawSpace Admin - นำเข้าข้อมูล: อัปโหลดไฟล์
- Frame `1:9784` — LawSpace Admin - นำเข้าข้อมูล Step 1: ตรวจสอบเนื้อหา แก้ไขเนื้อหา
- Frame `1:13921` — LawSpace Admin - นำเข้าข้อมูล : หน้าจัดการกฎหมาย เอกสาร รอลงนาม

---

## Flow

```
/admin/upload
  → upload file (AdminUploadPage)
  → poll until done
  → /documents/:id/review
      → whole-doc TipTap editor (DocumentEditorShell)
      → "บันทึกข้อมูล" saves draft_html → /documents/:id/rag
          → read-only block list (RagManageWorkspace)
          → merge or remove blocks
          → "บันทึกและเผยแพร่" → export → /documents/:id/preview
```

---

## File Map

### Modified files

| File | Change |
|------|--------|
| `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue` | Remove right sidebar, simplify status card, redirect to `/review` on done |
| `apps/app-laravel/resources/js/components/shared/UploadForm.vue` | Remove `v-select` controls for engine and scan mode; keep as hidden defaults (`standard` / `auto`) |
| `apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue` | 3-row toolbar, yellow warning banner, footer bar, no preview toggle, nav to `/rag` on save |
| `apps/app-laravel/resources/js/router/index.ts` | Add `/documents/:documentId/rag` route |

### New files

| File | Purpose |
|------|---------|
| `apps/app-laravel/resources/js/pages/rag/RagPage.vue` | Thin route shell for `/documents/:id/rag` |
| `apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue` | Block list, merge/remove operations, export |

### Retired from active flow (not deleted)

- `ComposePage.vue` and all `components/compose/` — still on disk, `/compose` route stays but nothing links to it
- `correctionInProgress` banner + `pollCorrectionStatus` in `DocumentComposeWorkspace` — stays in file, irrelevant

---

## Screen 1 — Upload Page

### `AdminUploadPage.vue` changes

**Remove:**
- Right `<aside>` panel (file format info, OCR tool descriptions, info note)
- CSS grid layout → single-column centered layout

**Keep:**
- `<UploadForm @uploaded="onUploaded" />`
- Status card (processing → done states) below the upload card
- Polling logic (`pollStatus`, `onUploaded`, `onBeforeUnmount` cleanup)

**Change:**
- On done: `router.push(`/documents/${documentId}/review`)` (was `/review` already — verify this is correct)
- Remove `goToView` / `goToEdit` buttons from status card; replace with auto-redirect on done status

**Layout (matching Figma `1:9537`):**
```
[LawspaceShell breadcrumb: การจัดการข้อมูล › การนำเข้าข้อมูล]

การนำเข้าเอกสารกฎหมาย
อัปโหลดไฟล์เพื่อเตรียมสกัดเนื้อหาเข้าสู่ระบบฐานข้อมูล

┌─────────────────────────────────────────┐
│  [upload icon]                           │
│  ลากและวางไฟล์ข้อมูลกฎหมาย              │
│  รองรับไฟล์ .PDF หรือ .DOCX             │
│  [เลือกไฟล์จากเครื่อง]  [ยกเลิก]        │
└─────────────────────────────────────────┘

[status card — appears after upload, shows progress]
```

### `UploadForm.vue` changes

**Remove:** Both `<v-select>` elements (extraction engine + scan mode)

**Keep hidden defaults:**
```ts
const extractionEngine = ref<'standard' | 'fast'>('standard');
const scanExtractionMode = ref<'auto' | 'local' | 'landingai'>('auto');
```

These are still passed to `uploadStore.upload()` — just not user-visible.

---

## Screen 2 — Review/Edit Page

### New TipTap extensions required

Install:
```bash
npm install @tiptap/extension-heading @tiptap/extension-text-align @tiptap/extension-highlight @tiptap/extension-text-style
```

Note: `Strike`, `BulletList`, `OrderedList` are already in `StarterKit`. `Underline` is already installed.

**Font size** — no free `@tiptap/extension-font-size`. Extend `TextStyle` directly:
```ts
import TextStyle from '@tiptap/extension-text-style';
const FontSize = TextStyle.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      fontSize: {
        default: null,
        parseHTML: el => el.style.fontSize || null,
        renderHTML: attrs => attrs.fontSize ? { style: `font-size: ${attrs.fontSize}` } : {},
      },
    };
  },
  addCommands() {
    return {
      ...this.parent?.(),
      setFontSize: (size: string) => ({ commands }) =>
        commands.updateAttributes('textStyle', { fontSize: size }),
      unsetFontSize: () => ({ commands }) =>
        commands.updateAttributes('textStyle', { fontSize: null }),
    };
  },
});
```

**Indent / Outdent** — `IndentExtension` already exists at `extensions/IndentExtension.ts`. Import and use it; do not add a new extension.

### `DocumentEditorShell.vue` — full redesign

**Remove:**
- Mode toggle buttons (edit/preview switch)
- `reviewUiStore.mode` watcher
- `switchMode()` function
- "บันทึกและไปที่ Compose" button → replaced by "บันทึกข้อมูล" in footer
- Preview pane (`editor-shell-preview` div)
- `switchModeError` ref

**Add:**
- Yellow warning banner (below header, above toolbar):
  ```html
  <div class="review-warning-banner">
    <v-icon icon="mdi-alert-outline" size="16" color="#7b3306" />
    <span>กรุณาตรวจทานเนื้อหาให้ครบถ้วนก่อนยืนยัน
      เนื่องจากการแปลงไฟล์อัตโนมัติอาจมีความคลาดเคลื่อนในบางจุด</span>
  </div>
  ```
- 3-row toolbar (see below)
- Footer bar (see below)
- Char count: `editor.value?.state.doc.textContent.length ?? 0` (no extension needed)

**TipTap editor setup:**
```ts
const editor = useEditor({
  extensions: [
    StarterKit,              // includes Strike, BulletList, OrderedList, History
    Underline,
    Heading.configure({ levels: [1, 2, 3] }),
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Highlight.configure({ multicolor: false }),
    TextStyle,               // required peer for FontSize
    FontSize,                // custom extension (see above); ponytail: skip FontFamily until second font needed
    IndentExtension,         // reuse existing extensions/IndentExtension.ts
  ],
  content: initialHtml,
  editable: true,         // always editable — no mode toggle
  onUpdate: () => {
    reviewUiStore.setDirty(true);
    scheduleAutoSave();
  },
});
```

**3-row toolbar layout (matching Figma `1:9784`):**

Row 1 — History + Heading:
```
[ย้อนกลับ label] [Undo btn] [Redo btn] | [หัวข้อ label] [Heading dropdown: ย่อหน้า/หัวข้อ 1/2/3]
```

Row 2 — Font size + Format:
```
[ขนาด label] [FontSize dropdown: 12/14/16/18/20] | [รูปแบบ label] [B] [I] [U] [S] [Highlight]
```
FontFamily (Sarabun) is set globally in `.ProseMirror` CSS — no picker needed.

Row 3 — Alignment + Lists + Auto-save:
```
[จัดข้อความ label] [Left] [Center] [Right] [Justify] | [รายการ label] [Bullet] [Numbered] [Indent] [Outdent] | [● บันทึกอัตโนมัติ]
```

**NOT in toolbar:** table edit, table row/col controls, image insert, reading-order tab, indent-level number control (BlockRulerEditor).

**Header (simplified):**
```html
<div class="editor-shell-header">
  <div>
    <h1>ตรวจทานเนื้อหาเอกสาร</h1>
    <p class="subtitle">อ่านทวน แก้ไข และจัดรูปแบบก่อนยืนยันนำเข้าระบบ</p>
  </div>
  <v-btn icon="mdi-close" variant="text" @click="router.back()" />
</div>
```

**Document area:**
```
background: #f8fafc
centered white paper: max-width 768px, min-height 944px
padding: 40px
border-radius: 6px
box-shadow: 0 0 0 1px #e2e8f0, 0 1px 3px rgba(0,0,0,0.1)
font-family: Sarabun, font-size: 14.4px
```

**Footer bar:**
```html
<div class="editor-shell-footer">
  <span>{{ charCount }} ตัวอักษร · บันทึกอัตโนมัติเมื่อปิด</span>
  <div>
    <v-btn variant="outlined" @click="router.back()">ยกเลิก</v-btn>
    <v-btn color="#1c398e" :loading="documentStore.saving" @click="saveAndContinue">บันทึกข้อมูล</v-btn>
  </div>
</div>
```

**`saveAndContinue()`:**
```ts
async function saveAndContinue(): Promise<void> {
  if (documentStore.saving) return;
  const html = editor.value?.getHTML() ?? '';
  const result = await documentStore.saveReview({ draft_html: html });
  if (result !== null) {
    reviewUiStore.setDirty(false);
    router.push(`/documents/${props.documentId}/rag`);
  }
}
```

---

## Screen 3 — RAG Management Page

### `pages/rag/RagPage.vue`

Thin shell — mirrors `ComposePage.vue`:
```vue
<template>
  <RagManageWorkspace :document-id="documentId" />
</template>

<script setup lang="ts">
import RagManageWorkspace from '../../components/rag/RagManageWorkspace.vue';
defineProps<{ documentId: string }>();
</script>
```

### `components/rag/RagManageWorkspace.vue`

**Stores used:** `composeStore` (fetch, triggerExport), `blockStore` (merge, remove)

**State:**
```ts
const selectedBlockIds = ref(new Set<string>());
const blockOpBusy = ref(false);
```

**Data source:**
```ts
const flatBlocks = computed(() =>
  composeStore.review?.pages.flatMap(p => p.blocks.map(b => ({ pageNo: p.page_no, block: b }))) ?? []
);
```

**Block card (read-only):**
```
┌──────────────────────────────────────────────────┐
│ [checkbox]  [type badge]  page_no                 │
│                                                   │
│  approved_text (plain, no editor)                 │
└──────────────────────────────────────────────────┘
```

- Type badge: pill chip colored by block type (มาตรา=blue, วรรค=slate, ย่อหน้า=gray, etc.)
- Checkbox: `v-checkbox` bound to `selectedBlockIds`
- Text: `{{ item.block.approved_text || item.block.normalized_text }}`
- No TipTap, no format controls, no editable fields

**Action bar (appears when selection > 0):**
```
[N รายการที่เลือก]  [รวม RAG]  [ลบ]  [ยกเลิกการเลือก]
```
- "รวม RAG" visible only when `selectedBlockIds.size >= 2`
- "ลบ" visible when `selectedBlockIds.size >= 1`

**`handleMerge()`:**
```ts
async function handleMerge(): Promise<void> {
  if (selectedBlockIds.value.size < 2 || blockOpBusy.value) return;
  blockOpBusy.value = true;
  try {
    const ordered = flatBlocks.value
      .filter(i => selectedBlockIds.value.has(i.block.block_id))
      .map(i => i.block.block_id);
    await blockStore.merge(props.documentId, ordered);
    selectedBlockIds.value = new Set();
    await composeStore.fetch(props.documentId);
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'รวมบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}
```

**`handleRemove()`:**
```ts
async function handleRemove(): Promise<void> {
  if (selectedBlockIds.value.size === 0 || blockOpBusy.value) return;
  blockOpBusy.value = true;
  try {
    for (const item of flatBlocks.value) {
      if (selectedBlockIds.value.has(item.block.block_id)) {
        await blockStore.remove(props.documentId, item.block.block_id, item.pageNo);
      }
    }
    selectedBlockIds.value = new Set();
    await composeStore.fetch(props.documentId);
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'ลบบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}
```

**Footer:**
```ts
async function handleExport(): Promise<void> {
  await composeStore.triggerExport(props.documentId);
  if (!composeStore.error) {
    router.push(`/documents/${props.documentId}/preview`);
  }
}
```

```html
<div class="rag-footer">
  <v-btn variant="outlined" @click="router.push(`/documents/${documentId}/review`)">ยกเลิก</v-btn>
  <v-btn color="#1a3673" :loading="composeStore.exporting" @click="handleExport">
    บันทึกและเผยแพร่
  </v-btn>
</div>
```

**Lifecycle:**
```ts
onMounted(() => composeStore.fetch(props.documentId));
onBeforeUnmount(() => composeStore.reset());
```

---

## Router Changes

`apps/app-laravel/resources/js/router/index.ts`:

```ts
import RagPage from '../pages/rag/RagPage.vue';

// Add to routes array:
{ path: '/documents/:documentId/rag', name: 'rag', component: RagPage, props: true, meta: { bareLayout: true } },
```

The `/compose` route stays in the router but is not linked from any button in the new flow.

---

## What is NOT changing

- `documentStore` — no changes needed
- `composeStore` — no changes needed (fetch + triggerExport already exist)
- `blockStore` — no changes needed (merge + remove already exist)
- `reviewUiStore` — `setDirty` still used; `setMode` / `mode` removed from DocumentEditorShell
- Python OCR service — no changes
- Laravel API — no changes
- Export schema — no changes

---

## Constraints

- **No AI banner:** Do not add `correctionInProgress` or any AI polling to the review or RAG pages.
- **No per-block editing:** The TipTap editor in Step 1 edits the full `draft_html` — never individual blocks.
- **Format locked in RAG page:** Block cards are read-only text; no TipTap, no v-html editing, no toolbar.
- **No table/image/reading-tab/indent-number in toolbar:** The Step 1 toolbar rows strictly follow the spec above.
- **`UploadForm.vue` keeps defaults hidden:** `extractionEngine='standard'` and `scanExtractionMode='auto'` are always sent to the API; just not shown in the UI.
