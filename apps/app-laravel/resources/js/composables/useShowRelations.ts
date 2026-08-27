import type { LawRelation, RelationType, ReportDocument } from '../types/document';
import { parentIdsOf } from './useLawCatalog';
import { documentRelations } from './useLawSections';
import { formatThaiDate } from '../utils/thaiDate';

export const SHOW_REL_RECENT_KEY = 'lawspace.show-relations.recent';
const MAX_RECENT = 12;
const MAX_DEPTH = 6;

export const TYPE_META: Record<string, { color: string; short: string }> = {
  กฎหมายภายนอก: { color: 'doc-phaainok', short: 'พ.ร.บ.' },
  พระราชบัญญัติ: { color: 'doc-phaainok', short: 'พ.ร.บ.' },
  ข้อบังคับ: { color: 'doc-kho-bangkhab', short: 'ข้อบังคับ' },
  ระเบียบ: { color: 'doc-rabiap', short: 'ระเบียบ' },
  ประกาศ: { color: 'doc-prakat', short: 'ประกาศ' },
  ประกาศที่ออกโดยมหาวิทยาลัย: { color: 'doc-prakat', short: 'ประกาศ มหาลัย' },
  ประกาศที่ออกโดยสภามหาวิทยาลัย: { color: 'doc-prakat', short: 'ประกาศ สภา' },
};

export const RELATION_FILTERS: Array<{ value: RelationType; label: string }> = [
  { value: 'issued_under', label: 'ออกตามอำนาจ' },
  { value: 'amends', label: 'แก้ไขเพิ่มเติม' },
  { value: 'related', label: 'เกี่ยวข้อง' },
];

export interface ShowRelRow {
  id: string;
  title: string;
  lawType: string;
  typeShort: string;
  metaStatus: string;
  workflowStage: string;
  isParent: boolean;
  childCount: number;
  org: string;
  group: string;
  pages: number;
  sections: number | null;
  editedAt: string;
  rawDate: string;
  parentIds: string[];
}

export interface RelTreeNode {
  row: ShowRelRow;
  edgeType: RelationType;
  level: number;
  children: RelTreeNode[];
}

export function typeColor(type: string): string {
  return TYPE_META[type]?.color ?? (type.includes('ประกาศ') ? 'doc-prakat' : 'grey');
}

export function typeShort(type: string): string {
  return TYPE_META[type]?.short ?? (type.includes('ประกาศ') ? 'ประกาศ' : type);
}

export function typeIcon(type: string): string {
  if (type === 'กฎหมายภายนอก' || type === 'พระราชบัญญัติ') return 'mdi-office-building-outline';
  if (type === 'ข้อบังคับ') return 'mdi-scale-balance';
  if (type === 'ระเบียบ') return 'mdi-folder-outline';
  if (type.includes('ประกาศ')) return 'mdi-bullhorn-outline';
  return 'mdi-file-document-outline';
}

export function workflowStageLabel(doc: {
  status: string;
  meta_status: string;
  workflow_completed_step: number | null;
}): string {
  if (doc.meta_status === 'ยกเลิก') return 'ยกเลิก';
  const step = doc.workflow_completed_step ?? 0;
  if (doc.status === 'exported' || doc.status === 'ingested') return 'เผยแพร่';
  if (step >= 6) return 'พร้อมเผยแพร่';
  if (step >= 5) return 'รอส่ง eSign';
  if (step >= 4) return 'รอการเชื่อมโยงความสัมพันธ์';
  if (doc.status === 'done') return 'ดำเนินการ';
  return 'กำลังประมวลผล';
}

export function workflowStageColor(stage: string): string {
  if (stage === 'เผยแพร่') return 'success';
  if (stage === 'พร้อมเผยแพร่') return 'admin-primary';
  if (stage === 'รอส่ง eSign') return 'deep-purple';
  if (stage === 'รอการเชื่อมโยงความสัมพันธ์') return 'orange';
  if (stage === 'ยกเลิก') return 'error';
  if (stage === 'ดำเนินการ') return 'teal';
  return 'grey';
}

export function metaStatusColor(status: string): string {
  if (
    status === 'active'
    || status === 'มีผลบังคับใช้'
    || status === 'มีผลใช้บังคับ'
    || status === 'ใช้บังคับ'
    || status === 'บังคับใช้'
  ) return 'success';
  if (status === 'ยกเลิก' || status === 'ถูกยกเลิก') return 'error';
  if (status === 'พักใช้' || status === 'ระงับใช้') return 'warning';
  return 'grey';
}

export function isActiveStatus(status: string): boolean {
  return metaStatusColor(status) === 'success';
}

export function isCancelledStatus(status: string): boolean {
  return status === 'ยกเลิก' || status === 'ถูกยกเลิก';
}

export function displayLawDate(value: string | null | undefined): string {
  const text = value?.trim() ?? '';
  if (!text) return '—';
  if (/[ก-๙]/.test(text)) return text;
  return formatThaiDate(text) || text;
}

export function mapShowRelRows(documents: ReportDocument[]): ShowRelRow[] {
  const completed = documents.filter((doc) => (doc.workflow_completed_step ?? 0) >= 4);
  const childCountMap: Record<string, number> = {};
  for (const doc of completed) {
    for (const parentId of parentIdsOf(doc)) {
      childCountMap[parentId] = (childCountMap[parentId] ?? 0) + 1;
    }
  }

  return completed.map((doc) => {
    const lawType = doc.type !== 'ไม่ระบุ' ? doc.type : '';
    return {
      id: doc.id,
      title: doc.title,
      lawType,
      typeShort: typeShort(lawType),
      metaStatus: doc.meta_status ?? '',
      workflowStage: workflowStageLabel(doc),
      isParent: (childCountMap[doc.id] ?? 0) > 0,
      childCount: childCountMap[doc.id] ?? 0,
      org: doc.agency !== 'ไม่ระบุ' ? doc.agency : '',
      group: doc.group !== 'ไม่ระบุ' ? doc.group : '',
      pages: doc.page_count ?? 0,
      sections: doc.section_count ?? null,
      editedAt: formatThaiDate(doc.date) || '-',
      rawDate: doc.date ?? '',
      parentIds: parentIdsOf(doc),
    };
  });
}

export function loadRecentIds(): string[] {
  try {
    const raw = localStorage.getItem(SHOW_REL_RECENT_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === 'string') : [];
  } catch {
    return [];
  }
}

export function rememberRecentId(documentId: string): void {
  const next = [documentId, ...loadRecentIds().filter((id) => id !== documentId)].slice(0, MAX_RECENT);
  localStorage.setItem(SHOW_REL_RECENT_KEY, JSON.stringify(next));
}

export function buildRelationTree(
  rootId: string,
  rows: ShowRelRow[],
  relations: LawRelation[] | undefined,
  allowedTypes: RelationType[] | null,
): RelTreeNode | null {
  const byId = new Map(rows.map((row) => [row.id, row]));
  const root = byId.get(rootId);
  if (!root) return null;

  const childrenByParent = new Map<string, ShowRelRow[]>();
  for (const row of rows) {
    for (const parentId of row.parentIds) {
      const list = childrenByParent.get(parentId) ?? [];
      list.push(row);
      childrenByParent.set(parentId, list);
    }
  }

  const extraBySource = new Map<string, Array<{ id: string; type: RelationType }>>();
  for (const rel of documentRelations(relations)) {
    const targetId = rel.target_document_id?.trim();
    if (!targetId || !byId.has(targetId) || targetId === rootId) continue;
    const list = extraBySource.get(rootId) ?? [];
    if (!list.some((item) => item.id === targetId)) {
      list.push({ id: targetId, type: rel.type });
    }
    extraBySource.set(rootId, list);
  }

  const relationByTarget = new Map<string, RelationType>();
  for (const rel of documentRelations(relations)) {
    const targetId = rel.target_document_id?.trim();
    if (targetId) relationByTarget.set(targetId, rel.type);
  }

  const allowed = allowedTypes && allowedTypes.length ? new Set(allowedTypes) : null;

  function walk(id: string, level: number, edgeType: RelationType, seen: Set<string>): RelTreeNode | null {
    const row = byId.get(id);
    if (!row) return null;

    const nextSeen = new Set(seen);
    nextSeen.add(id);

    const childRows = [...(childrenByParent.get(id) ?? [])];
    if (level === 0) {
      for (const extra of extraBySource.get(id) ?? []) {
        if (!childRows.some((child) => child.id === extra.id)) {
          const extraRow = byId.get(extra.id);
          if (extraRow) childRows.push(extraRow);
        }
      }
    }

    const children: RelTreeNode[] = [];
    if (level < MAX_DEPTH) {
      for (const child of childRows) {
        if (nextSeen.has(child.id)) continue;
        const childEdge = relationByTarget.get(child.id)
          ?? (child.parentIds.includes(id) ? 'issued_under' : 'related');
        const node = walk(child.id, level + 1, childEdge, nextSeen);
        if (node) children.push(node);
      }
    }

    if (level > 0 && allowed && children.length === 0 && !allowed.has(edgeType)) {
      return null;
    }

    return { row, edgeType, level, children };
  }

  return walk(rootId, 0, 'related', new Set());
}

export function flattenTree(node: RelTreeNode | null): RelTreeNode[] {
  if (!node) return [];
  const out: RelTreeNode[] = [];
  const visit = (current: RelTreeNode) => {
    if (current.level > 0) out.push(current);
    for (const child of current.children) visit(child);
  };
  visit(node);
  return out;
}

export function maxTreeLevel(node: RelTreeNode | null): number {
  if (!node) return 0;
  let max = node.level;
  for (const child of node.children) {
    max = Math.max(max, maxTreeLevel(child));
  }
  return max;
}
