import type { DocumentListItem } from '../types/document';

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

export function isUniversityAnnouncementType(lawType: string | null | undefined): boolean {
  const type = (lawType ?? '').trim();
  if (type === 'ประกาศที่ออกโดยมหาวิทยาลัย') return true;
  return type.includes('ประกาศ') && type.includes('มหาวิทยาลัย') && !type.includes('สภา');
}

export function isCouncilAnnouncementType(lawType: string | null | undefined): boolean {
  const type = (lawType ?? '').trim();
  if (type === 'ประกาศที่ออกโดยสภามหาวิทยาลัย') return true;
  return type.includes('ประกาศ') && type.includes('สภา');
}

export function matchesParentLawFamily(lawType: string | null | undefined, family: ParentLawFamily): boolean {
  const type = (lawType ?? '').trim();
  if (!type) return false;
  if (family === 'regulation') return type.includes('ระเบียบ');
  if (family === 'ordinance') return type.includes('ข้อบังคับ');
  if (family === 'announcement') return type.includes('ประกาศ');
  return type.includes('พระราชบัญญัติ')
    || type.includes('พ.ร.บ')
    || type.includes('กฎหมายภายนอก')
    || type === 'phrb'
    || type === 'kotmai-phaainok';
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
