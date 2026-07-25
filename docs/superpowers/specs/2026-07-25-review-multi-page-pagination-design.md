# Review page multi-page (Google-Docs-style) pagination — design

Date: 2026-07-25

## Goal

On the `/review` page (the editable TipTap editor), render the document as
stacked A4 sheets — visible page boundaries, gaps between sheets, and per-sheet
page numbers — the way Google Docs looks. This must be visible **immediately
after OCR**, before the reviewer inserts any manual page break. Manual page
breaks must force a new sheet.

## Non-goals (v1)

- Live per-keystroke reflow (recompute is debounced, not continuous).
- Matching LibreOffice's exact PDF break points for *automatic* boundaries.
- Paginating the read-only `/preview` page.
- Running headers/footers or page numbers baked into the exported PDF.
- Splitting a single block that is taller than one page.

## Decisions

- **Where:** the editable `/review` editor (`DocumentEditorShell.vue`).
- **Model:** one continuous editable ProseMirror flow underneath a stacked-sheet
  presentation layer. No schema change, no `page` node, no content mutation.
- **Boundaries:** *soft pagination* — measure rendered geometry and push a whole
  block (paragraph/table/image) past the seam when it would cross the A4
  boundary; never split a block. Manual `pageBreak` nodes also force a new sheet.
- **Recompute triggers:** initial load, 400 ms after typing stops (debounced),
  window resize, web-font load, image load, table structure change.

## Architecture

Pagination is a **presentation layer**. The saved document remains the flat
block flow it is today. Four pieces:

| Piece | Responsibility | Type |
|---|---|---|
| `resources/js/pagination/paginate.ts` | **Pure function.** Measured block rects + page geometry → page assignments, per-block spacer heights, sheet rectangles. No DOM access. | new |
| `resources/js/pagination/usePagination.ts` | Vue composable. Measures the DOM, debounces, calls `paginate()`, applies decorations via the plugin, exposes `sheets[]` reactive for the backdrop. | new |
| `resources/js/extensions/PaginationDecorations.ts` | ProseMirror plugin. Turns the computed spacer map into **widget decorations** (a gap div before each block that starts a new sheet). Ephemeral — never serialized. | new |
| `DocumentEditorShell.vue` | Adds a `.page-backdrop` layer (`v-for` over `sheets[]`) of absolutely-positioned white A4 divs behind `EditorContent`, each labelled `หน้า N`. Wires the composable. | edit |

Rationale for isolating `paginate.ts`: it is the only non-trivial logic and the
only part testable without a browser layout engine (jsdom has no layout).

## Algorithm (`paginate.ts`)

Input:

- `blocks: { id: string; top: number; height: number; isBreak: boolean }[]`
  (top/height in layout px, pre-transform, in document order)
- `usableHeight: number` — 297mm minus top+bottom margins, in px
- `geom: { topMargin: number; bottomMargin: number; interPageGap: number }` (px)

Walk blocks left→right tracking `pageContentBottom` (the y where the current
page's usable area ends). For each block:

- If `block.isBreak` **or** `block.top + block.height > pageContentBottom`:
  start a new page. Emit `spacers[block.id] = <distance from block.top down to
  the next sheet's content-top>`; advance `pageContentBottom` by one page stride
  (`usableHeight + bottomMargin + interPageGap + topMargin`).
- Else: fits — no spacer.

Output: `{ spacers: Map<string, number>, sheets: { pageNo: number; top: number; height: number }[] }`.

Each sheet's `height` is `max(297mm_px, groupContentHeight + topMargin + bottomMargin)`
so short pages still render as a full sheet, and an over-tall block grows its
sheet rather than being cut.

Deterministic and pure (~40 lines).

### Unit tests (`paginate.test.ts`)

1. All blocks fit → one sheet, no spacers.
2. A block that crosses the boundary → spacer emitted, two sheets.
3. A manual break block → forces a second sheet even when content fits.
4. A block taller than `usableHeight` → its sheet height grows to fit (documented
   overflow, no split).

## Rendering / DOM layering

Inside `.editor-stage` (the positioning context):

1. `.page-backdrop` — absolutely positioned, `z-index` below content. One
   `.a4-sheet` div per entry in `sheets[]`, positioned at `{top, height}`, styled
   with the existing A4 look (210mm wide, box-shadow, margin padding) and a
   `หน้า N` marker.
2. `EditorContent` — transparent background, sits on top. The widget-decoration
   spacers create the vertical gaps in the flow so text visually lands inside
   each sheet's content area.

Measuring is unaffected by the existing zoom transform because `offsetTop` /
`offsetHeight` report pre-transform layout box values; the backdrop lives inside
the same transformed stage, so both scale together.

## Persistence & export alignment

- **Manual breaks** persist unchanged (`pageBreak` node → `data-page-break`
  div). The PDF export already honors them exactly (live code path), so a manual
  break is pixel-faithful between screen and PDF.
- **Soft (automatic) boundaries are an on-screen approximation.** Real PDF
  pagination is done by LibreOffice, whose line-breaking will not land
  identically to our measured boundaries. The UI shows a small honest hint
  (`ตำแหน่งแบ่งหน้าอัตโนมัติเป็นค่าประมาณ`) so the approximation is not mistaken
  for precision. Only manual breaks are guaranteed to match the PDF.

## Edge cases & limits (v1)

- Block taller than one page → sheet grows; block not split.
- Rapid typing → only the trailing 400 ms-idle recompute runs; no per-keystroke
  churn.
- No manual breaks (fresh OCR) → soft pagination still yields clean stacked
  sheets on load.
- Empty document → a single empty sheet.
