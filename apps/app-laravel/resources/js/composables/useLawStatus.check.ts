import { useLawStatus } from './useLawStatus';
import { useLookups } from './useLookups';

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

// Admin-added status (not in the fallback list) resolves once /api/lookups data is present.
const lookups = useLookups();
lookups.statusesAll.value = [
  { title: 'มีผลบังคับใช้', value: 'STA01', code: 'STA01', color: 'success', role: 'in_force' },
  { title: 'ยกเลิกการใช้งาน', value: 'STA02', code: 'STA02', color: 'error', role: 'repealed' },
  { title: 'ร่าง', value: 'STA03', code: 'STA03', color: 'grey', role: 'draft' },
  { title: 'ล่าสุด', value: 'STA04', code: 'STA04', color: null, role: null },
];
assert(statusLabel('STA04') === 'ล่าสุด', 'admin-added status shows its name');
assert(statusRole('STA04') === null, 'admin-added status keeps its own role');

console.log('useLawStatus.check.ts: all passed');
