import { cardChangeState } from './cardChangeState';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`cardChangeState check failed: ${msg}`);
}

// cancelled by enforcement status wins over everything
assert(cardChangeState('ปรับปรุงรายมาตรา', 'ยกเลิกการใช้งาน').variant === 'cancelled', 'status cancel wins');
assert(cardChangeState('', 'ยกเลิกการใช้งาน').label === 'ยกเลิกแล้ว', 'cancelled label');

// partial cancel from change_status
assert(cardChangeState('ยกเลิกรายมาตรา', 'มีผลบังคับใช้').variant === 'partial', 'partial variant');
assert(cardChangeState('ยกเลิกรายมาตรา', '').label === 'ยกเลิกบางส่วน', 'partial label');

// revise passes the change_status through as the label
const revise = cardChangeState('ปรับปรุงรายมาตรา', 'มีผลบังคับใช้');
assert(revise.variant === 'revise', 'revise variant');
assert(revise.label === 'ปรับปรุงรายมาตรา', 'revise label passthrough');

// default / unknown → new
assert(cardChangeState('กฎหมายใหม่', 'มีผลบังคับใช้').variant === 'new', 'new default');
assert(cardChangeState('', '').variant === 'new', 'empty → new');
assert(cardChangeState(null, null).variant === 'new', 'null → new');

console.log('cardChangeState.check: all assertions passed');
