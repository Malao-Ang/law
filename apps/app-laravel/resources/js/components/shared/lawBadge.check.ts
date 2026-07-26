import { changeStatusToBadge, docTypeToBadge } from './lawBadge';

function assert(cond: boolean, msg: string): void {
  if (!cond) {
    throw new Error(`FAIL: ${msg}`);
  }
}

assert(docTypeToBadge('rabiap') === 'ระเบียบ', 'rabiap → ระเบียบ');
assert(docTypeToBadge('kho-bangkhab') === 'ข้อบังคับ', 'kho-bangkhab → ข้อบังคับ');
assert(docTypeToBadge('prakat') === 'ประกาศ', 'prakat → ประกาศ');
assert(docTypeToBadge('kotmai-krung') === 'พ.ร.บ.', 'kotmai-krung → พ.ร.บ.');
assert(docTypeToBadge('other') === null, 'other → null (no design badge)');

assert(changeStatusToBadge('new') === 'ใหม่ล่าสุด', 'new → ใหม่ล่าสุด');
assert(changeStatusToBadge('amended') === 'ปรับปรุงรายมาตรา', 'amended → ปรับปรุงรายมาตรา');
assert(changeStatusToBadge('repealed') === 'ยกเลิกแล้ว', 'repealed → ยกเลิกแล้ว');

console.log('PASS: lawBadge maps');
