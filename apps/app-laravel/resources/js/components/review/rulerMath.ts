// Pure conversions for the review ruler. Kept browser-free so rulerMath.check.ts
// can run under tsx without pulling in @tiptap/core.
//
// ponytail: INDENT_STEP_PX (24) and MAX_INDENT (7) mirror IndentExtension.ts
// (source of truth) and the .doc-indent-N CSS. They have been 24/7 since
// inception; if IndentExtension changes them, update here too.
export const INDENT_STEP_PX = 24;
export const MAX_INDENT = 7;

const PX_PER_INCH = 96;
const MM_PER_INCH = 25.4;
const PT_PER_INCH = 72;

export const INDENT_STEP_MM = (INDENT_STEP_PX / PX_PER_INCH) * MM_PER_INCH; // 6.35

export function indentLevelToMm(level: number): number {
  return level * INDENT_STEP_MM;
}

export function mmToIndentLevel(mm: number): number {
  return Math.max(0, Math.min(MAX_INDENT, Math.round(mm / INDENT_STEP_MM)));
}

export function ptToMm(pt: number): number {
  return (pt / PT_PER_INCH) * MM_PER_INCH;
}

export function mmToPt(mm: number): number {
  return (mm / MM_PER_INCH) * PT_PER_INCH;
}

// Clamp a pointer offset (px, within the ruler track) to a position in mm along a
// content column of `contentMm` millimetres. Zoom cancels out because both
// offsetPx and trackPxWidth are measured post-transform.
export function offsetToMm(offsetPx: number, trackPxWidth: number, contentMm: number): number {
  if (trackPxWidth <= 0) return 0;
  const ratio = Math.max(0, Math.min(1, offsetPx / trackPxWidth));
  return ratio * contentMm;
}
