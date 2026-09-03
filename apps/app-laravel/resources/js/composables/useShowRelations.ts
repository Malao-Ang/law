import type { LawRelation, RelationType, ReportDocument } from '../types/document';
import { parentIdsOf } from './useLawCatalog';
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
  changeStatus: string;
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
  sameLevelVersions: ShowRelRow[];
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
  return status === 'ยกเลิก' || status === 'ถูกยกเลิก' || status === 'ยกเลิกการใช้งาน' || status.includes('ยกเลิก');
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
      changeStatus: doc.change_status ?? '',
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

export const SAME_LEVEL_CHANGE_STATUSES = new Set([
  'ปรับปรุงทั้งฉบับ',
  'ยกเลิกทั้งฉบับ',
]);

export const WHOLE_EDITION_CHANGES = new Set([
  'ปรับปรุงทั้งฉบับ',
  'ยกเลิกทั้งฉบับ',
]);

export const SECTION_EDITION_CHANGES = new Set([
  'ปรับปรุงรายข้อ',
  'ปรับปรุงรายมาตรา',
  'ยกเลิกรายมาตรา',
]);

export function isSameLevelChange(changeStatus: string): boolean {
  return SAME_LEVEL_CHANGE_STATUSES.has(changeStatus.trim());
}

export function isWholeEditionChange(changeStatus: string): boolean {
  return WHOLE_EDITION_CHANGES.has(changeStatus.trim());
}

export function isSectionEditionChange(changeStatus: string): boolean {
  return SECTION_EDITION_CHANGES.has(changeStatus.trim());
}

export function isNewLawChange(changeStatus: string): boolean {
  return changeStatus.trim() === 'กฎหมายใหม่';
}

export function isAmendmentChange(changeStatus: string): boolean {
  return isSameLevelChange(changeStatus) || isSectionEditionChange(changeStatus);
}

export function versionNodeSize(changeStatus: string): 'big' | 'small' {
  return isSectionEditionChange(changeStatus) ? 'small' : 'big';
}

export const SAME_LEVEL_RELATION_TYPES = new Set<RelationType>([
  'amends',
  'supersedes',
  'repeals',
]);

export function regulationFamilyKey(title: string): string {
  return title
    .replace(/\s*พ\.ศ\.\s*[๐-๙0-9]+/gu, '')
    .replace(/\s+/g, ' ')
    .trim();
}

export function versionStatusKind(status: string): 'revoked' | 'active' | 'other' {
  if (isCancelledStatus(status) || status.includes('ยกเลิก')) return 'revoked';
  if (isActiveStatus(status)) return 'active';
  return 'other';
}

function relationsLinkingDocuments(relations: LawRelation[] | undefined): LawRelation[] {
  return (relations ?? []).filter((rel) => (rel.target_document_id?.trim() ?? '') !== '');
}

function relationTypesBetween(
  aId: string,
  bId: string,
  relations: LawRelation[] | undefined,
): RelationType[] {
  const types: RelationType[] = [];
  for (const rel of relationsLinkingDocuments(relations)) {
    const targetId = rel.target_document_id?.trim();
    if (targetId === bId) types.push(rel.type);
  }
  return types;
}

export function shouldUnionSameLevelFamily(
  a: ShowRelRow,
  b: ShowRelRow,
  relationTypes: RelationType[] = [],
): boolean {
  if (a.id === b.id) return false;
  if ((a.lawType || '') !== (b.lawType || '')) return false;

  const parentLinked = a.parentIds.includes(b.id) || b.parentIds.includes(a.id);
  const hasSameLevelRel = relationTypes.some((type) => SAME_LEVEL_RELATION_TYPES.has(type));
  if (parentLinked) return hasSameLevelRel;
  if (hasSameLevelRel) return true;

  const keyA = regulationFamilyKey(a.title);
  const keyB = regulationFamilyKey(b.title);
  if (keyA === '' || keyA !== keyB) return false;
  return isAmendmentChange(a.changeStatus) || isAmendmentChange(b.changeStatus);
}

export function shouldUnionSameLevel(
  a: ShowRelRow,
  b: ShowRelRow,
  relationTypes: RelationType[] = [],
): boolean {
  if (isSectionEditionChange(a.changeStatus) || isSectionEditionChange(b.changeStatus)) return false;
  return shouldUnionSameLevelFamily(a, b, relationTypes);
}

export function shouldNestAsSectionPatch(parent: ShowRelRow, child: ShowRelRow): boolean {
  if (parent.id === child.id) return false;
  if (!isSectionEditionChange(child.changeStatus)) return false;
  if ((parent.lawType || '') !== (child.lawType || '')) return false;
  if (child.parentIds.includes(parent.id)) return true;
  const keyParent = regulationFamilyKey(parent.title);
  const keyChild = regulationFamilyKey(child.title);
  return keyParent !== '' && keyParent === keyChild;
}

function sortVersionChain(rows: ShowRelRow[]): ShowRelRow[] {
  return [...rows].sort((a, b) => {
    const dateCmp = a.rawDate.localeCompare(b.rawDate);
    if (dateCmp !== 0) return dateCmp;
    return a.id.localeCompare(b.id);
  });
}

export type RelationBag = LawRelation[] | Record<string, LawRelation[]>;

function relationMapOf(relations: RelationBag | undefined, sourceId: string | null): Map<string, LawRelation[]> {
  const map = new Map<string, LawRelation[]>();
  if (!relations) return map;
  if (Array.isArray(relations)) {
    if (sourceId) map.set(sourceId, relations);
    return map;
  }
  for (const [id, list] of Object.entries(relations)) {
    map.set(id, list ?? []);
  }
  return map;
}
function groupedByUnion(
  rows: ShowRelRow[],
  relations: RelationBag | undefined,
  relationSourceId: string | null,
  includeSections: boolean,
): ShowRelRow[][] {
  const parent: Record<string, string> = {};
  const find = (id: string): string => {
    parent[id] ??= id;
    if (parent[id] !== id) parent[id] = find(parent[id]);
    return parent[id];
  };
  const union = (a: string, b: string): void => {
    const pa = find(a);
    const pb = find(b);
    if (pa !== pb) parent[pa] = pb;
  };

  const relMap = relationMapOf(relations, relationSourceId);

  for (let i = 0; i < rows.length; i += 1) {
    for (let j = i + 1; j < rows.length; j += 1) {
      const a = rows[i];
      const b = rows[j];
      const types: RelationType[] = [
        ...relationTypesBetween(a.id, b.id, relMap.get(a.id)),
        ...relationTypesBetween(b.id, a.id, relMap.get(b.id)),
      ];
      const linked = includeSections
        ? shouldUnionSameLevelFamily(a, b, types)
        : shouldUnionSameLevel(a, b, types);
      if (linked) union(a.id, b.id);
    }
  }

  const groups = new Map<string, ShowRelRow[]>();
  for (const row of rows) {
    const root = find(row.id);
    const list = groups.get(root) ?? [];
    list.push(row);
    groups.set(root, list);
  }

  return [...groups.values()]
    .map((group) => sortVersionChain(
      includeSections ? group : group.filter((row) => !isSectionEditionChange(row.changeStatus)),
    ))
    .filter((group) => group.length >= 2);
}

export function collectSameLevelChains(
  rootId: string,
  rows: ShowRelRow[],
  relations: RelationBag | undefined,
): ShowRelRow[][] {
  const byId = new Map(rows.map((row) => [row.id, row]));
  if (!byId.has(rootId)) return [];

  const components = groupedByUnion(rows, relations, rootId, false);

  const containingRoot = components.filter((group) => group.some((row) => row.id === rootId));
  if (containingRoot.length) return containingRoot;

  const root = byId.get(rootId);
  const rootKey = root ? regulationFamilyKey(root.title) : '';
  if (root && (isSectionEditionChange(root.changeStatus) || rootKey !== '')) {
    const family = components.filter((group) =>
      group.some((row) =>
        row.id === rootId
        || (rootKey !== '' && regulationFamilyKey(row.title) === rootKey && (row.lawType || '') === (root.lawType || '')),
      ),
    );
    if (family.length) return family;
  }

  const childIds = new Set(rows.filter((row) => row.parentIds.includes(rootId)).map((row) => row.id));
  return components.filter((group) => group.some((row) => childIds.has(row.id)));
}

export function collectMixedSameLevelChains(
  rows: ShowRelRow[],
  relations: RelationBag | undefined,
  relationSourceId: string | null = null,
): ShowRelRow[][] {
  return groupedByUnion(rows, relations, relationSourceId, true);
}

export function collectDescendantIds(rootId: string, rows: ShowRelRow[]): string[] {
  const childrenByParent = new Map<string, string[]>();
  for (const row of rows) {
    for (const parentId of row.parentIds) {
      const list = childrenByParent.get(parentId) ?? [];
      list.push(row.id);
      childrenByParent.set(parentId, list);
    }
  }
  const out: string[] = [];
  const seen = new Set<string>([rootId]);
  const stack = [rootId];
  while (stack.length) {
    const id = stack.pop()!;
    for (const childId of childrenByParent.get(id) ?? []) {
      if (seen.has(childId)) continue;
      seen.add(childId);
      out.push(childId);
      stack.push(childId);
    }
  }
  return out;
}

export function sameLevelKeepId(rootId: string, chain: ShowRelRow[]): string {
  if (chain.some((row) => row.id === rootId)) return rootId;

  const pick = (list: ShowRelRow[]): string | undefined => {
    if (!list.length) return undefined;
    const latestWhole = [...list].reverse().find((row) => isWholeEditionChange(row.changeStatus));
    if (latestWhole) return latestWhole.id;
    const original = list.find((row) => !isSectionEditionChange(row.changeStatus));
    return (original ?? list[list.length - 1])?.id;
  };

  const direct = chain.filter((row) => row.parentIds.includes(rootId));
  return pick(direct) ?? pick(chain) ?? rootId;
}

export function sameLevelTreeSkipIds(rootId: string, chains: ShowRelRow[][]): Set<string> {
  const skip = new Set<string>();
  for (const chain of chains) {
    const keepId = sameLevelKeepId(rootId, chain);
    for (const row of chain) {
      if (row.id !== rootId && row.id !== keepId) skip.add(row.id);
    }
  }
  return skip;
}

export function currentFamilyTitle(chain: ShowRelRow[]): string {
  const active = [...chain].reverse().find((row) => versionStatusKind(row.metaStatus) === 'active');
  return (active ?? chain[chain.length - 1])?.title ?? '';
}

export function editionKindLabel(changeStatus: string): string {
  if (isSectionEditionChange(changeStatus)) return 'ปรับปรุงรายข้อ';
  if (changeStatus.trim() === 'ปรับปรุงทั้งฉบับ' || changeStatus.trim() === 'ยกเลิกทั้งฉบับ') return 'ปรับปรุงทั้งฉบับ';
  if (changeStatus.trim() === 'กฎหมายใหม่') return 'กฎหมายใหม่';
  return 'ทั้งฉบับ';
}

export function buildRelationTree(
  rootId: string,
  rows: ShowRelRow[],
  relations: RelationBag | undefined,
  allowedTypes: RelationType[] | null,
  skipChildIds: Set<string> | null = null,
): RelTreeNode | null {
  const byId = new Map(rows.map((row) => [row.id, row]));
  const root = byId.get(rootId);
  if (!root) return null;

  const mixedChains = collectMixedSameLevelChains(rows, relations, rootId);
  const mixedSkip = sameLevelTreeSkipIds(rootId, mixedChains);
  const skip = new Set<string>([...mixedSkip, ...(skipChildIds ?? [])]);
  const chainById = new Map<string, ShowRelRow[]>();
  for (const chain of mixedChains) {
    for (const row of chain) chainById.set(row.id, chain);
  }

  const childrenByParent = new Map<string, ShowRelRow[]>();
  for (const row of rows) {
    for (const parentId of row.parentIds) {
      const list = childrenByParent.get(parentId) ?? [];
      list.push(row);
      childrenByParent.set(parentId, list);
    }
  }

  const extraBySource = new Map<string, Array<{ id: string; type: RelationType }>>();
  const relMap = relationMapOf(relations, rootId);
  for (const rel of relationsLinkingDocuments(relMap.get(rootId))) {
    const targetId = rel.target_document_id?.trim();
    if (!targetId || !byId.has(targetId) || targetId === rootId) continue;
    if (SAME_LEVEL_RELATION_TYPES.has(rel.type)) continue;
    const list = extraBySource.get(rootId) ?? [];
    if (!list.some((item) => item.id === targetId)) {
      list.push({ id: targetId, type: rel.type });
    }
    extraBySource.set(rootId, list);
  }

  const relationByTarget = new Map<string, RelationType>();
  for (const list of relMap.values()) {
    for (const rel of relationsLinkingDocuments(list)) {
      const targetId = rel.target_document_id?.trim();
      if (targetId) relationByTarget.set(targetId, rel.type);
    }
  }

  const allowed = allowedTypes && allowedTypes.length ? new Set(allowedTypes) : null;

  function walk(id: string, level: number, edgeType: RelationType, seen: Set<string>): RelTreeNode | null {
    const row = byId.get(id);
    if (!row) return null;

    const nextSeen = new Set(seen);
    nextSeen.add(id);

    const childRows: ShowRelRow[] = [];
    const seenChild = new Set<string>();
    const addChild = (child: ShowRelRow): void => {
      if (child.id === id || seenChild.has(child.id) || nextSeen.has(child.id)) return;
      if (skip.has(child.id)) return;
      seenChild.add(child.id);
      childRows.push(child);
    };

    for (const child of childrenByParent.get(id) ?? []) {
      addChild(child);
    }

    if (level === 0) {
      for (const extra of extraBySource.get(id) ?? []) {
        const extraRow = byId.get(extra.id);
        if (!extraRow) continue;
        addChild(extraRow);
      }
    }

    const children: RelTreeNode[] = [];
    if (level < MAX_DEPTH) {
      const ordered = [...childRows].sort((a, b) => a.title.localeCompare(b.title, 'th'));
      for (const child of ordered) {
        const childEdge = relationByTarget.get(child.id)
          ?? (child.parentIds.includes(id) ? 'issued_under' : 'related');
        const node = walk(child.id, level + 1, childEdge, nextSeen);
        if (node) children.push(node);
      }
    }

    if (level > 0 && allowed && children.length === 0 && !allowed.has(edgeType)) {
      return null;
    }

    return {
      row,
      edgeType,
      level,
      children,
      sameLevelVersions: chainById.get(id) ?? [],
    };
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
