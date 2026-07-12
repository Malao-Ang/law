# Split-anywhere + transient merge/split history — Design

**Date:** 2026-07-12
**Scope:** RAG manage workspace (`RagManageWorkspace.vue`) block editing.

## Problem

1. The split dialog (`SplitBlockDialog.vue`) only offers cut points at existing `\n`
   line breaks. A block that is a single line hits the guard
   `บล็อกนี้มีบรรทัดเดียว ไม่สามารถแบ่งได้` and cannot be split at all.
2. There is no way to undo a merge or a split. Once done, the only recovery is
   manual re-editing.

## Goals

- Let the user split a **single-line** block at a chosen point, by clicking
  cut markers. Text stays **read-only** (no in-dialog text editing).
- Give a **transient** history of merge/split actions that can be reverted to
  any earlier point. History is session-only: cleared on save/publish and gone
  on page reload. A fresh history is recorded from scratch each session.

## Non-goals (explicitly excluded)

- In-dialog text editing (user chose read-only click-to-split).
- Character-level cutting when a single line has no whitespace.
- History for delete / reorder / edit — only **merge** and **split**.
- Redo.
- Any server-side persistence of history.

## Part 1 — Split a single line at spaces

`SplitBlockDialog.vue` generalizes its internal model from "lines" to
**atoms + separator**:

- Block text contains `\n` → `atoms` = lines, `sep` = `"\n"` (current behaviour,
  unchanged).
- Block text is a single line → `atoms` = whitespace-separated tokens,
  `sep` = `" "`.

The clickable `แยกตรงนี้` boundaries render between atoms exactly as today.
On confirm the dialog emits the resulting **text pieces** (`string[]`) — each
piece is its group of atoms rejoined with `sep` — instead of line-index
boundaries. The boundary separator (the space/newline cut on) is dropped between
pieces, matching the current line-split behaviour.

`onLineSplitConfirm` in `RagManageWorkspace.vue` changes signature to accept
`pieces: string[]` and loops the existing `blockStore.split` before/after API to
produce N blocks:

```
tailId = block.block_id
for i in 0 .. pieces.length - 2:
    res = split(tailId, before = pieces[i], after = pieces[i+1..].join(sep))
    tailId = res.second.block_id
```

The before/after HTML stays `''` as it does today (plain-text split).

**Edge case:** a single line with no whitespace yields `atoms.length === 1`, so
the dialog still shows "แบ่งไม่ได้". No natural cut point exists.

## Part 2 — Editable text

Out of scope. Read-only click-to-split only. The existing
`แก้ไขข้อความไม่ได้` caption remains accurate. No change.

## Part 3 — Transient merge/split history

Client-only state in `RagManageWorkspace.vue`:

```ts
const history = ref<{ id: string; label: string; snapshot: PageBlocks[] }[]>([]);
```

`snapshot` is a deep clone of the whole document's blocks across **all pages**,
captured **before** the mutation. Whole-doc scope is required because
`mergeBlocks` collects selected blocks across pages (not page-scoped).

Snapshot shape:

```ts
type PageBlocks = { page_no: number; blocks: DocumentBlock[] };
```

Behaviour:

- Wrap **merge** and **split** only. Before mutating, push
  `{ id, label, snapshot }` — label e.g. `รวม 3 บล็อก` / `แยกบล็อก`.
- A history panel lists entries newest-first, each with a `ย้อน` button.
- **Revert(entry):** POST the entry's snapshot to the restore endpoint, reload
  blocks, then truncate that entry and every newer one (the user jumped back
  past them).
- Cleared explicitly on publish/save (`history.value = []`) and naturally empty
  after reload (it is only component state).

## Backend — one new endpoint

Server state changes on merge/split, so revert must rewrite it. Mirrors the
existing granular block methods.

- Route: `POST /documents/{id}/blocks/restore`
- Controller: `ReviewController::restoreBlocks(Request, string $documentId)`
  — validates `pages: [{ page_no, blocks: [...] }]`.
- Store: `ReviewStore::restoreBlocks(string $documentId, array $pages)` —
  under the `review` lock, overwrite each matching page's `blocks` from the
  snapshot, then `recalculateSummary()` + `markOutOfSync()`.

## Files touched

- `resources/js/components/rag/SplitBlockDialog.vue` — atoms/sep model, emit pieces.
- `resources/js/components/rag/RagManageWorkspace.vue` — pieces handler, history state + panel + revert, clear on publish.
- `resources/js/api/client.ts` + `stores/blockStore.ts` — `restoreBlocks` client call.
- `app/Http/Controllers/Api/ReviewController.php` — `restoreBlocks`.
- `app/Services/ReviewStore.php` — `restoreBlocks`.
- `routes/api.php` — new route.

## Testing

- PHP: `ReviewStore::restoreBlocks` round-trips a snapshot (merge → restore
  returns original block count/text). Split-then-restore likewise.
- Frontend: dialog produces correct pieces for single-line (space) and
  multi-line (`\n`) inputs; no-whitespace single line stays unsplittable.
