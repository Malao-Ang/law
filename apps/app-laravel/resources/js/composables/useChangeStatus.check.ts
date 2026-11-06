// Dev-only assertion file — no JS test runner in this app. Run:
//   cd apps/app-laravel && npx tsx resources/js/composables/useChangeStatus.check.ts
import { createChangeStatusCatalog, type ChangeStatusCatalogInput } from './useChangeStatus';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`useChangeStatus check failed: ${msg}`);
}

// ─── Use fallback seeds (empty pools) ────────────────────────────────────────
const empty: ChangeStatusCatalogInput = { types: [], typesAll: [], details: [], detailsAll: [] };
const cat = createChangeStatusCatalog(empty);

// CHG codes
assert(cat.normalize('CHG01') === 'CHG01', 'CHG01 code roundtrip');
assert(cat.normalize('CHG02') === 'CHG02', 'CHG02 code roundtrip');
assert(cat.normalize('CHG03') === 'CHG03', 'CHG03 code roundtrip');
assert(cat.normalize('CHG04') === 'CHG04', 'CHG04 code roundtrip');

// Thai name resolution
assert(cat.normalize('กฎหมายใหม่') === 'CHG01', 'Thai name CHG01');
assert(cat.normalize('ปรับปรุงทั้งฉบับ') === 'CHG02', 'Thai name CHG02');
assert(cat.normalize('ปรับปรุงรายข้อ') === 'CHG03', 'Thai name CHG03');
assert(cat.normalize('ปรับปรุงรายมาตรา') === 'CHG04', 'Thai name CHG04');

// Legacy aliases
assert(cat.normalize('ยกเลิกทั้งฉบับ') === 'CHG02', 'legacy ยกเลิกทั้งฉบับ → CHG02');
assert(cat.normalize('ยกเลิกรายมาตรา') === 'CHG04', 'legacy ยกเลิกรายมาตรา → CHG04');
assert(cat.normalize('กฎหมายล่าสุด') === 'CHG01', 'legacy กฎหมายล่าสุด → CHG01');

// Roles
assert(cat.role('CHG01') === 'new', 'CHG01 role = new');
assert(cat.role('CHG02') === 'whole', 'CHG02 role = whole');
assert(cat.role('CHG03') === 'section', 'CHG03 role = section');
assert(cat.role('CHG04') === 'section', 'CHG04 role = section');

// Role helpers
assert(cat.isNew('CHG01'), 'isNew CHG01');
assert(cat.isNew('กฎหมายใหม่'), 'isNew Thai name');
assert(cat.isWhole('CHG02'), 'isWhole CHG02');
assert(cat.isWhole('ปรับปรุงทั้งฉบับ'), 'isWhole Thai name');
assert(cat.isWhole('ยกเลิกทั้งฉบับ'), 'isWhole legacy alias');
assert(cat.isSection('CHG03'), 'isSection CHG03');
assert(cat.isSection('CHG04'), 'isSection CHG04');
assert(cat.isSection('ปรับปรุงรายข้อ'), 'isSection Thai name');
assert(cat.isSection('ยกเลิกรายมาตรา'), 'isSection legacy alias');

// hasDetails
assert(!cat.hasDetails('CHG01'), 'CHG01 no details');
assert(!cat.hasDetails('CHG02'), 'CHG02 no details');
assert(cat.hasDetails('CHG03'), 'CHG03 has details');
assert(cat.hasDetails('CHG04'), 'CHG04 has details');

// label passthrough for unknown
assert(cat.label('UNKNOWN') === 'UNKNOWN', 'unknown label passthrough');
assert(cat.label('') === '', 'empty label passthrough');
assert(cat.role('UNKNOWN') === null, 'unknown role = null');

// CHD codes
assert(cat.normalizeDetail('CHD01') === 'CHD01', 'CHD01 code roundtrip');
assert(cat.normalizeDetail('CHD02') === 'CHD02', 'CHD02 code roundtrip');
assert(cat.normalizeDetail('CHD03') === 'CHD03', 'CHD03 code roundtrip');
assert(cat.normalizeDetail('CHD04') === 'CHD04', 'CHD04 code roundtrip');

// Thai name resolution
assert(cat.normalizeDetail('ยกเลิกข้อ') === 'CHD01', 'Thai name CHD01');
assert(cat.normalizeDetail('ยกเลิกมาตรา') === 'CHD02', 'Thai name CHD02');
assert(cat.normalizeDetail('เพิ่มข้อความ') === 'CHD03', 'Thai name CHD03');
assert(cat.normalizeDetail('แก้ไขข้อความ') === 'CHD04', 'Thai name CHD04');

// Legacy short aliases
assert(cat.normalizeDetail('ยกเลิก') === 'CHD01', 'legacy ยกเลิก → CHD01');
assert(cat.normalizeDetail('เพิ่ม') === 'CHD03', 'legacy เพิ่ม → CHD03');
assert(cat.normalizeDetail('แก้ไข') === 'CHD04', 'legacy แก้ไข → CHD04');

// Detail roles
assert(cat.detailRole('CHD01') === 'repeals', 'CHD01 role = repeals');
assert(cat.detailRole('CHD02') === 'repeals', 'CHD02 role = repeals');
assert(cat.detailRole('CHD03') === 'amends', 'CHD03 role = amends');
assert(cat.detailRole('CHD04') === 'amends', 'CHD04 role = amends');
assert(cat.detailRole('ยกเลิกข้อ') === 'repeals', 'Thai name CHD01 role = repeals');
assert(cat.detailRole('เพิ่ม') === 'amends', 'legacy alias CHD03 role = amends');

// detailMeta
const meta01 = cat.detailMeta('CHD01');
assert(meta01?.color === 'error', 'CHD01 color = error');
assert(meta01?.icon === 'mdi-cancel', 'CHD01 icon = mdi-cancel');
const meta03 = cat.detailMeta('CHD03');
assert(meta03?.color === 'success', 'CHD03 color = success');
assert(meta03?.icon === 'mdi-plus', 'CHD03 icon = mdi-plus');
assert(cat.detailMeta('UNKNOWN') === null, 'unknown detail meta = null');

// detailLabel passthrough
assert(cat.detailLabel('UNKNOWN') === 'UNKNOWN', 'unknown detail label passthrough');

// ─── With live data injected ──────────────────────────────────────────────────
const injected = createChangeStatusCatalog({
  types: [
    { title: 'กฎหมายใหม่', value: 'CHG01', code: 'CHG01', source: 'both', has_details: false, role: 'new' },
    { title: 'ปรับปรุงทั้งฉบับ', value: 'CHG02', code: 'CHG02', source: 'both', has_details: false, role: 'whole' },
  ],
  typesAll: [
    { title: 'กฎหมายใหม่', value: 'CHG01', code: 'CHG01', source: 'both', has_details: false, role: 'new' },
    { title: 'ปรับปรุงทั้งฉบับ', value: 'CHG02', code: 'CHG02', source: 'both', has_details: false, role: 'whole' },
    { title: 'ปรับปรุงรายข้อ', value: 'CHG03', code: 'CHG03', source: 'internal', has_details: true, role: 'section' },
    { title: 'ปรับปรุงรายมาตรา', value: 'CHG04', code: 'CHG04', source: 'external', has_details: true, role: 'section' },
  ],
  details: [
    { title: 'ยกเลิกข้อ', value: 'CHD01', code: 'CHD01', source: 'internal', role: 'repeals', color: 'error', icon: 'mdi-cancel' },
    { title: 'เพิ่มข้อความ', value: 'CHD03', code: 'CHD03', source: 'both', role: 'amends', color: 'success', icon: 'mdi-plus' },
  ],
  detailsAll: [
    { title: 'ยกเลิกข้อ', value: 'CHD01', code: 'CHD01', source: 'internal', role: 'repeals', color: 'error', icon: 'mdi-cancel' },
    { title: 'ยกเลิกมาตรา', value: 'CHD02', code: 'CHD02', source: 'external', role: 'repeals', color: 'error', icon: 'mdi-cancel' },
    { title: 'เพิ่มข้อความ', value: 'CHD03', code: 'CHD03', source: 'both', role: 'amends', color: 'success', icon: 'mdi-plus' },
    { title: 'แก้ไขข้อความ', value: 'CHD04', code: 'CHD04', source: 'both', role: 'amends', color: 'teal', icon: 'mdi-pencil' },
  ],
});
assert(injected.normalize('CHG01') === 'CHG01', 'injected CHG01');
assert(injected.isWhole('CHG02'), 'injected isWhole CHG02');
assert(injected.isSection('CHG03'), 'injected isSection CHG03 (from typesAll)');
assert(injected.detailRole('CHD02') === 'repeals', 'injected CHD02 (from detailsAll)');
assert(injected.typeOptions('internal').some((o) => o.code === 'CHG01'), 'typeOptions both shows for internal');
assert(injected.typeOptions('external').every((o) => o.source !== 'internal'), 'typeOptions external excludes internal-only');

console.log('useChangeStatus.check.ts: all passed');
