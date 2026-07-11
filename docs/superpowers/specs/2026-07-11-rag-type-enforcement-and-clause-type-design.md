# RAG Type Enforcement & CLAUSE Chunk Type

**Date:** 2026-07-11  
**Scope:** `RagManageWorkspace.vue`, `chunkType.ts`, `useLawSections.ts`, `LawInfoPage.vue`

---

## Problem

1. **RAG page Next button skips type enforcement.** `goToLawInfo()` calls `completeWorkflowStep(3)` with no validation. A `handleExport()` function with the correct auto-apply + validate logic exists but is dead code (no button calls it).

2. **ข้อ and มาตรา share the same chunk type (ARTICLE).** The RAG type picker shows one "มาตรา" option; users cannot mark a block as "ข้อ" distinctly. The law-info section count cannot distinguish the two.

---

## Design

### Feature 1 — Auto-apply suggestions & block-on-missing in `goToLawInfo()`

Merge the two-step logic from `handleExport()` into `goToLawInfo()`, then delete `handleExport()`.

Before calling `completeWorkflowStep(3)`:

1. **Auto-persist suggestions:** Find sections where `headBlock.meta.chunk_type` is falsy but `suggestChunkType(headBlock)` returns a value. Optimistically set `chunk_type` on each block and persist via `blockStore.patchChunkType` in parallel. On error, show save error and abort.

2. **Block if still missing:** After step 1, if any section still has no type, show a Swal warning listing the offending section badges and abort. Only proceed when every section has an explicit type.

3. **Proceed:** Call `completeWorkflowStep(3)` and push to `/documents/:id/law-info`.

Dead code removed: `handleExport()` and its `triggerExport` call (export now lives downstream in the workflow, not on this page).

### Feature 2 — New `CLAUSE` chunk type for ข้อ

**`chunkType.ts`**
- Add `'CLAUSE'` to `CHUNK_TYPES` tuple.
- Add `'CLAUSE'` to `HEAD_CHUNK_TYPES`.
- `CHUNK_TYPE_LABELS['CLAUSE'] = 'ข้อ'`
- `CHUNK_TYPE_COLORS['CLAUSE'] = 'orange'`

**`useLawSections.ts`** — `suggestChunkType` rules:
- `^ข้อ\s*[๐-๙0-9]` → `'CLAUSE'` (was `'ARTICLE'`)
- `^มาตรา\s*[๐-๙0-9]` → `'ARTICLE'` (unchanged)

No change to `HEAD_RE` (ข้อ is already matched there for `isHead` detection).

**`LawInfoPage.vue`** — section count:
- `articleBlocks` filter: `chunk_type === 'ARTICLE' || chunk_type === 'CLAUSE'`
- `articleUnitLabel`: derive from chunk types present rather than scanning text content:
  - only CLAUSE → `'ข้อ'`
  - only ARTICLE → `'มาตรา'`
  - both → `'ข้อ/มาตรา'`
  - neither → `'ข้อ/มาตรา'` (fallback)

---

## What's NOT changing

- Backend chunk_type storage: stored as a plain string, no enum validation, no migration needed.
- The RAG page display logic (`containerType`, `containerTypeLabel`, `containerTypeColor`) works unchanged since it reads `chunk_type` generically.
- `buildSections` / `isHead` in `useLawSections.ts`: no change needed; `HEAD_CHUNK_TYPE_SET` is built from the updated `HEAD_CHUNK_TYPES` at import time.
