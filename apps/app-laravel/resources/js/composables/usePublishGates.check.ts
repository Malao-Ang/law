// dev-only assertion — no JS test runner in this app. Run:
//   cd apps/app-laravel && npx tsx resources/js/composables/usePublishGates.check.ts
import { evaluatePublishGates } from './usePublishGates';
import type { DocumentStatus, LawMeta } from '../types/document';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error('FAIL: ' + msg);
}

const fullMeta: LawMeta = {
  title: 'ประกาศทดสอบ',
  law_type: 'ประกาศ',
  promulgation_date: '2025-01-01',
  access_scope: 'public',
  status: 'มีผลบังคับใช้',
} as LawMeta;

// New doc, e-sign submitted + confirmed_at set, but sign_status NOT 'Y' → must FAIL e-sign gate.
const forged: DocumentStatus = {
  status: 'ingested',
  esign_submitted_at: '2025-01-01T00:00:00Z',
  esign_confirmed_at: '2025-01-01T00:00:00Z',
  esign_sign_status: null,
  workflow_completed_step: 3,
} as DocumentStatus;

const forgedResult = evaluatePublishGates(fullMeta, forged, [], false);
const forgedEsign = forgedResult.gates.find((g) => g.key === 'esign');
assert(forgedEsign?.ok === false, 'e-sign gate must FAIL when sign_status !== Y even if confirmed_at is set');
assert(forgedResult.hasRequiredFail === true, 'forged confirmed_at must NOT allow publish');

// New doc properly signed (Y) → e-sign gate passes.
const signed: DocumentStatus = {
  status: 'ingested',
  esign_submitted_at: '2025-01-01T00:00:00Z',
  esign_confirmed_at: '2025-01-01T00:00:00Z',
  esign_sign_status: 'Y',
  workflow_completed_step: 3,
} as DocumentStatus;
const signedEsign = evaluatePublishGates(fullMeta, signed, [], false).gates.find((g) => g.key === 'esign');
assert(signedEsign?.ok === true, 'e-sign gate passes when sign_status === Y');

// Old doc → e-sign gate is skipped entirely (not present).
const oldResult = evaluatePublishGates(fullMeta, { status: 'ingested' } as DocumentStatus, [], true);
assert(oldResult.gates.find((g) => g.key === 'esign') === undefined, 'old docs skip the e-sign gate');
assert(oldResult.canPublish === true, 'old doc with full metadata can publish without e-sign');

console.log('usePublishGates.check.ts: all passed');
