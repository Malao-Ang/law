import { computed, ref, watch } from 'vue';
import { fetchStatus } from '../api/client';
import { useAuthStore } from '../stores/authStore';
import type { DocumentCapabilities } from '../types/document';

const denied: DocumentCapabilities = { manage_permissions: false, edit: false, delete: false, esign: false, publish: false, transfer: false, cancel_esign: false, read: false };

export function useDocumentAccess(documentId: () => string) {
  const auth = useAuthStore();
  const capabilities = ref<DocumentCapabilities>(denied);
  let request = 0;
  async function refreshCan(): Promise<void> {
    const current = ++request;
    capabilities.value = denied;
    try {
      const status = await fetchStatus(documentId());
      if (current === request) capabilities.value = status.can ?? denied;
    } catch { /* Keep actions disabled when capabilities cannot be loaded. */ }
  }
  watch([documentId, () => auth.active_unit?.code, () => auth.user?.username,
    () => JSON.stringify(auth.memberships), () => auth.is_super_admin, () => auth.role, () => auth.acting_mode, () => auth.authEnforced], refreshCan, { immediate: true });
  return { refreshCan, setCan: (can: DocumentCapabilities) => { capabilities.value = can; }, can: computed(() => capabilities.value) };
}
