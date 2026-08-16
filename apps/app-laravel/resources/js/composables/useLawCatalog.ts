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
