import { ref, type Ref } from 'vue';
import { paginate, type MeasuredBlock, type PageGeometry } from './paginate';

export interface Sheet { top: number; height: number }

/**
 * Paginate the review editor's ProseMirror flow onto stacked A4 sheets.
 * Splits at manual page breaks AND wherever a top-level block would overflow
 * the usable page height (auto-pagination) — the overflowing block is pushed
 * whole to the next sheet, never split.
 *
 * Measures every top-level block with offsetTop/offsetHeight (pre-transform, so
 * zoom is irrelevant), applies a margin-top to each block that starts a new
 * sheet, and collapses manual break markers to zero height. Re-apply by calling
 * `recompute()` on load, edit (debounced by the caller), resize, zoom, and
 * margin changes.
 *
 * `contentRoot` is the `.a4-page` frame; the ProseMirror element is found within.
 */
export function usePageSheets(contentRoot: Ref<HTMLElement | null>, geom: () => PageGeometry) {
  const sheets = ref<Sheet[]>([]);
  let applying = false;

  function recompute(): void {
    const root = contentRoot.value;
    if (!root || applying) return;

    const pm = root.querySelector<HTMLElement>('.ProseMirror');
    if (!pm) return;

    const children = Array.from(pm.children).filter((el): el is HTMLElement => el instanceof HTMLElement);

    // Reset previously applied spacing so we measure the natural flow.
    applying = true;
    for (const el of children) {
      el.style.marginTop = '';
      if (el.hasAttribute('data-page-break')) {
        el.style.height = '0px';
      }
    }

    // offsetTop/offsetHeight are layout-box values (unaffected by the zoom
    // transform); only their differences feed the pure geometry.
    const blocks: MeasuredBlock[] = children.map((el, index) => ({
      pos: index,
      top: el.offsetTop,
      height: el.offsetHeight,
      isBreak: el.hasAttribute('data-page-break'),
    }));
    applying = false;

    const { spacers, sheets: computed } = paginate(blocks, geom());

    const spacerByIndex = new Map(spacers.map((s) => [s.pos, s.height]));
    applying = true;
    children.forEach((el, index) => {
      const spacer = spacerByIndex.get(index);
      el.style.marginTop = spacer ? `${spacer}px` : '';
    });
    applying = false;

    sheets.value = computed;
  }

  return { sheets, recompute };
}
