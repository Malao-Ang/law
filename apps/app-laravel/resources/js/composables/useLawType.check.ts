import { createLawTypeCatalog, legacyIssuerForType } from './useLawType';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`FAIL: ${msg}`);
}

const catalog = createLawTypeCatalog();

assert(catalog.typeItem('LTY01')?.title === 'ประกาศ', 'code LTY01 resolves to ประกาศ');
assert(catalog.typeItem('ประกาศที่ออกโดยมหาวิทยาลัย')?.code === 'LTY01', 'legacy university announcement resolves to LTY01');
assert(catalog.typeItem('คำสั่ง')?.code === 'LTY01', 'legacy คำสั่ง resolves to LTY01');
assert(catalog.typeItem('ประกาศที่ออกโดยสภามหาวิทยาลัย')?.code === 'LTY01', 'legacy council announcement resolves to LTY01');
assert(catalog.typeItem('มติ')?.code === 'LTY01', 'legacy มติ resolves to LTY01');
assert(catalog.typeItem('พ.ร.บ.')?.code === 'LTY05', 'พ.ร.บ. resolves to พระราชบัญญัติ');
assert(catalog.typeLabel('พ.ร.บ.') === 'พระราชบัญญัติ', 'พ.ร.บ. label is พระราชบัญญัติ');

assert(legacyIssuerForType('ประกาศที่ออกโดยมหาวิทยาลัย') === 'ISS01', 'legacy university announcement issuer is ISS01');
assert(legacyIssuerForType('คำสั่ง') === 'ISS01', 'legacy คำสั่ง issuer is ISS01');
assert(legacyIssuerForType('ประกาศที่ออกโดยสภามหาวิทยาลัย') === 'ISS02', 'legacy council announcement issuer is ISS02');
assert(legacyIssuerForType('มติ') === 'ISS02', 'legacy มติ issuer is ISS02');

assert(catalog.requiresIssuer('ประกาศ'), 'ประกาศ requires issuer');
assert(!catalog.requiresIssuer('พระราชบัญญัติ'), 'พระราชบัญญัติ does not require issuer');
assert(catalog.unitWord('ระเบียบ') === 'ข้อ', 'internal family unit is ข้อ');
assert(catalog.unitWord('คำสั่ง') === 'ข้อ', 'legacy คำสั่ง is internal and uses ข้อ');
assert(catalog.unitWord('พระราชบัญญัติ') === 'มาตรา', 'external family unit is มาตรา');
assert(catalog.typeSource('กฎหมายภายนอก') === 'external', 'legacy external law is external');
assert(catalog.issuerLabel('ISS02') === 'สภามหาวิทยาลัย', 'issuer code resolves to label');
assert(catalog.issuerRank('มหาวิทยาลัย') < catalog.issuerRank('สภามหาวิทยาลัย'), 'issuer rank follows sort_order');
assert(catalog.familiesOrdered.value.map((family) => family.code).join(',') === 'LFM01,LFM02,LFM03,LFM04', 'families ordered by sort_order');
assert(catalog.typesGroupedByFamily.value.some((group) => group.family.code === 'LFM04' && group.types.some((type) => type.code === 'LTY05')), 'external types grouped by family');

console.log('PASS: useLawType maps');
