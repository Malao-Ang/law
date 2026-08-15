// apps/app-laravel/resources/js/data/documentPipeline.ts
// Front-end workflow stages. Backend owns queue/processing/processed + failed;
// later workflow steps prefer server-side progress, with localStorage kept only
// as a fallback for older documents and manual admin overrides.

export type StageKey =
  | 'queue' | 'processing' | 'processed' | 'normalize' | 'rag'
  | 'relation' | 'info' | 'complete' | 'wait_esign' | 'public' | 'failed';

export type StageAction =
  | { type: 'none' }
  | { type: 'route'; label: string; to: (id: string) => string }
  | { type: 'advance'; label: string };

export interface StageDef {
  key: StageKey;
  label: string;
  color: string;
  icon: string;
  action: StageAction;
}

// Linear pipeline order (excludes the side-status 'failed').
export const STAGES: StageDef[] = [
  { key: 'queue',      label: 'รอประมวลผล',      color: 'grey',             icon: 'mdi-clock-outline',        action: { type: 'none' } },
  { key: 'processing', label: 'กำลังประมวลผล',   color: 'info',             icon: 'mdi-progress-clock',       action: { type: 'none' } },
  { key: 'processed',  label: 'ประมวลผลแล้ว',    color: 'primary',          icon: 'mdi-check-circle-outline', action: { type: 'route', label: 'เริ่มตรวจ', to: (id) => `/documents/${id}/review` } },
  { key: 'normalize',  label: 'ปรับข้อความ',      color: 'warning',          icon: 'mdi-format-text',          action: { type: 'route', label: 'แก้ไขข้อความ', to: (id) => `/documents/${id}/review` } },
  { key: 'rag',        label: 'จัดลำดับเนื้อหา', color: 'admin-primary',    icon: 'mdi-database-cog-outline', action: { type: 'route', label: 'จัดลำดับเนื้อหา', to: (id) => `/documents/${id}/rag` } },
  { key: 'info',       label: 'กรอกข้อมูลเอกสาร',  color: 'doc-kho-bangkhab', icon: 'mdi-information-outline',   action: { type: 'route', label: 'กรอกข้อมูล', to: (id) => `/documents/${id}/law-info` } },
  { key: 'relation',   label: 'เพิ่มความสัมพันธ์', color: 'doc-rabiap',       icon: 'mdi-graph-outline',        action: { type: 'route', label: 'เพิ่มความสัมพันธ์', to: (id) => `/documents/${id}/relations` } },
  { key: 'complete',   label: 'เสร็จสมบูรณ์',      color: 'success',          icon: 'mdi-check-decagram-outline', action: { type: 'route', label: 'กำหนดสิทธิ์', to: (id) => `/documents/${id}/permissions` } },
  { key: 'wait_esign', label: 'รอลงนาม',          color: 'elaw-gold',        icon: 'mdi-draw-pen',             action: { type: 'route', label: 'เปิดหน้าลงนาม', to: (id) => `/documents/${id}/esign/status` } },
  { key: 'public',     label: 'เผยแพร่แล้ว',       color: 'success',          icon: 'mdi-earth',                action: { type: 'route', label: 'ดูหน้าเผยแพร่', to: (id) => `/law/${id}` } },
];

export const FAILED_STAGE: StageDef = {
  key: 'failed', label: 'ล้มเหลว', color: 'error', icon: 'mdi-alert-circle-outline', action: { type: 'none' },
};

export const STAGE_MAP: Record<StageKey, StageDef> = {
  ...Object.fromEntries(STAGES.map((s) => [s.key, s])),
  failed: FAILED_STAGE,
} as Record<StageKey, StageDef>;

// Manual rollback cannot go below 'processed' (queue/processing are backend-owned).
const FLOOR_INDEX = STAGES.findIndex((s) => s.key === 'processed');

function indexOf(stage: StageKey): number {
  return STAGES.findIndex((s) => s.key === stage);
}

export function deriveStage(backendStatus: string): StageKey {
  switch (backendStatus) {
    case 'failed': return 'failed';
    case 'processing':
    case 'ingesting':
    case 'uploading': return 'processing';
    case 'done':
    case 'exported':
    case 'ingested': return 'processed';
    default: return 'queue'; // queued / pending / unknown
  }
}

export function deriveStageFromWorkflow(completedStep?: number | null): StageKey | null {
  switch (completedStep) {
    case 2: return 'rag';
    case 3: return 'info';
    case 4: return 'relation';
    case 5: return 'complete';
    case 6: return 'wait_esign';
    default: return null;
  }
}

// The later of two linear stages (used to merge backend-derived vs stored).
export function laterStage(a: StageKey, b: StageKey): StageKey {
  const ia = indexOf(a);
  const ib = indexOf(b);
  return ib > ia ? b : a;
}

export function nextStage(stage: StageKey): StageKey {
  const i = indexOf(stage);
  if (i < 0) return stage;
  return STAGES[Math.min(i + 1, STAGES.length - 1)].key;
}

export function prevStage(stage: StageKey): StageKey {
  const i = indexOf(stage);
  if (i < 0) return stage;
  return STAGES[Math.max(i - 1, FLOOR_INDEX)].key;
}

// ── localStorage persistence ────────────────────────────────
const STORAGE_KEY = 'lawspace.pipeline.stages';

export function readStages(): Record<string, StageKey> {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}') as Record<string, StageKey>;
  } catch {
    return {};
  }
}

export function writeStage(documentId: string, stage: StageKey): void {
  const map = readStages();
  map[documentId] = stage;
  localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
}

export function deleteStage(documentId: string): void {
  const map = readStages();
  delete map[documentId];
  localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
}
