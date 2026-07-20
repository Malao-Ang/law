# Unified Indent & Tabs — Design

**Date:** 2026-07-12
**Scope:** Make paragraph indent and tab stops edited in the review page render consistently in the RAG page, Result page, Word export, and PDF export.

## Problem

Indent currently exists in four disconnected representations and nothing keeps them in sync:

1. Inline `margin-left:{n×24}px` on `<p>` in `draft_html` — written by TipTap `IndentExtension` (toolbar / Tab key).
2. `meta.layout.indent_left / indent_first_line / indent_hanging / tabs` (twips) — set by extraction and the `BlockRulerEditor` ruler.
3. `doc-indent-N` class in server `generated_html`.
4. Inline `margin-left:{pt}` in server `generated_html`.

Because they are not unified:

- **Save gap:** `ReviewStore::applyDraftHtmlToBlocksInPlace` extracts each paragraph's *innerHTML* into `reviewed_html`, dropping the `<p>`'s `margin-left`, and never writes indent into `meta.layout`. (It already special-cases `text-align` recovery — indent gets the same drop but no recovery.) So a TipTap indent edit lands only in `draft_html`.
- **RAG** (`BlockFlow`) reads `meta.layout` → never sees TipTap edits; tabs not rendered.
- **Word export** reads `meta.layout` (correctly, incl. tabs) → stale because layout was never synced.
- **PDF export** (`DocumentExportService::buildHtml`) reads only `reviewed_html`/plain text → **ignores `meta.layout` entirely**; no indent/tab CSS at all.
- **Result page** renders `draft_html ?? html`, so it reflects only whichever editor happened to write that source.

## Goal

`meta.layout` (twips) is the **single source of truth** for indent and tabs. Both editors write into it; every surface derives its rendering from it through one shared converter. Editing indent/tabs in review then shows identically in RAG, Result, Word, and PDF.

## Units & conversions (Word geometry)

Canonical storage: **twips** (1/20 pt, 1440/inch), matching Word and the existing `meta.layout`.

| Target | Formula | Note |
|---|---|---|
| Screen CSS | `px = twips / 15` | 1440 twips = 96px/inch; 360 twips = 24px = one indent step |
| Print CSS (PDF) | `pt = twips / 20` | 360 twips = 18pt |
| Word (OOXML) | twips passed directly | PhpWord already does this |
| Tab % on ruler / A4 | `pct = position / 9000 × 100` | A4 content width = **9000 twips** (existing `pageWidthTwips`) |

One indent level = 360 twips = 18pt = 24px (already the values used by `IndentExtension` and `app.css`). px↔twips: `twips = px × 15`.

## Non-goals (explicitly excluded)

- Full mid-line multi-tab-stop alignment in HTML/PDF (true Word tab fill needs live text measurement). We render the **leading indent / first tab** as left offset from A4 geometry (the "padding from A4" approach). Word export keeps full tab-stop fidelity via OOXML `<w:tabs>`; HTML/PDF get faithful leading indentation. Remaining tab stops are a documented limitation.
- Changing the extraction pipeline's indent inference.
- Admin-editable page geometry.

## Architecture

### A. Shared converter (single formula, reused everywhere)

Create **`resources/js/utils/layoutStyle.ts`** (frontend) and mirror the same math in the existing PHP `DocumentHtmlService`:

```ts
export const TWIPS_PER_PX = 15;
export const A4_CONTENT_TWIPS = 9000;

export function layoutToScreenStyle(layout: BlockLayout | undefined): Record<string, string> {
  // margin-left from indent_left (twips) → px; fallback indent_level × 360 twips.
  // text-indent from indent_first_line / -indent_hanging (twips) → px.
  // text-align from alignment.
  // padding-left for the first tab stop (tabs[0].position twips → px) when no indent_left.
}
```

The PHP side (`DocumentHtmlService::buildLayoutStyleAttribute`) already emits `margin-left:{pt}`; extend it to also emit the first tab stop as `padding-left` and keep px/pt consistent with the table above.

### B. Save path — TipTap indent synced into `meta.layout`

In `ReviewStore::applyDraftHtmlToBlocksInPlace` (and/or `DocumentHtmlService::buildChunksFromHtml`), for each text block:

- Read the paragraph wrapper's `margin-left` (px) — the same wrapper it already reads `text-align` from.
- Convert `px → twips` (`× 15`) and write `meta.layout.indent_left`. If `margin-left` is absent/zero, clear `indent_left` so removing indent also persists.

The ruler (`BlockRulerEditor` → `patchBlockLayout`) already writes `meta.layout`; no change needed there.

### C. Consumers read `meta.layout` via the shared converter

- **`BlockFlow.vue`** (RAG): replace its ad-hoc inline-style block with `layoutToScreenStyle(block.meta.layout)`; render leading tab as `padding-left`. Indent now reflects synced edits.
- **Result page** (`ResultPage.vue` / `/preview`): render from block-derived HTML so it reflects `meta.layout`. Since `DocumentHtmlService::buildGeneratedHtml` already emits indent styling, ensure the Result path uses generated HTML (or applies the converter) rather than a stale `draft_html`. Confirm `.doc-indent-N` CSS + inline styles survive the page's DOMPurify allowlist (it already permits `class`/`style`).
- **Word export** (`DocumentExportService::paragraphStyleForBlock`): already reads `indent_*` + `tabs`. No change beyond receiving synced data. Verify with a test.
- **PDF export** (`DocumentExportService::buildHtml` / `renderBlockHtml`): wrap each block in a `<div>`/`<p>` carrying `style="margin-left:{pt}pt"` (and `padding-left` for the leading tab) computed from `meta.layout` via the shared PHP converter; add TH Sarabun PSK `@font-face` to the export HTML `<style>`.

### D. Fonts

The export HTML and preview must load **TH Sarabun PSK** so indent/tab widths and line wrapping match Word. Add an `@font-face` (bundled font file) in `DocumentExportService::buildHtml`'s `<style>` and ensure the preview/result paper uses the same family (CSS var already defines Sarabun/PSK — reuse it).

## Files touched

- Create: `resources/js/utils/layoutStyle.ts` — shared twips→CSS converter.
- Modify: `resources/js/components/shared/BlockFlow.vue` — use the converter; render leading tab.
- Modify: `resources/js/pages/result/ResultPage.vue` (+ preview data path) — render from `meta.layout`-derived HTML.
- Modify: `app/Services/ReviewStore.php` — `applyDraftHtmlToBlocksInPlace`: sync paragraph `margin-left` → `meta.layout.indent_left`.
- Modify: `app/Services/DocumentHtmlService.php` — mirror converter; emit leading-tab padding; consistent units.
- Modify: `app/Services/DocumentExportService.php` — PDF `buildHtml` embeds indent/tabs + TH Sarabun PSK `@font-face`.
- Assets: bundle TH Sarabun PSK font for the PDF service HTML.

## Testing

- **PHP:** save `draft_html` with a paragraph `margin-left:48px` → assert `meta.layout.indent_left == 720` (48×15). Removing it clears `indent_left`.
- **PHP:** `DocumentExportService::buildHtml` output for a block with `indent_left=720` contains `margin-left:36pt` (720/20). Word `paragraphStyleForBlock` returns `indentation.left == 720`.
- **Frontend:** `layoutToScreenStyle({indent_left:720})` → `{ marginLeft: '48px' }`; `{tabs:[{position:1440}]}` with no indent → `paddingLeft` ≈ 96px.
- **Manual:** indent a paragraph in review (both toolbar and ruler), save, confirm it appears in RAG, Result, downloaded Word, and downloaded PDF.
