import type { BlockLayout } from '../types/document';

export const TWIPS_PER_PX = 15;
export const A4_CONTENT_TWIPS = 9000;
const INDENT_LEVEL_TWIPS = 360;

function formatPx(value: number): string {
  const rounded = Math.round(value * 100) / 100;

  return `${Number.isInteger(rounded) ? rounded : rounded}px`;
}

function firstTabPosition(layout: BlockLayout | undefined): number | null {
  const position = layout?.tabs?.[0]?.position;

  return typeof position === 'number' && position > 0 ? position : null;
}

export function shouldUseLeadingTabPadding(layout: BlockLayout | undefined): boolean {
  return !(
    typeof layout?.indent_left === 'number'
    && layout.indent_left > 0
  ) && firstTabPosition(layout) !== null;
}

export function layoutToScreenStyle(layout: BlockLayout | undefined): Record<string, string> {
  const nextStyle: Record<string, string> = {};

  if (typeof layout?.indent_left === 'number' && layout.indent_left > 0) {
    nextStyle.marginLeft = formatPx(layout.indent_left / TWIPS_PER_PX);
  } else if (typeof layout?.indent_level === 'number' && layout.indent_level > 0) {
    nextStyle.marginLeft = formatPx((layout.indent_level * INDENT_LEVEL_TWIPS) / TWIPS_PER_PX);
  }

  if (typeof layout?.indent_first_line === 'number' && layout.indent_first_line !== 0) {
    nextStyle.textIndent = formatPx(layout.indent_first_line / TWIPS_PER_PX);
  } else if (typeof layout?.indent_hanging === 'number' && layout.indent_hanging > 0) {
    nextStyle.textIndent = formatPx(-(layout.indent_hanging / TWIPS_PER_PX));
  }

  if (typeof layout?.alignment === 'string') {
    nextStyle.textAlign = layout.alignment;
  }

  const firstTab = firstTabPosition(layout);
  if (firstTab !== null && shouldUseLeadingTabPadding(layout)) {
    nextStyle.paddingLeft = formatPx(firstTab / TWIPS_PER_PX);
  }

  return nextStyle;
}
