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
  if (code === 'C') {
    return false;
  }

  return code === 'Y' || Boolean(status.esign_signed_at) || Boolean(status.esign_confirmed_at);
}

export function hasSignedEsignPdf(status: Pick<DocumentStatus, 'esign_sign_status' | 'esign_signed_filename' | 'esign_doc_filename'> | null | undefined): boolean {
  if (!status) {
    return false;
  }

  const signedName = String(status.esign_signed_filename ?? '').trim();
  if (signedName !== '') {
    return !isEsignRejected(status) && esignSignCode(status) !== 'C';
  }

  return isEsignApproved(status) && String(status.esign_doc_filename ?? '').trim() !== '';
}
