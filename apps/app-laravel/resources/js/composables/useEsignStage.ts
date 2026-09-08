import type { DocumentStatus, LawMeta } from '../types/document';
import { esignSignCode } from '../utils/esignStatus';

export type EsignStage = 'draft' | 'waiting' | 'signed' | 'published' | 'rejected' | 'cancelled';

export function deriveEsignStage(
  status: DocumentStatus | null | undefined,
  meta: LawMeta | null | undefined,
): EsignStage {
  if (meta?.published_date) return 'published';

  const code = esignSignCode(status);
  if (code === 'Y' || status?.esign_confirmed_at || status?.esign_signed_at) return 'signed';
  if (code === 'N') return 'rejected';
  if (code === 'C') return 'cancelled';
  if (status?.esign_submitted_at) return 'waiting';
  return 'draft';
}

export const ESIGN_STAGE_LABEL: Record<EsignStage, string> = {
  draft: 'เตรียมส่งลงนาม',
  waiting: 'รอลงนาม',
  signed: 'ลงนามเสร็จ',
  published: 'เผยแพร่แล้ว',
  rejected: 'ถูกปฏิเสธการลงนาม',
  cancelled: 'ยกเลิกการส่ง',
};

export const ESIGN_STAGE_COLOR: Record<EsignStage, string> = {
  draft: 'admin-primary',
  waiting: 'warning',
  signed: 'success',
  published: 'success',
  rejected: 'error',
  cancelled: 'warning',
};
