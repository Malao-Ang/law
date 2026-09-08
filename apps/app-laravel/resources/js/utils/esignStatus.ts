import type { DocumentStatus } from '../types/document';

type EsignFields = Pick<
  DocumentStatus,
  'esign_sign_status' | 'esign_signed_at' | 'esign_confirmed_at'
>;

export function esignSignCode(status: Pick<DocumentStatus, 'esign_sign_status'> | null | undefined): string {
  return String(status?.esign_sign_status ?? '').trim().toUpperCase();
}

export function isEsignRejected(status: Pick<DocumentStatus, 'esign_sign_status'> | null | undefined): boolean {
  const code = esignSignCode(status);
  return code === 'N' || code === 'REJECTED';
}

export function isEsignApproved(status: EsignFields | null | undefined): boolean {
  if (!status || isEsignRejected(status)) {
    return false;
  }

  const code = esignSignCode(status);
  return code === 'Y';
}

export function hasSignedEsignPdf(status: Pick<DocumentStatus, 'esign_sign_status' | 'esign_signed_filename' | 'esign_doc_filename'> | null | undefined): boolean {
  if (!status) {
    return false;
  }

  const signedName = String(status.esign_signed_filename ?? '').trim();
  if (signedName !== '') {
    return isEsignApproved(status);
  }

  return isEsignApproved(status) && String(status.esign_doc_filename ?? '').trim() !== '';
}

type EsignStatusInput = {
  document_type?: string | null;
  esign_sign_status?: string | null;
  esign_submitted_at?: string | null;
};

/** Human e-Sign status for admin tables. Old docs → '–' (no e-Sign). */
export function esignStatusLabel(row: EsignStatusInput | null | undefined): string {
  if (!row) return 'ยังไม่ส่งลงนาม';
  if (row.document_type === 'old') return '–';
  const code = String(row.esign_sign_status ?? '').trim().toUpperCase();
  if (code === 'Y') return 'ลงนามแล้ว';
  if (code === 'N') return 'ถูกปฏิเสธ';
  if (code === 'C') return 'ยกเลิกการส่ง';
  if (row.esign_submitted_at) return 'รอลงนาม';
  return 'ยังไม่ส่งลงนาม';
}

export function esignStatusColor(row: EsignStatusInput | null | undefined): string {
  if (!row || row.document_type === 'old') return 'grey';
  const code = String(row.esign_sign_status ?? '').trim().toUpperCase();
  if (code === 'Y') return 'success';
  if (code === 'N') return 'error';
  if (code === 'C') return 'warning';
  if (row.esign_submitted_at) return 'admin-primary';
  return 'grey';
}
