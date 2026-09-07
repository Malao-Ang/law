import type { DocumentStatus, LawMeta, LawRelation } from '../types/document';

export interface PublishGate {
  key: string;
  label: string;
  ok: boolean;
  status: string;
  level: 'required' | 'optional';
}

export interface PublishGateResult {
  gates: PublishGate[];
  canPublish: boolean;
  hasRequiredFail: boolean;
  hasOptionalWarning: boolean;
}

/**
 * Single source of truth for all publish validation conditions.
 *
 * Gate list (in order):
 *   1. e-Sign          — required, skipped for old docs
 *   2. RAG             — required, skipped for old docs
 *   3. Access scope    — required
 *   4. Relations       — optional
 *   5. Status          — required (must not be ร่าง)
 *   6. Metadata        — required (title + law_type + date)
 */
export function evaluatePublishGates(
  meta: LawMeta | null | undefined,
  docStatus: DocumentStatus | null | undefined,
  relations: LawRelation[],
  isOldDoc: boolean,
): PublishGateResult {
  const gates: PublishGate[] = [];

  if (!isOldDoc) {
    // Gate 1: e-Sign
    const esignSendFailed = docStatus?.esign_send_response?.status === 'fail';
    const esignSignedY = String(docStatus?.esign_sign_status ?? '').trim().toUpperCase() === 'Y';
    const esignOk =
      esignSignedY &&
      !!docStatus?.esign_confirmed_at &&
      docStatus?.esign_sign_status !== 'rejected' &&
      !esignSendFailed;

    let esignStatus: string;
    if (esignOk) {
      esignStatus = 'ยืนยันแล้ว';
    } else if (esignSendFailed) {
      esignStatus = 'ส่งลงนามล้มเหลว';
    } else if (docStatus?.esign_sign_status === 'rejected') {
      esignStatus = 'ถูกปฏิเสธการลงนาม';
    } else if (docStatus?.esign_submitted_at) {
      esignStatus = 'อยู่ระหว่างรอลงนาม';
    } else {
      esignStatus = 'ยังไม่ผ่าน e-Sign';
    }

    gates.push({
      key: 'esign',
      label: 'ผ่านการลงนาม e-Sign',
      ok: esignOk,
      status: esignStatus,
      level: 'required',
    });

    // Gate 2: RAG
    const ragStatus = docStatus?.status ?? '';
    const ragDone =
      ragStatus === 'exported' ||
      ragStatus === 'ingested' ||
      (docStatus?.workflow_completed_step ?? 0) >= 3;
    const ragOk = ragDone && !docStatus?.rag_skipped;

    gates.push({
      key: 'rag',
      label: 'จัดลำดับเนื้อหา',
      ok: ragOk,
      status: ragOk
        ? 'พร้อมใช้งาน'
        : docStatus?.rag_skipped
          ? 'ข้ามขั้นตอน — ต้องกลับไปทำ'
          : 'ยังไม่ได้จัดลำดับเนื้อหา',
      level: 'required',
    });
  }

  // Gate 3: Access scope
  gates.push({
    key: 'access_scope',
    label: 'กำหนดสิทธิ์การเข้าถึง',
    ok: !!meta?.access_scope,
    status: meta?.access_scope ? meta.access_scope : 'ยังไม่ได้กำหนด',
    level: 'required',
  });

  // Gate 4: Relations (optional)
  gates.push({
    key: 'relations',
    label: 'ความสัมพันธ์กฎหมาย',
    ok: relations.length > 0,
    status: relations.length > 0 ? `${relations.length} รายการ` : 'ยังไม่มี',
    level: 'optional',
  });

  // Gate 5: Status (must exist and not be ร่าง)
  const statusOk = !!meta?.status && meta.status !== 'ร่าง';
  gates.push({
    key: 'status',
    label: 'สถานะการบังคับใช้',
    ok: statusOk,
    status: statusOk ? meta!.status : 'ยังเป็นร่าง',
    level: 'required',
  });

  // Gate 6: Metadata completeness
  const metaOk = !!(
    meta?.title &&
    meta.law_type &&
    (meta.promulgation_date || meta.effective_date)
  );
  gates.push({
    key: 'metadata',
    label: 'ข้อมูลกฎหมายครบ (ชื่อ + ประเภท + วันที่)',
    ok: metaOk,
    status: metaOk ? 'ครบถ้วน' : 'ยังขาดข้อมูลจำเป็น',
    level: 'required',
  });

  const hasRequiredFail = gates.some((g) => g.level === 'required' && !g.ok);
  const hasOptionalWarning = gates.some((g) => g.level === 'optional' && !g.ok);
  const canPublish = !hasRequiredFail;

  return { gates, canPublish, hasRequiredFail, hasOptionalWarning };
}
