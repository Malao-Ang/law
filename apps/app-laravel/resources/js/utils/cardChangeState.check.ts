import { cardChangeState } from './cardChangeState';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`cardChangeState check failed: ${msg}`);
}

// whole-document cancellation is already represented by use status, so no change badge
assert(cardChangeState('ปรับปรุงรายมาตรา', 'STA02').variant === 'new', 'status cancel is not duplicated');
assert(cardChangeState('', 'STA02').label === '', 'whole cancel label hidden');

// partial cancel from change_status
assert(cardChangeState('ยกเลิกรายมาตรา', 'STA01').variant === 'partial', 'partial variant');
assert(cardChangeState('ยกเลิกรายมาตรา', '').label === 'ยกเลิกบางส่วน', 'partial label');

// revise passes the change_status through as the label
const revise = cardChangeState('ปรับปรุงรายมาตรา', 'STA01');
assert(revise.variant === 'revise', 'revise variant');
assert(revise.label === 'ปรับปรุงรายมาตรา', 'revise label passthrough');

// default / unknown → new
assert(cardChangeState('กฎหมายใหม่', 'STA01').variant === 'new', 'new default');
assert(cardChangeState('', '').variant === 'new', 'empty → new');
assert(cardChangeState(null, null).variant === 'new', 'null → new');

// codes behave like the Thai names
assert(cardChangeState('CHG04', 'STA01').variant === 'revise', 'CHG04 code → revise');
assert(cardChangeState('CHG04', 'STA01').label === 'ปรับปรุงรายมาตรา', 'CHG04 code shows Thai label');
assert(cardChangeState('CHG02', 'STA01').label === 'ปรับปรุงทั้งฉบับ', 'CHG02 code shows Thai label');
assert(cardChangeState('CHG01', 'STA01').variant === 'new', 'CHG01 code → new');

console.log('cardChangeState.check: all assertions passed');
