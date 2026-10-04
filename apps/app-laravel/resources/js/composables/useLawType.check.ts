import { createLawTypeCatalog } from './useLawType';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`FAIL: ${msg}`);
}

const catalog = createLawTypeCatalog();

assert(catalog.typeItem('LTY01')?.title === 'ประกาศที่ออกโดยมหาวิทยาลัย', 'code LTY01 resolves to university announcement');
assert(catalog.typeItem('LTY09')?.title === 'ประกาศที่ออกโดยสภามหาวิทยาลัย', 'code LTY09 resolves to council announcement');
assert(catalog.typeItem('ประกาศ') === null, 'bare ประกาศ is not a type');
assert(catalog.typeFamily('ประกาศ') === 'LFM03', 'bare ประกาศ resolves to announcement family');
assert(catalog.typeLabel('ประกาศ') === 'ประกาศ (ยังไม่ระบุผู้ออก)', 'bare ประกาศ label warns about unspecified announcement source');
assert(catalog.typeItem('ประกาศที่ออกโดยมหาวิทยาลัย')?.code === 'LTY01', 'legacy university announcement resolves to LTY01');
assert(catalog.typeItem('คำสั่ง')?.code === 'LTY01', 'legacy คำสั่ง resolves to LTY01');
assert(catalog.typeItem('ประกาศที่ออกโดยสภามหาวิทยาลัย')?.code === 'LTY09', 'legacy council announcement resolves to LTY09');
assert(catalog.typeItem('มติ')?.code === 'LTY09', 'legacy มติ resolves to LTY09');
assert(catalog.typeItem('พ.ร.บ.')?.code === 'LTY05', 'พ.ร.บ. resolves to พระราชบัญญัติ');
assert(catalog.typeLabel('พ.ร.บ.') === 'พระราชบัญญัติ', 'พ.ร.บ. label is พระราชบัญญัติ');

assert(catalog.unitWord('ระเบียบ') === 'ข้อ', 'internal family unit is ข้อ');
assert(catalog.unitWord('คำสั่ง') === 'ข้อ', 'legacy คำสั่ง is internal and uses ข้อ');
assert(catalog.unitWord('พระราชบัญญัติ') === 'มาตรา', 'external family unit is มาตรา');
assert(catalog.typeSource('กฎหมายภายนอก') === 'external', 'legacy external law is external');
assert(catalog.familiesOrdered.value.map((family) => family.code).join(',') === 'LFM01,LFM02,LFM03,LFM04', 'families ordered by sort_order');
assert(catalog.typesByFamily.value.some((group) => group.family.code === 'LFM04' && group.types.some((type) => type.code === 'LTY05')), 'external types grouped by family');
assert(catalog.groupedTypeOptions.value.some((option) => option.option_type === 'subheader' && option.value === 'family:LFM03'), 'multi-type family renders a subheader');
assert(catalog.groupedTypeOptions.value.some((option) => option.option_type !== 'subheader' && option.value === 'LTY02'), 'single matching type renders as plain option');

console.log('PASS: useLawType maps');
