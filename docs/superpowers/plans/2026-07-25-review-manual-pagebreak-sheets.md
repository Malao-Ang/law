# Review: Manual Page-Break → Real A4 Sheets — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On the `/review` editor, clicking "split page" (insert page break) makes the following content start on a fresh **A4 sheet** — the page before the break is padded to full A4 height, and a gray gap separates the two white sheets (Google-Docs style). The old "แบ่งหน้า" marker text is removed.

**Architecture:** Implements the *manual-break* half of the approved spec `docs/superpowers/specs/2026-07-25-review-multi-page-pagination-design.md` (auto/soft pagination is a later follow-up). Content stays ONE ProseMirror flow inside a transparent stage. A measured backdrop renders one white A4 `.page-sheet` card per break-delimited group; a spacer injected into each `data-page-break` element pads the ending page to full height + gap + next page's top margin, so text visually lands inside each sheet. A pure function does the geometry; a composable does the measuring; no content mutation, no persisted changes.

**Tech Stack:** Vue 3 + Vuetify 3 + TypeScript, TipTap/ProseMirror.

**Verification:** the frontend has no JS test runner (only `npm run typecheck` + `npm run build`). The geometry is isolated in a pure function verified by typecheck + a documented worked example; the DOM/measuring is verified manually. (Do not add vitest — YAGNI for this repo.)

---

### Task 1: Pure pagination geometry (`paginateManual.ts`)

**Files:**
- Create: `apps/app-laravel/resources/js/pagination/paginateManual.ts`

- [ ] **Step 1: Write the pure function**

```ts
export interface PageGeometry {
  /** Usable content height of one A4 page (297mm − top − bottom margins), in px. */
  usableHeight: number;
  /** Top page margin, px. */
  topMargin: number;
  /** Bottom page margin, px. */
  bottomMargin: number;
  /** Gray gap shown between two stacked sheets, px. */
  gap: number;
}

export interface PaginationResult {
  /** Height (px) to apply to the i-th page-break element so page i fills to A4. */
  breakSpacerHeights: number[];
  /** Backdrop sheet rectangles in stage coordinates (px, unscaled). */
  sheets: { top: number; height: number }[];
}

/**
 * Lay content groups (split by manual page breaks) onto stacked A4 sheets.
 * `groupContentHeights[i]` = natural content height (px) of the i-th group,
 * i.e. the run of blocks between break i-1 and break i. length = breaks + 1.
 *
 * Each page is at least one A4 tall; a group taller than a page grows its sheet.
 */
export function paginateManual(
  groupContentHeights: number[],
  geom: PageGeometry,
): PaginationResult {
  const { usableHeight, topMargin, bottomMargin, gap } = geom;
  const sheets: { top: number; height: number }[] = [];
  const breakSpacerHeights: number[] = [];

  let top = 0;
  for (let i = 0; i < groupContentHeights.length; i += 1) {
    const contentH = Math.max(0, groupContentHeights[i]);
    const pageContentH = Math.max(usableHeight, contentH); // fill to A4 (or grow)
    const sheetHeight = pageContentH + topMargin + bottomMargin;
    sheets.push({ top, height: sheetHeight });

    // The break AFTER this group pads the page to full height, then adds the
    // visible gap + the next page's top margin, so the next group starts inside
    // the next sheet's content area. No spacer after the final group.
    if (i < groupContentHeights.length - 1) {
      breakSpacerHeights.push((pageContentH - contentH) + bottomMargin + gap + topMargin);
    }
    top += sheetHeight + gap;
  }

  return { breakSpacerHeights, sheets };
}
```

- [ ] **Step 2: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS.

- [ ] **Step 3: Sanity-check the geometry (worked example, no runner)**

Confirm by hand against this example (put it in the file as a comment so future readers can re-verify):

```
// Example: usableHeight=1000, topMargin=100, bottomMargin=100, gap=40.
// Two groups: [400px, 1200px].
//  page 0: pageContentH=max(1000,400)=1000; sheet0={top:0, height:1200}.
//          break0 spacer = (1000-400)+100+40+100 = 840.
//  page 1: pageContentH=max(1000,1200)=1200; sheet1={top:1200+40=1240, height:1400}.
//  => sheets:[{0,1200},{1240,1400}], breakSpacerHeights:[840].
```

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/pagination/paginateManual.ts
git commit -m "feat(review): pure A4 pagination geometry for manual breaks"
```

---

### Task 2: Drop the "แบ่งหน้า" marker text

**Files:**
- Modify: `apps/app-laravel/resources/js/extensions/PageBreakExtension.ts:20-22`

- [ ] **Step 1: Render an empty break node**

Replace `renderHTML()` (lines 20-22):

```ts
  renderHTML() {
    return ['div', { 'data-page-break': '', class: 'doc-page-gap', style: 'page-break-after: always' }];
  },
```

(Removes the literal `'แบ่งหน้า'` text child. The visible gap now comes from the measured spacer + backdrop.)

- [ ] **Step 2: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/extensions/PageBreakExtension.ts
git commit -m "feat(review): page break renders as gap, not marker text"
```

---

### Task 3: Measuring composable (`usePageSheets.ts`)

**Files:**
- Create: `apps/app-laravel/resources/js/pagination/usePageSheets.ts`

- [ ] **Step 1: Write the composable**

```ts
import { ref, type Ref } from 'vue';
import { paginateManual, type PageGeometry } from './paginateManual';

export interface Sheet { top: number; height: number }

/**
 * Measures manual page breaks inside a ProseMirror content root and produces
 * A4 sheet rectangles + injects spacer heights onto each break element.
 * Call `recompute()` on load, edit (debounced by the caller), resize, zoom,
 * and margin changes. `geom()` returns the current page geometry in px.
 */
export function usePageSheets(contentRoot: Ref<HTMLElement | null>, geom: () => PageGeometry) {
  const sheets = ref<Sheet[]>([]);
  let applying = false;

  function recompute(): void {
    const root = contentRoot.value;
    if (!root || applying) return;

    const breaks = Array.from(root.querySelectorAll<HTMLElement>('[data-page-break]'));

    // Measure natural layout: zero the spacers first so offsets reflect content.
    applying = true;
    breaks.forEach((b) => { b.style.height = '0px'; });
    // Force reflow read.
    const rootTop = root.getBoundingClientRect().top;
    const breakTops = breaks.map((b) => b.getBoundingClientRect().top - rootTop);
    const contentHeight = root.scrollHeight;
    applying = false;

    // Group content heights = spans between successive break tops.
    const groupHeights: number[] = [];
    let prev = 0;
    for (const t of breakTops) {
      groupHeights.push(Math.max(0, t - prev));
      prev = t;
    }
    groupHeights.push(Math.max(0, contentHeight - prev));

    const { breakSpacerHeights, sheets: computed } = paginateManual(groupHeights, geom());

    // Apply spacer heights to the break elements.
    applying = true;
    breaks.forEach((b, i) => { b.style.height = `${breakSpacerHeights[i] ?? 0}px`; });
    applying = false;

    sheets.value = computed;
  }

  return { sheets, recompute };
}
```

- [ ] **Step 2: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/pagination/usePageSheets.ts
git commit -m "feat(review): measuring composable for manual page sheets"
```

---

### Task 4: Wire the backdrop into `DocumentEditorShell`

**Files:**
- Modify: `apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue`

- [ ] **Step 1: Render the sheet backdrop behind the content**

In the template, replace the page-frame block (lines 179-181):

```vue
      <div class="editor-stage" :style="editorStageStyle">
        <div ref="pageFrameRef" class="a4-page" :style="pageFrameStyle">
          <EditorContent v-if="editor" :editor="editor" class="editor-shell-content" />
        </div>
      </div>
```

with:

```vue
      <div class="editor-stage" :style="editorStageStyle">
        <div ref="pageFrameRef" class="a4-page" :style="pageFrameStyle">
          <div class="page-backdrop" aria-hidden="true">
            <div
              v-for="(sheet, i) in pageSheets.sheets.value"
              :key="i"
              class="page-sheet"
              :style="{ top: `${sheet.top}px`, height: `${sheet.height}px` }"
            />
          </div>
          <EditorContent v-if="editor" :editor="editor" class="editor-shell-content" />
        </div>
      </div>
```

- [ ] **Step 2: Instantiate the composable and recompute on the existing triggers**

In `<script setup>`, add the import near the other imports:

```ts
import { usePageSheets } from '../../pagination/usePageSheets';
```

After `const pageMargins = ...` (and `MM_TO_CSS_PX` is already defined), add:

```ts
const INTER_PAGE_GAP_PX = 24;

const pageSheets = usePageSheets(pageFrameRef, () => {
  const mm = (twips: number) => twipsToMm(twips) * MM_TO_CSS_PX;
  return {
    usableHeight: (PAGE_MIN_HEIGHT_MM * MM_TO_CSS_PX) - mm(pageMargins.value.top) - mm(pageMargins.value.bottom),
    topMargin: mm(pageMargins.value.top),
    bottomMargin: mm(pageMargins.value.bottom),
    gap: INTER_PAGE_GAP_PX,
  };
});
```

Then call `pageSheets.recompute()` wherever `refreshPageHeight()` is already called — the editor `onCreate`, `onUpdate`, the ResizeObserver, `setZoom`, and margin-change handlers. Concretely, change `refreshPageHeight` so the sheet recompute rides along:

```ts
function refreshPageHeight(): void {
  if (!pageFrameRef.value) return;
  pageSheets.recompute();
  pageHeightPx.value = Math.max(pageFrameRef.value.offsetHeight, PAGE_MIN_HEIGHT_MM * MM_TO_CSS_PX);
}
```

(Every existing caller of `refreshPageHeight()` — onCreate, onUpdate, ResizeObserver, mount — now also repaginates. `pageHeightPx` reading `offsetHeight` after spacers are applied keeps the stage tall enough for all sheets.)

- [ ] **Step 3: Styles — transparent paper, backdrop sheets, gap element**

In `<style scoped>`: make `.a4-page` a transparent positioning context (the white paper now comes from `.page-sheet`), and add the backdrop styles. Replace the `.a4-page` rule (around line 851) so its `background`, `box-shadow`, and `min-height` move to `.page-sheet`:

```css
.a4-page {
  --page-margin-top: 25.4mm;
  --page-margin-bottom: 25.4mm;
  --page-margin-left: 31.75mm;
  --page-margin-right: 31.75mm;
  width: 210mm;
  min-height: 297mm;
  padding: var(--page-margin-top) var(--page-margin-right) var(--page-margin-bottom) var(--page-margin-left);
  background: transparent;
  box-sizing: border-box;
  transform-origin: top center;
  position: relative;
}
.page-backdrop {
  position: absolute;
  inset: 0;
  z-index: 0;
  pointer-events: none;
}
.page-sheet {
  position: absolute;
  left: 0;
  width: 210mm;
  background: #fff;
  box-shadow: 0 0 0 1px #e2e8f0, 0 10px 30px rgba(15, 23, 42, 0.08);
  border-radius: 2px;
}
.editor-shell-content { position: relative; z-index: 1; }
.doc-page-gap { display: block; }
```

- [ ] **Step 4: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue
git commit -m "feat(review): stacked A4 sheet backdrop driven by manual breaks"
```

---

### Task 5: Verify

**Files:** none (verification only)

- [ ] **Step 1: Manual check**

Open a document in `/review`. Confirm:
- With no page break: one A4 sheet, unchanged.
- Click the page-break (split) toolbar button mid-document: the content after the break jumps to a **second white A4 sheet**, the first sheet is padded to full A4 height, and a gray gap separates them. No "แบ่งหน้า" text appears.
- Typing above the break repaginates (the second sheet shifts) after the edit settles.
- Changing margins / zoom keeps the sheets aligned with the text.
- Export to PDF (existing) still starts a new page at the break — the export already honors `data-page-break`, unaffected by this visual change.

- [ ] **Step 2: Tune the gap if needed**

`INTER_PAGE_GAP_PX = 24` and the box-shadow are visual calibration knobs; adjust to taste during the check. Commit any tweak:

```bash
git add -A && git commit -m "fix(review): tune page gap after manual check"
```

---

## Self-Review

- **Spec coverage:** click-split → new A4 sheet (Tasks 3-4), page padded to full height + gap (Task 1 geometry, Task 4 backdrop), no marker text (Task 2), repaginates on edit/zoom/margin (Task 4 Step 2). ✓
- **Placeholders:** none — pure function, composable, and DOM edits given in full; the one worked example is a verification aid, not a gap. ✓
- **Type consistency:** `PageGeometry`/`PaginationResult`/`paginateManual` defined in Task 1 and consumed in Task 3; `usePageSheets`/`pageSheets.sheets`/`recompute` defined in Task 3 and used in Task 4. `twipsToMm`, `MM_TO_CSS_PX`, `PAGE_MIN_HEIGHT_MM`, `pageFrameRef`, `refreshPageHeight` are pre-existing. ✓
- **Scope:** manual breaks only; auto/soft pagination deferred to the full spec. No content mutation, export path untouched. ✓
