// dev-only assertion - run: cd apps/app-laravel && npx tsx resources/js/composables/useEsignStage.check.ts
import { deriveEsignStage } from './useEsignStage';
import type { DocumentStatus, LawMeta } from '../types/document';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error('FAIL: ' + msg);
}

const meta = (override: Partial<LawMeta> = {}): LawMeta => ({ ...({} as LawMeta), ...override });

assert(deriveEsignStage({} as DocumentStatus, meta()) === 'draft', 'empty = draft');
assert(deriveEsignStage({ esign_submitted_at: '2025-01-01T00:00:00Z' } as DocumentStatus, meta()) === 'waiting', 'submitted = waiting');
assert(deriveEsignStage({ esign_submitted_at: 'x', esign_sign_status: 'Y', esign_confirmed_at: 'x' } as DocumentStatus, meta()) === 'signed', 'Y = signed');
assert(deriveEsignStage({ esign_sign_status: 'Y', esign_confirmed_at: 'x' } as DocumentStatus, meta({ published_date: '2025-01-02' })) === 'published', 'published_date = published');
assert(deriveEsignStage({ esign_submitted_at: 'x', esign_sign_status: 'N' } as DocumentStatus, meta()) === 'rejected', 'N = rejected');
assert(deriveEsignStage({ esign_submitted_at: 'x', esign_sign_status: 'C' } as DocumentStatus, meta()) === 'cancelled', 'C = cancelled');
assert(deriveEsignStage({} as DocumentStatus, meta({ document_type: 'old', published_date: '2025-01-02' })) === 'published', 'old published');

console.log('useEsignStage.check.ts: all passed');
