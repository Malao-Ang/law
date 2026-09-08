// dev-only assertion — no JS test runner in this app. Run:
//   cd apps/app-laravel && npx tsx resources/js/utils/esignStatus.check.ts
import { esignStatusLabel, esignStatusColor } from './esignStatus';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error('FAIL: ' + msg);
}

// Old docs → dash, no e-sign
assert(esignStatusLabel({ document_type: 'old' }) === '–', 'old doc shows dash');
assert(esignStatusColor({ document_type: 'old' }) === 'grey', 'old doc dash is grey');

// New doc states
assert(esignStatusLabel({ esign_sign_status: 'Y' }) === 'ลงนามแล้ว', 'Y = signed');
assert(esignStatusLabel({ esign_sign_status: 'N' }) === 'ถูกปฏิเสธ', 'N = rejected');
assert(esignStatusLabel({ esign_sign_status: 'C' }) === 'ยกเลิกการส่ง', 'C = cancelled');
assert(esignStatusLabel({ esign_submitted_at: '2025-01-01T00:00:00Z' }) === 'รอลงนาม', 'submitted no code = waiting');
assert(esignStatusLabel({}) === 'ยังไม่ส่งลงนาม', 'nothing = not sent');

assert(esignStatusColor({ esign_sign_status: 'Y' }) === 'success', 'signed green');
assert(esignStatusColor({ esign_sign_status: 'N' }) === 'error', 'rejected red');
assert(esignStatusColor({ esign_sign_status: 'C' }) === 'warning', 'cancelled warning');
assert(esignStatusColor({ esign_submitted_at: 'x' }) === 'admin-primary', 'waiting admin-primary');
assert(esignStatusColor({}) === 'grey', 'not-sent grey');

console.log('esignStatus.check.ts: all passed');
