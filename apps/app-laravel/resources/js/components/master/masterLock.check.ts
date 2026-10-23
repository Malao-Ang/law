import { isLocked, lockAriaLabel, lockReason, toggleText, usageLink } from './masterLock';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`FAIL: ${msg}`);
}

const item = (over: Partial<{ is_system: boolean; is_active: boolean; usage_count: number }> = {}) => ({
  is_system: false,
  is_active: true,
  usage_count: 0,
  ...over,
});

// locked only when active and (system or in use)
assert(!isLocked(item(), 'law_category'), 'unused active row is not locked');
assert(!isLocked(item({ usage_count: 15 }), 'law_category'), 'in-use category is locked');
assert(!isLocked(item({ usage_count: 15, is_active: false }), 'law_category'), 'inactive row is never locked');
assert(!isLocked(item({ is_system: true, is_active: false }), 'enforcement_status'), 'inactive system row is not locked');
assert(!isLocked(item({ is_system: true }), 'enforcement_status'), 'system row is locked');
assert(!isLocked(item({ usage_count: 9 }), 'enforcement_status'), 'usage does not lock enforcement_status');
assert(!isLocked(item({ usage_count: 9 }), 'change_status'), 'usage does not lock change_status');

// reason + hint per kind
const cat = lockReason(item({ usage_count: 15 }), 'law_category');
assert(cat === null, 'category reason');
assert(cat === null, 'category hint');
assert(lockReason(item({ usage_count: 2 }), 'law_type') === null, 'type hint');
assert(lockReason(item({ usage_count: 2 }), 'law_family') === null, 'family reason');
assert(lockReason(item({ usage_count: 2 }), 'law_family') === null, 'family hint');
assert(lockReason(item({ usage_count: 3 }), 'legal_structure') === null, 'structure reason uses ฉบับ');
assert(lockReason(item({ usage_count: 3 }), 'legal_structure') === null, 'structure hint');
assert(lockReason(item({ usage_count: 1234 }), 'law_category') === null, 'count is th-TH formatted');

// system wins over usage
const sys = lockReason(item({ is_system: true, usage_count: 5 }), 'law_family');
assert(sys === null, 'system row text');
assert(lockReason(item(), 'law_category') === null, 'no lock text when unlocked');

// plain toggle labels
assert(toggleText(item(), 'law_category').reason === 'ปิดใช้งาน', 'unlocked active label');
assert(toggleText(item({ is_active: false }), 'law_category').reason === 'เปิดใช้งาน', 'inactive label');
assert(toggleText(item({ is_active: false, usage_count: 4 }), 'law_category').reason === 'เปิดใช้งาน', 'inactive in-use label');

// aria
assert(
  lockAriaLabel(item({ usage_count: 15 }), 'law_category') === 'ใช้งานอยู่ — ปิดใช้งาน',
  'aria label for locked row',
);

// usage links
assert(usageLink('law_category', 'DCT003', 1) === '/admin/laws?category=DCT003', 'category link');
assert(usageLink('law_type', 'LTY02', 4) === '/admin/laws?type=LTY02', 'type link');
assert(usageLink('law_category', 'DCT001', 0) === null, 'zero count is not a link');
assert(usageLink('law_category', 'DCT001', undefined) === null, 'missing count is not a link');
assert(usageLink('legal_structure', 'LST004', 3) === null, 'legal structure has no filter');
assert(usageLink('law_family', 'LFM01', 3) === null, 'family has no link');

console.log('masterLock.check OK');
