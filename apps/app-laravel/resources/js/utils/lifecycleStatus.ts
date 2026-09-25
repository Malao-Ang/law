// apps/app-laravel/resources/js/utils/lifecycleStatus.ts
// Folds the publish + e-sign + pipeline-stage axes into a single readable
// "ความคืบหน้า" chip. Pure function — display-only, reads existing fields.
import { STAGE_MAP, type StageKey } from '../data/documentPipeline';
import type { DocumentListItem } from '../types/document';

export type LifecycleKey =
  | 'failed' | 'rejected' | 'cancelled' | 'published' | 'signed' | 'waiting' | 'draft';

export type LifecycleBucket =
  | 'failed' | 'rejected_cancelled' | 'published' | 'signed' | 'waiting' | 'draft';

export interface LifecycleStatus {
  key: LifecycleKey;
  bucket: LifecycleBucket; // used by the table filter
  label: string;           // draft includes "ร่าง · <phase>"
  color: string;           // vuetify color token
  icon: string;            // mdi icon
}

// Collapse the 11-stage StageKey into 4 readable draft phases.
// Exhaustive switch (no default) — TS errors here if a StageKey is ever added.
function draftPhaseLabel(stage: StageKey, isOld: boolean): string {
  switch (stage) {
    case 'queue':
    case 'processing':
    case 'processed':
    case 'normalize':
      return 'กำลังประมวลผล';
    case 'rag':
      return 'จัดเนื้อหา';
    case 'info':
    case 'relation':
      return 'กรอกข้อมูล';
    case 'complete':
    case 'wait_esign':
      return isOld ? 'พร้อมเผยแพร่' : 'พร้อมส่งลงนาม';
    case 'public':
    case 'failed':
      return 'กำลังประมวลผล';
  }
}

export function lifecycleStatus(doc: DocumentListItem, stage: StageKey): LifecycleStatus {
  const isOld = doc.document_type === 'old';
  const esign = String(doc.esign_sign_status ?? '').trim().toUpperCase();

  // 1. pipeline failure wins over everything
  if (doc.status === 'failed') {
    return { key: 'failed', bucket: 'failed', label: 'ล้มเหลว', color: 'error', icon: 'mdi-alert-circle-outline' };
  }

  // 2-3. e-sign negative outcomes (imported 'old' docs never go through e-sign)
  if (!isOld) {
    if (esign === 'N') {
      return { key: 'rejected', bucket: 'rejected_cancelled', label: 'ถูกปฏิเสธการลงนาม', color: 'error', icon: 'mdi-close-circle-outline' };
    }
    if (esign === 'C') {
      return { key: 'cancelled', bucket: 'rejected_cancelled', label: 'ยกเลิกการส่งลงนาม', color: 'warning', icon: 'mdi-cancel' };
    }
  }

  // 4. published
  if (doc.published_date) {
    return { key: 'published', bucket: 'published', label: 'เผยแพร่แล้ว', color: 'success', icon: 'mdi-earth' };
  }

  // 5-6. e-sign positive / in-flight
  if (!isOld) {
    if (esign === 'Y') {
      return { key: 'signed', bucket: 'signed', label: 'ลงนามสำเร็จ — รอเผยแพร่', color: 'admin-primary', icon: 'mdi-check-decagram-outline' };
    }
    if (doc.esign_submitted_at) {
      return { key: 'waiting', bucket: 'waiting', label: 'รอลงนาม', color: 'info', icon: 'mdi-draw-pen' };
    }
  }

  // 7. draft — show the coarse phase it is currently in
  return {
    key: 'draft',
    bucket: 'draft',
    label: `ร่าง · ${draftPhaseLabel(stage, isOld)}`,
    color: 'grey',
    icon: STAGE_MAP[stage].icon,
  };
}
