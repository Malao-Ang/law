import {
  INDENT_STEP_MM,
  MAX_INDENT,
  indentLevelToMm,
  mmToIndentLevel,
  ptToMm,
  mmToPt,
  offsetToMm,
} from './rulerMath';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`FAIL: ${msg}`);
}

const approx = (a: number, b: number, eps = 1e-6): boolean => Math.abs(a - b) < eps;

// indent level <-> mm round-trips for every valid level
for (let level = 0; level <= MAX_INDENT; level += 1) {
  assert(mmToIndentLevel(indentLevelToMm(level)) === level, `level ${level} round-trips`);
}

// one step is 6.35mm (24px @ 96dpi)
assert(approx(INDENT_STEP_MM, 6.35), 'INDENT_STEP_MM is 6.35');

// mmToIndentLevel snaps to nearest and clamps
assert(mmToIndentLevel(-100) === 0, 'clamps below 0');
assert(mmToIndentLevel(9999) === MAX_INDENT, 'clamps above MAX_INDENT');
assert(mmToIndentLevel(INDENT_STEP_MM * 1.4) === 1, 'snaps down to nearest level');
assert(mmToIndentLevel(INDENT_STEP_MM * 1.6) === 2, 'snaps up to nearest level');

// pt <-> mm round-trip
assert(approx(mmToPt(ptToMm(36)), 36), 'pt round-trips through mm');
assert(approx(ptToMm(72), 25.4), '72pt == 25.4mm');

// offsetToMm maps proportionally and clamps to [0, contentMm]
assert(approx(offsetToMm(50, 100, 160), 80), 'half the track is half the content');
assert(offsetToMm(-10, 100, 160) === 0, 'negative offset clamps to 0');
assert(offsetToMm(200, 100, 160) === 160, 'past-track offset clamps to contentMm');
assert(offsetToMm(50, 0, 160) === 0, 'zero-width track returns 0');

console.log('OK: all rulerMath checks passed');
