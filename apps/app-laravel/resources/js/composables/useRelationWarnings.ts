import { ref } from 'vue';
import { fetchActiveChildren, fetchIncomingRelationDocumentIds, type ActiveChildLaw } from '../api/client';
import { useChangeStatus } from './useChangeStatus';
import { useLawStatus } from './useLawStatus';

export function useRelationWarnings() {
  const activeChildren = ref<ActiveChildLaw[]>([]);
  const amendedByOthers = ref(false);
  const { isWhole, isSection } = useChangeStatus();
  const { isRepealed } = useLawStatus();

  async function load(documentId: string): Promise<void> {
    const [children, incoming] = await Promise.all([
      fetchActiveChildren(documentId).catch(() => ({ document_id: documentId, count: 0, children: [] as ActiveChildLaw[] })),
      fetchIncomingRelationDocumentIds(documentId).catch(() => [] as string[]),
    ]);
    activeChildren.value = children.children ?? [];
    amendedByOthers.value = incoming.length > 0;
  }

  function showAmendedChildrenWarning(changeStatus: string | null | undefined): boolean {
    const amended = amendedByOthers.value || isWhole(changeStatus ?? '') || isSection(changeStatus ?? '');
    return amended && activeChildren.value.length > 0;
  }

  function repealedParentTitles(parents: Array<{ title: string; status: string | null | undefined }>): string[] {
    return parents
      .filter((parent) => isRepealed(parent.status))
      .map((parent) => parent.title.trim())
      .filter((title) => title !== '');
  }

  return { activeChildren, load, showAmendedChildrenWarning, repealedParentTitles };
}
