import { defineStore } from 'pinia';
import { ref } from 'vue';
import { getDocumentVersions } from '../api/client';
import type { VersionChainItem } from '../types/versionChain';

export const useVersionStore = defineStore('versions', () => {
  const documentId = ref('');
  const versions = ref<VersionChainItem[]>([]);
  const currentDocumentId = ref('');
  const loading = ref(false);

  let fetchRequest = 0;
  async function fetch(id: string): Promise<void> {
    const current = ++fetchRequest;
    documentId.value = id;
    // Clear stale chain up-front so navigating A -> B never flashes A's timeline/banner while B loads.
    versions.value = [];
    currentDocumentId.value = '';
    loading.value = true;
    try {
      const data = await getDocumentVersions(id);
      if (current !== fetchRequest) return;
      versions.value = data.versions;
      currentDocumentId.value = data.current_document_id;
    } catch {
      if (current !== fetchRequest) return;
      versions.value = [];
      currentDocumentId.value = '';
    } finally {
      if (current === fetchRequest) loading.value = false;
    }
  }

  function reset(): void {
    fetchRequest++;
    documentId.value = '';
    versions.value = [];
    currentDocumentId.value = '';
    loading.value = false;
  }

  return { documentId, versions, currentDocumentId, loading, fetch, reset };
});
