import type { DocumentListItem } from '../types/document';

const PICKABLE_STATUSES = new Set(['done', 'exported', 'ingested']);
const RELATION_READY_STEP = 4;

export function isPickableDocument(doc: DocumentListItem): boolean {
  return PICKABLE_STATUSES.has(doc.status) || (doc.workflow_completed_step ?? 0) >= RELATION_READY_STEP;
}

export function rootDocuments(
  documents: DocumentListItem[],
  excludeDocumentId?: string | null,
): DocumentListItem[] {
  const byId = new Map(documents.map((doc) => [doc.document_id, doc]));

  return documents.filter((doc) => {
    if (excludeDocumentId && doc.document_id === excludeDocumentId) return false;
    if (!isPickableDocument(doc)) return false;
    if (!doc.parent_document_id) return true;

    // Show orphan children at root when their parent is not in the catalog.
    return !byId.has(doc.parent_document_id);
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
    return doc.parent_document_id === parentDocumentId;
  });
}

export function documentHasChildren(
  documents: DocumentListItem[],
  documentId: string,
): boolean {
  return documents.some(
    (doc) => doc.parent_document_id === documentId && isPickableDocument(doc),
  );
}

export function filterByQuery(items: Array<{ title: string }>, query: string): Array<{ title: string }> {
  const needle = query.trim().toLowerCase();
  if (!needle) return items;
  return items.filter((item) => item.title.toLowerCase().includes(needle));
}
