import { cardChangeState } from './cardChangeState';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`cardChangeState check failed: ${msg}`);
}

// whole-document cancellation is already represented by use status, so no change badge
assert(cardChangeState('ปรับปรุงรายมาตรา', 'ยกเลิกการใช้งาน').variant === 'new', 'status cancel is not duplicated');
assert(cardChangeState('', 'ยกเลิกการใช้งาน').label === '', 'whole cancel label hidden');

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
