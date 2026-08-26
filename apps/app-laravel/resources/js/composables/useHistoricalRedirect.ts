import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchStatus } from '../api/client';

/**
 * Old (historical) docs have no editable blocks — send them to the read-only
 * PDF preview instead of an empty block editor. Only fires for document_type
 * 'old'; new scanned-PDF docs keep the editor.
 */
export function useHistoricalRedirect(documentId: string): void {
  const router = useRouter();
  onMounted(async () => {
    const status = await fetchStatus(documentId).catch(() => null);
    if (status?.document_type === 'old') {
      void router.replace(`/documents/${documentId}/preview`);
    }
  });
}
