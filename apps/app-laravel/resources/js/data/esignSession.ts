import type { ESignActivity, ESignSession, ESignSigner } from '../types/esign';
import { createClientId } from '../utils/createClientId';

const SIGNERS_KEY = 'lawspace.esign.signers';
const SESSION_KEY = 'lawspace.esign.session';

export function signersStorageKey(documentId: string): string {
  return `${SIGNERS_KEY}.${documentId}`;
}

export function sessionStorageKey(documentId: string): string {
  return `${SESSION_KEY}.${documentId}`;
}

export function loadSigners(documentId: string): ESignSigner[] {
  try {
    const raw = localStorage.getItem(signersStorageKey(documentId));
    const parsed = raw ? JSON.parse(raw) as ESignSigner[] : [];
    return parsed.map((signer) => ({
      ...signer,
      roleType: signer.roleType ?? 'delegate',
    }));
  } catch {
    return [];
  }
}

export function saveSigners(documentId: string, signers: ESignSigner[]): void {
  localStorage.setItem(signersStorageKey(documentId), JSON.stringify(signers));
}

export function defaultSession(documentId: string): ESignSession {
  const short = documentId.replace(/[^A-Za-z0-9]/g, '').slice(-6).toUpperCase() || '000000';
  return {
    status: 'draft',
    trackingId: `ESIGN-${new Date().getFullYear()}-${short}`,
    submittedAt: null,
    signedAt: null,
    activities: [],
  };
}

export function loadSession(documentId: string): ESignSession {
  try {
    const raw = localStorage.getItem(sessionStorageKey(documentId));
    if (!raw) return defaultSession(documentId);
    return { ...defaultSession(documentId), ...JSON.parse(raw) as ESignSession };
  } catch {
    return defaultSession(documentId);
  }
}

export function saveSession(documentId: string, session: ESignSession): void {
  localStorage.setItem(sessionStorageKey(documentId), JSON.stringify(session));
}

export function pushActivity(
  session: ESignSession,
  activity: Omit<ESignActivity, 'id' | 'at'> & { at?: string },
): ESignSession {
  const entry: ESignActivity = {
    id: createClientId('activity'),
    at: activity.at ?? new Date().toISOString(),
    title: activity.title,
    detail: activity.detail,
    actor: activity.actor,
  };
  return {
    ...session,
    activities: [entry, ...session.activities],
  };
}

export const ROLE_LABELS: Record<string, string> = {
  president: 'อธิการบดี',
  council: 'นายกสภาฯ',
  delegate: 'ผู้รับมอบอำนาจ',
};
