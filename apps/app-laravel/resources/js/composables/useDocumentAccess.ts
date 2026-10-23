import { computed, ref, watch } from 'vue';
import { fetchStatus } from '../api/client';
import { useAuthStore } from '../stores/authStore';
import type { DocumentCapabilities } from '../types/document';

const denied: DocumentCapabilities = { edit: false, delete: false, esign: false, publish: false, transfer: false, cancel_esign: false, manage_co_editors: false, read: false };
const unrestricted: DocumentCapabilities = { edit: true, delete: true, esign: true, publish: true, transfer: true, cancel_esign: true, manage_co_editors: true, read: true };

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
    () => JSON.stringify(auth.memberships), () => auth.is_super_admin, () => auth.authEnforced], refreshCan, { immediate: true });
  return { refreshCan, setCan: (can: DocumentCapabilities) => { capabilities.value = can; }, can: computed(() => auth.authEnforced ? capabilities.value : unrestricted) };
}
