# RAG Type Enforcement & CLAUSE Chunk Type Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a distinct `CLAUSE` (ข้อ) chunk type, auto-apply type suggestions when the user advances from the RAG page, and block advancement when types are missing.

**Architecture:** Three focused frontend changes — add the type constant, update the suggestion rule, update the section count filter, and wire the RAG Next button with enforce-then-advance logic. No backend changes needed; chunk_type is stored as a plain string.

**Tech Stack:** Vue 3, TypeScript, Vuetify 3, Pinia, SweetAlert2

---

### Task 1: Add CLAUSE to chunkType.ts and update the suggestion rule

**Files:**
- Modify: `apps/app-laravel/resources/js/types/chunkType.ts`
- Modify: `apps/app-laravel/resources/js/composables/useLawSections.ts`

- [ ] **Step 1: Add CLAUSE to CHUNK_TYPES, HEAD_CHUNK_TYPES, CHUNK_TYPE_LABELS, CHUNK_TYPE_COLORS**

In `apps/app-laravel/resources/js/types/chunkType.ts`, replace the entire file with:

```ts
export const CHUNK_TYPES = [
  'TITLE', 'PREAMBLE', 'BOOK', 'PART', 'CHAPTER', 'SECTION',
  'ARTICLE', 'CLAUSE', 'PARAGRAPH', 'ITEM', 'DEFINITION', 'TRANSITIONAL_PROVISION',
  'ANNEX', 'TABLE', 'NOTE', 'FOOTNOTE', 'SIGNATURE_BLOCK', 'OTHER',
] as const;

export type ChunkType = typeof CHUNK_TYPES[number];

// Structural heading types — the ones isHead() treats as section heads.
export const HEAD_CHUNK_TYPES: readonly ChunkType[] = [
  'TITLE', 'PREAMBLE', 'BOOK', 'PART', 'CHAPTER', 'SECTION', 'ARTICLE', 'CLAUSE', 'ANNEX', 'TRANSITIONAL_PROVISION',
];

export const CHUNK_TYPE_LABELS: Record<ChunkType, string> = {
  TITLE: 'ชื่อกฎหมาย',
  PREAMBLE: 'คำปรารภ',
  BOOK: 'ภาค',
  PART: 'ลักษณะ',
  CHAPTER: 'หมวด',
  SECTION: 'ส่วน',
  ARTICLE: 'มาตรา',
  CLAUSE: 'ข้อ',
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
  CLAUSE: 'orange',
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

- [ ] **Step 2: Change ข้อ suggestion rule to CLAUSE**

In `apps/app-laravel/resources/js/composables/useLawSections.ts`, find this line inside `SUGGEST_RULES`:

```ts
  [/^ข้อ\s*[๐-๙0-9]/u, 'ARTICLE'],
```

Replace it with:

```ts
  [/^ข้อ\s*[๐-๙0-9]/u, 'CLAUSE'],
```

- [ ] **Step 3: Run typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors. (`Record<ChunkType, string>` will catch any missing key in LABELS/COLORS — if it fails, a key is missing from Task 1 Step 1.)

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/types/chunkType.ts apps/app-laravel/resources/js/composables/useLawSections.ts
git commit -m "feat(rag): add CLAUSE chunk type for ข้อ sections"
```

---

### Task 2: Update LawInfoPage section count to include CLAUSE

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue` (lines ~276–297)

- [ ] **Step 1: Update articleBlocks filter**

In `LawInfoPage.vue`, find:

```ts
const articleBlocks = computed<DocumentBlock[]>(() =>
  (documentStore.review?.pages ?? [])
    .flatMap((page) => page.blocks)
    .filter((block) => block.meta?.chunk_type === 'ARTICLE'),
);
```

Replace with:

```ts
const articleBlocks = computed<DocumentBlock[]>(() =>
  (documentStore.review?.pages ?? [])
    .flatMap((page) => page.blocks)
    .filter((block) => block.meta?.chunk_type === 'ARTICLE' || block.meta?.chunk_type === 'CLAUSE'),
);
```

- [ ] **Step 2: Simplify articleUnitLabel to use chunk_type directly**

In `LawInfoPage.vue`, find:

```ts
const articleUnitLabel = computed(() => {
  let hasItem = false;
  let hasArticle = false;

  for (const block of articleBlocks.value) {
    const text = blockText(block);
    if (/^ข้อ\s*[๐-๙0-9]/u.test(text)) hasItem = true;
    if (/^มาตรา\s*[๐-๙0-9]/u.test(text)) hasArticle = true;
  }

  if (hasItem && hasArticle) return 'ข้อ/มาตรา';
  if (hasItem) return 'ข้อ';
  if (hasArticle) return 'มาตรา';
  return 'ข้อ/มาตรา';
});
```

Replace with:

```ts
const articleUnitLabel = computed(() => {
  const hasClause = articleBlocks.value.some((b) => b.meta?.chunk_type === 'CLAUSE');
  const hasArticle = articleBlocks.value.some((b) => b.meta?.chunk_type === 'ARTICLE');
  if (hasClause && hasArticle) return 'ข้อ/มาตรา';
  if (hasClause) return 'ข้อ';
  if (hasArticle) return 'มาตรา';
  return 'ข้อ/มาตรา';
});
```

- [ ] **Step 3: Run typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue
git commit -m "feat(law-info): count CLAUSE blocks in section total, derive label from chunk_type"
```

---

### Task 3: Wire RAG Next button with type enforcement and remove dead handleExport

**Files:**
- Modify: `apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue`

- [ ] **Step 1: Replace goToLawInfo with the enforcing version**

In `RagManageWorkspace.vue`, find:

```ts
async function goToLawInfo(): Promise<void> {
  if (blockBusy.value) return;
  const progressed = await documentStore.completeWorkflowStep(3);
  if (!progressed) return;
  router.push(`/documents/${props.documentId}/law-info`);
}
```

Replace with:

```ts
async function goToLawInfo(): Promise<void> {
  if (blockBusy.value) return;

  // Auto-persist suggestions for untyped head blocks.
  const toPersist = sections.value.filter(
    (s) => !s.headBlock.meta.chunk_type && suggestChunkType(s.headBlock),
  );
  if (toPersist.length > 0) {
    blockBusy.value = true;
    try {
      await Promise.all(
        toPersist.map((s) => {
          const suggested = suggestChunkType(s.headBlock)!;
          s.headBlock.meta.chunk_type = suggested;
          const pageNo = blockPage.value.get(s.headBlock.block_id) ?? 1;
          return blockStore.patchChunkType(props.documentId, s.headBlock, pageNo, suggested);
        }),
      );
    } catch (e) {
      documentStore.setSaveError(e instanceof Error ? e.message : 'บันทึกประเภทไม่สำเร็จ');
      return;
    } finally {
      blockBusy.value = false;
    }
  }

  // Block if any section still has no type.
  const missing = sections.value.filter((s) => !s.headBlock.meta.chunk_type);
  if (missing.length > 0) {
    await Swal.fire({
      icon: 'warning',
      title: 'ยังกำหนดประเภทไม่ครบ',
      html:
        'กรุณาเลือกประเภทให้ส่วนต่อไปนี้ก่อนดำเนินการต่อ:<br><br>' +
        missing.map((s) => `• ${escapeForHtml(s.badge)}`).join('<br>'),
      confirmButtonText: 'ตกลง',
      confirmButtonColor: '#1a3673',
    });
    return;
  }

  const progressed = await documentStore.completeWorkflowStep(3);
  if (!progressed) return;
  router.push(`/documents/${props.documentId}/law-info`);
}
```

- [ ] **Step 2: Remove the dead handleExport function**

In `RagManageWorkspace.vue`, delete the entire `handleExport` function (lines ~437–482 in the original file). It starts with:

```ts
async function handleExport(): Promise<void> {
```

and ends with the closing `}` after `router.push(\`/law/${props.documentId}\`)`.

- [ ] **Step 3: Run typecheck**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue
git commit -m "feat(rag): enforce chunk types on Next — auto-apply suggestions, block if missing"
```
