import { useLawStatus } from './useLawStatus';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`useLawStatus check failed: ${msg}`);
}

const {
  statusLabel,
  statusColor,
  statusRole,
  isDraft,
  isInForce,
  isRepealed,
  draftCode,
  inForceCode,
  repealedCode,
} = useLawStatus();

assert(inForceCode.value === 'STA01', 'in force code');
assert(repealedCode.value === 'STA02', 'repealed code');
assert(draftCode.value === 'STA03', 'draft code');

assert(statusLabel('STA01').length > 0, 'code label');
assert(statusColor('STA02') === 'error', 'code color');
assert(statusRole('STA03') === 'draft', 'code role');

assert(isInForce('STA01'), 'in force code');
assert(isRepealed('STA02'), 'repealed code');
assert(isDraft(''), 'empty status aliases to draft');
assert(isDraft(''), 'empty value is draft');
assert(statusLabel('custom') === 'custom', 'unknown label passthrough');

console.log('useLawStatus.check.ts: all passed');
