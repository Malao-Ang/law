export type ESignSignerRole = 'president' | 'council' | 'delegate';

export type ESignFlowStatus = 'draft' | 'waiting' | 'signed';

export interface ESignPerson {
  id: string;
  name: string;
  position: string;
  department: string;
  employeeId: string;
}

export interface ESignSigner {
  id: string;
  roleType: ESignSignerRole;
  name: string;
  position: string;
  department?: string;
  employeeId?: string;
  note?: string;
}

export interface ESignActivity {
  id: string;
  title: string;
  detail?: string;
  at: string; // ISO
  actor?: string;
}

export interface ESignSession {
  status: ESignFlowStatus;
  trackingId: string;
  submittedAt?: string | null;
  signedAt?: string | null;
  activities: ESignActivity[];
}
