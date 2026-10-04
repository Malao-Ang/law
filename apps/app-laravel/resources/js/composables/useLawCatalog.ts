import type { DocumentListItem } from '../types/document';
import { createLawTypeCatalog } from './useLawType';

const PICKABLE_STATUSES = new Set(['done', 'exported', 'ingested']);
const RELATION_READY_STEP = 4;

export function isPickableDocument(doc: DocumentListItem): boolean {
  return PICKABLE_STATUSES.has(doc.status) || (doc.workflow_completed_step ?? 0) >= RELATION_READY_STEP;
}

export function parentIdsOf(doc: {
  parent_document_id?: string | null;
  parent_document_ids?: string[] | null;
}): string[] {
  const ids = (doc.parent_document_ids ?? [])
    .map((id) => id.trim())
    .filter((id) => id !== '');
  if (ids.length) return [...new Set(ids)];
  const legacy = doc.parent_document_id?.trim();
  return legacy ? [legacy] : [];
}

export function rootDocuments(
  documents: DocumentListItem[],
  excludeDocumentId?: string | null,
): DocumentListItem[] {
  const byId = new Map(documents.map((doc) => [doc.document_id, doc]));

  return documents.filter((doc) => {
    if (excludeDocumentId && doc.document_id === excludeDocumentId) return false;
    if (!isPickableDocument(doc)) return false;
    const parents = parentIdsOf(doc);
    if (parents.length === 0) return true;

    // Show orphan children at root when none of their parents are in the catalog.
    return parents.every((parentId) => !byId.has(parentId));
  });
}

const SAME_LEVEL_EDITION_STATUSES = new Set([
  'ปรับปรุงทั้งฉบับ',
  'ยกเลิกทั้งฉบับ',
  'ปรับปรุงรายข้อ',
  'ปรับปรุงรายมาตรา',
  'ยกเลิกรายมาตรา',
]);

export function documentsWithoutParent(
  documents: DocumentListItem[],
  excludeDocumentId?: string | null,
): DocumentListItem[] {
  return documents.filter((doc) => {
    if (excludeDocumentId && doc.document_id === excludeDocumentId) return false;
    if (!isPickableDocument(doc)) return false;
    if (parentIdsOf(doc).length > 0) return false;
    const changeStatus = doc.change_status?.trim() ?? '';
    return !SAME_LEVEL_EDITION_STATUSES.has(changeStatus);
  });
}

export function pickableDocuments(
  documents: DocumentListItem[],
  excludeDocumentId?: string | null,
): DocumentListItem[] {
  return documents.filter((doc) => {
    if (excludeDocumentId && doc.document_id === excludeDocumentId) return false;
    return isPickableDocument(doc);
  });
}

export function childDocuments(
  documents: DocumentListItem[],
  parentDocumentId: string,
  excludeDocumentId?: string | null,
): DocumentListItem[] {
  return documents.filter((doc) => {
    if (excludeDocumentId && doc.document_id === excludeDocumentId) return false;
    if (!isPickableDocument(doc)) return false;
    return parentIdsOf(doc).includes(parentDocumentId);
  });
}

export function documentsByIds(
  documents: DocumentListItem[],
  documentIds: string[],
  excludeDocumentId?: string | null,
): DocumentListItem[] {
  const ids = new Set(documentIds.map((id) => id.trim()).filter(Boolean));
  if (ids.size === 0) return [];

  return documents.filter((doc) => {
    if (excludeDocumentId && doc.document_id === excludeDocumentId) return false;
    if (!isPickableDocument(doc)) return false;
    return ids.has(doc.document_id);
  });
}

export function documentsUnderParents(
  documents: DocumentListItem[],
  parentDocumentIds: string[],
  excludeDocumentId?: string | null,
): DocumentListItem[] {
  const parents = new Set(parentDocumentIds.map((id) => id.trim()).filter(Boolean));
  if (parents.size === 0) return [];

  return documents.filter((doc) => {
    if (excludeDocumentId && doc.document_id === excludeDocumentId) return false;
    if (!isPickableDocument(doc)) return false;
    return parentIdsOf(doc).some((id) => parents.has(id));
  });
}

export function documentsSiblingsAndParents(
  documents: DocumentListItem[],
  parentDocumentIds: string[],
  excludeDocumentId?: string | null,
): DocumentListItem[] {
  const seen = new Set<string>();
  const merged: DocumentListItem[] = [];
  for (const doc of [
    ...documentsByIds(documents, parentDocumentIds, excludeDocumentId),
    ...documentsUnderParents(documents, parentDocumentIds, excludeDocumentId),
  ]) {
    if (seen.has(doc.document_id)) continue;
    seen.add(doc.document_id);
    merged.push(doc);
  }
  return merged;
}

export function documentHasChildren(
  documents: DocumentListItem[],
  documentId: string,
): boolean {
  return documents.some(
    (doc) => parentIdsOf(doc).includes(documentId) && isPickableDocument(doc),
  );
}

export function filterByQuery(items: Array<{ title: string }>, query: string): Array<{ title: string }> {
  const needle = query.trim().toLowerCase();
  if (!needle) return items;
  return items.filter((item) => item.title.toLowerCase().includes(needle));
}

export type ParentLawFamily = 'act' | 'regulation' | 'ordinance' | 'announcement';

const lawTypes = createLawTypeCatalog();

export function isUniversityAnnouncementType(lawType: string | null | undefined): boolean {
  return lawTypes.typeItem(lawType)?.code === 'LTY01';
}

export function isCouncilAnnouncementType(lawType: string | null | undefined): boolean {
  return lawTypes.typeItem(lawType)?.code === 'LTY09';
}

export function matchesParentLawFamily(lawType: string | null | undefined, family: ParentLawFamily): boolean {
  const familyCode = lawTypes.typeFamily(lawType);
  if (!familyCode) return false;
  if (family === 'regulation') return familyCode === 'LFM02';
  if (family === 'ordinance') return familyCode === 'LFM01';
  if (family === 'announcement') return familyCode === 'LFM03';
  return lawTypes.typeSource(lawType) === 'external';
}

export function allowedParentFamiliesForChild(childLawType: string | null | undefined): ParentLawFamily[] | null {
  if (isCouncilAnnouncementType(childLawType)) {
    return ['act', 'regulation', 'ordinance', 'announcement'];
  }
  if (isUniversityAnnouncementType(childLawType)) {
    return ['regulation', 'ordinance'];
  }
  return null;
}

export function parentDocumentsForChildType(
  documents: DocumentListItem[],
  childLawType: string | null | undefined,
  excludeDocumentId?: string | null,
  keepDocumentIds: string[] = [],
): DocumentListItem[] {
  const families = allowedParentFamiliesForChild(childLawType);
  const keep = new Set(keepDocumentIds.map((id) => id.trim()).filter(Boolean));

  return documents.filter((doc) => {
    if (excludeDocumentId && doc.document_id === excludeDocumentId) return false;
    if (!isPickableDocument(doc)) return false;
    if (keep.has(doc.document_id)) return true;
    if (!families) return true;
    return families.some((family) => matchesParentLawFamily(doc.law_type, family));
  });
}
