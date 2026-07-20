# RAG Page — ChunkType Selector + Split Action Bar

## Summary

Four improvements to `RagManageWorkspace.vue` and its backend:

1. **แบ่ง in action bar** — add split button to the sticky selection bar (enabled only when exactly 1 block is selected).
2. **Fix 422 double-split bug** — three-way split calls `callSplit` twice with the same stale `blockId`; second call must use the `first.block_id` returned by the first split.
3. **ChunkType chip per block** — compact clickable chip before each block's text; opens a v-menu dropdown with all 17 values displayed in Thai; saved to backend.
4. **Remove section badge chip** — remove the auto-detected `section.badge` v-chip from `rag-sec__head` (now superseded by per-block ChunkType).

---

## ChunkType Enum and Thai Labels

New file `apps/app-laravel/resources/js/types/chunkType.ts`:

```typescript
export const CHUNK_TYPES = [
  'TITLE', 'PREAMBLE', 'BOOK', 'PART', 'CHAPTER', 'SECTION',
  'ARTICLE', 'PARAGRAPH', 'ITEM', 'DEFINITION', 'TRANSITIONAL_PROVISION',
  'ANNEX', 'TABLE', 'NOTE', 'FOOTNOTE', 'SIGNATURE_BLOCK', 'OTHER',
] as const;

export type ChunkType = typeof CHUNK_TYPES[number];

export const CHUNK_TYPE_LABELS: Record<ChunkType, string> = {
  TITLE: 'ชื่อกฎหมาย',
  PREAMBLE: 'คำปรารภ',
  BOOK: 'ภาค',
  PART: 'ลักษณะ',
  CHAPTER: 'หมวด',
  SECTION: 'ส่วน',
  ARTICLE: 'มาตรา',
  PARAGRAPH: 'วรรค',
  ITEM: 'รายการ',
  DEFINITION: 'นิยาม',
  TRANSITIONAL_PROVISION: 'บทเฉพาะกาล',
  ANNEX: 'ภาคผนวก',
  TABLE: 'ตาราง',
  NOTE: 'หมายเหตุ',
  FOOTNOTE: 'เชิงอรรถ',
  SIGNATURE_BLOCK: 'ลายเซ็น',
  OTHER: 'อื่นๆ',
};

export const CHUNK_TYPE_COLORS: Record<ChunkType, string> = {
  TITLE: 'indigo',
  PREAMBLE: 'success',
  BOOK: 'pink',
  PART: 'purple',
  CHAPTER: 'deep-purple',
  SECTION: 'red',
  ARTICLE: 'primary',
  PARAGRAPH: 'teal',
  ITEM: 'warning',
  DEFINITION: 'amber',
  TRANSITIONAL_PROVISION: 'cyan',
  ANNEX: 'light-blue',
  TABLE: 'blue-grey',
  NOTE: 'grey',
  FOOTNOTE: 'grey',
  SIGNATURE_BLOCK: 'brown',
  OTHER: 'grey',
};
```

---

## Type Changes

### `apps/app-laravel/resources/js/types/document.ts`

Add `chunk_type` to `BlockMeta`:

```typescript
export interface BlockMeta {
  // ... existing fields ...
  chunk_type?: string | null;
}
```

### `apps/app-laravel/resources/js/api/client.ts`

Add `chunk_type` to `patchBlock` payload type:

```typescript
export function patchBlock(
  documentId: string,
  blockId: string,
  payload: {
    page_no: number;
    approved_text: string;
    approved_by?: string;
    notes?: string;
    mark_uncertain: boolean;
    type?: string;
    reading_order?: number;
    bbox?: [number, number, number, number] | null;
    reviewed_html?: string;
    table?: ReviewedTable | null;
    chunk_type?: string | null;   // <-- add this
  },
): Promise<{ status: string }>
```

---

## Backend Changes

### `apps/app-laravel/app/Http/Requests/UpdateBlockRequest.php`

Add to `rules()`:

```php
'chunk_type' => ['nullable', 'string', 'in:TITLE,PREAMBLE,BOOK,PART,CHAPTER,SECTION,ARTICLE,PARAGRAPH,ITEM,DEFINITION,TRANSITIONAL_PROVISION,ANNEX,TABLE,NOTE,FOOTNOTE,SIGNATURE_BLOCK,OTHER'],
```

### `apps/app-laravel/app/Services/ReviewStore.php` — `patchApprovedBlock`

In the `$block['meta'] = array_merge(...)` call (around line 218), add `chunk_type` to the merge:

```php
$block['meta'] = array_merge($existingMeta, [
    'reviewed_html' => $reviewedHtml,
    'layout'        => $layout,
    'table'         => $table,
    'table_html'    => $table['html'] ?? null,
    'chunk_type'    => $patch['chunk_type'] ?? $existingMeta['chunk_type'] ?? null,  // <-- add
    'review'        => [
        'approved_by' => $patch['approved_by'] ?? null,
        'notes'       => $patch['notes']       ?? null,
        'updated_at'  => now()->toIso8601String(),
    ],
]);
```

---

## Frontend Store Changes

### `apps/app-laravel/resources/js/stores/blockStore.ts`

Add `patchChunkType` — a thin wrapper that calls `patchBlock` with just the minimum required fields plus `chunk_type`. Because `approved_text` is required by the endpoint, read it from the block before calling:

```typescript
async function patchChunkType(
  documentId: string,
  block: DocumentBlock,
  chunkType: string | null,
): Promise<void> {
  await patchBlock(documentId, block.block_id, {
    page_no: /* caller must supply */ 0, // see note below
    approved_text: block.approved_text || block.normalized_text || block.raw_text || '',
    mark_uncertain: block.needs_review,
    chunk_type: chunkType,
  });
}
```

> **Note:** `page_no` is required by the backend. `RagManageWorkspace` already maintains `blockPage: Map<blockId, pageNo>` — the caller passes `blockPage.value.get(block.block_id) ?? 1`.

Revised signature:

```typescript
async function patchChunkType(
  documentId: string,
  block: DocumentBlock,
  pageNo: number,
  chunkType: string | null,
): Promise<void> {
  await patchBlock(documentId, block.block_id, {
    page_no: pageNo,
    approved_text: block.approved_text || block.normalized_text || block.raw_text || '',
    mark_uncertain: block.needs_review,
    chunk_type: chunkType,
  });
}
```

Export from `useBlockStore` return value.

---

## RagManageWorkspace.vue Changes

### 1 — Action bar: add แบ่ง button

After the existing ลบ button, add:

```html
<v-btn
  size="small"
  :disabled="selectedBlockIds.size !== 1 || blockBusy"
  prepend-icon="mdi-call-split"
  style="background:rgba(5,150,105,0.85);color:#fff"
  @click="openSplitFromSelection">แบ่ง</v-btn>
```

Add handler in `<script setup>`:

```typescript
function openSplitFromSelection(): void {
  const [blockId] = [...selectedBlockIds.value];
  if (!blockId) return;
  const block = allBlocks.value.find(b => b.block_id === blockId);
  if (block) openSplit(block);
}
```

`allBlocks` is a computed that flattens all blocks across sections (already exists as the source for `blockPage` map — if not, add it):

```typescript
const allBlocks = computed<DocumentBlock[]>(() =>
  sections.value.flatMap(s => [s.headBlock, ...s.children]),
);
```

### 2 — Fix 422 double-split bug

In `splitSelectedTextOut`, the three-way path calls `callSplit` twice with the original `blockId`. After the first split the original block is gone. Fix by returning the response from `callSplit` and using `result.first.block_id`:

Change `callSplit` signature from `Promise<void>` to return the split response:

```typescript
async function callSplit(
  blockId: string,
  pageNo: number,
  before: string,
  after: string,
): Promise<{ status: string; first: DocumentBlock; second: DocumentBlock }> {
  return blockStore.split(props.documentId, blockId, {
    page_no: pageNo,
    before_text: before,
    before_html: `<p>${escapeForHtml(before)}</p>`,
    after_text: after,
    after_html: `<p>${escapeForHtml(after)}</p>`,
  });
}
```

Fix the three-way branch in `splitSelectedTextOut`:

```typescript
// was: (two calls with same blockId — second always 422)
// await callSplit(blockId, pageNo, `${before}${selected}`, after);
// await callSplit(blockId, pageNo, before, selected);

// fix:
const first = await callSplit(blockId, pageNo, `${before}${selected}`, after);
await callSplit(first.first.block_id, pageNo, before, selected);
```

### 3 — Remove section badge chip

In `rag-sec__head`, remove the `<v-chip>` that shows `section.badge`:

```html
<!-- Remove this block: -->
<v-chip size="x-small" :color="section.isChapter ? 'indigo' : 'success'" variant="tonal">
  {{ section.badge }}
</v-chip>
```

### 4 — ChunkType chip on each block row

Import at top of `<script setup>`:

```typescript
import { CHUNK_TYPES, CHUNK_TYPE_LABELS, CHUNK_TYPE_COLORS } from '../../types/chunkType';
import type { ChunkType } from '../../types/chunkType';
```

Add handler:

```typescript
async function setChunkType(block: DocumentBlock, chunkType: string | null): Promise<void> {
  if (blockBusy.value) return;
  const pageNo = blockPage.value.get(block.block_id) ?? 1;
  try {
    await blockStore.patchChunkType(props.documentId, block, pageNo, chunkType);
    block.meta.chunk_type = chunkType;
  } catch (e) {
    documentStore.setSaveError(e instanceof Error ? e.message : 'บันทึกประเภทไม่สำเร็จ');
  }
}
```

> Optimistic update (`block.meta.chunk_type = chunkType`) avoids a full `reloadBlocks()` for a single-field change.

In the template, add the chip inside each `rag-blockrow`, between `<span class="rag-blockrow__cb">` and `<BlockFlow>`:

```html
<!-- ChunkType chip -->
<v-menu location="bottom start" :close-on-content-click="true">
  <template #activator="{ props: menuProps }">
    <v-chip
      v-bind="menuProps"
      size="x-small"
      :color="block.meta.chunk_type ? CHUNK_TYPE_COLORS[block.meta.chunk_type as ChunkType] : undefined"
      :variant="block.meta.chunk_type ? 'tonal' : 'outlined'"
      class="rag-blockrow__typechip"
      @click.prevent.stop>
      {{ block.meta.chunk_type ? CHUNK_TYPE_LABELS[block.meta.chunk_type as ChunkType] : 'ประเภท...' }}
      <v-icon icon="mdi-chevron-down" size="10" class="ml-1" />
    </v-chip>
  </template>
  <v-list density="compact" :min-width="140">
    <v-list-item
      v-for="ct in CHUNK_TYPES"
      :key="ct"
      :title="CHUNK_TYPE_LABELS[ct]"
      :active="block.meta.chunk_type === ct"
      @click="setChunkType(block, ct)"
    />
  </v-list>
</v-menu>
```

Apply to both `section.headBlock` and each `child` in the section loop. Extract into a shared named slot or duplicate — given only two sites, duplication is fine (ponytail).

Add CSS for the chip so it stays compact and doesn't stretch the row:

```css
.rag-blockrow__typechip {
  flex-shrink: 0;
  align-self: flex-start;
  margin-top: 2px;
  cursor: pointer;
}
```

---

## Constraints

- Never touch TipTap/ProseMirror/contenteditable internals or document-canvas CSS.
- `chunk_type` is stored in `block.meta` (not at the block top level) — consistent with how `reviewed_html`, `layout`, etc. live.
- The backend enum list in `UpdateBlockRequest` must exactly match `CHUNK_TYPES` in `chunkType.ts`.
- `callSplit` returning a value is a non-breaking change — all existing callers (`splitBlockInto`, `splitSelectedTextOut`) either ignored the return value or can be updated inline.
