// Pure pagination geometry for the review editor. Given the measured top-level
// blocks of the ProseMirror flow (spacers reset to 0, break nodes collapsed to
// 0), decide where A4 page boundaries fall — at manual page breaks AND wherever
// a block would overflow the usable page height (auto-pagination). Never splits
// a block: a block that would cross the boundary is pushed whole to the next
// sheet.
//
// All inputs/outputs are unscaled layout px (offsetTop/offsetHeight are
// pre-transform, so zoom does not affect the math). Sheets are uniform A4; a
// single block taller than one page overflows its sheet (documented edge case).

export interface PageGeometry {
  /** Usable content height of one A4 page (297mm − top − bottom margins), px. */
  usableHeight: number;
  /** Top page margin, px. */
  topMargin: number;
  /** Bottom page margin, px. */
  bottomMargin: number;
  /** Gray gap drawn between two stacked sheets, px. */
  gap: number;
}

export interface MeasuredBlock {
  /** ProseMirror doc position of the node (for decoration mapping). */
  pos: number;
  /** offsetTop of the block within the content flow, px. */
  top: number;
  /** offsetHeight of the block, px. */
  height: number;
  /** True when this block IS a manual page-break node. */
  isBreak: boolean;
}

export interface PaginationResult {
  /** margin-top (px) to apply BEFORE the block at this doc position. */
  spacers: { pos: number; height: number }[];
  /** Backdrop sheet rectangles in stage coordinates (px, unscaled). */
  sheets: { top: number; height: number }[];
}

export function paginate(blocks: MeasuredBlock[], geom: PageGeometry): PaginationResult {
  const { usableHeight, topMargin, bottomMargin, gap } = geom;
  const a4 = usableHeight + topMargin + bottomMargin;
  const stride = a4 + gap;
  const spacers: { pos: number; height: number }[] = [];

  const hasContent = blocks.some((b) => !b.isBreak);
  if (!hasContent) {
    return { spacers, sheets: [{ top: 0, height: a4 }] };
  }

  let pageStartTop = 0;
  let pageIndex = 0;
  let pendingBreak = false;
  let placedFirst = false;

  for (const block of blocks) {
    if (block.isBreak) {
      pendingBreak = true; // the break itself collapses; the NEXT block starts a page
      continue;
    }

    if (!placedFirst) {
      placedFirst = true;
      pageStartTop = block.top;
      pendingBreak = false;
      continue;
    }

    const overflow = block.top + block.height - pageStartTop > usableHeight;
    if (pendingBreak || overflow) {
      const usedOnPage = block.top - pageStartTop; // natural content height before this block
      const spacer = (usableHeight - usedOnPage) + bottomMargin + gap + topMargin;
      spacers.push({ pos: block.pos, height: Math.max(0, spacer) });
      pageIndex += 1;
      pageStartTop = block.top;
    }

    pendingBreak = false;
  }

  const sheets: { top: number; height: number }[] = [];
  for (let i = 0; i <= pageIndex; i += 1) {
    sheets.push({ top: i * stride, height: a4 });
  }

  return { spacers, sheets };
}
