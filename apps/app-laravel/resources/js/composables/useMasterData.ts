import { computed, ref, watch } from 'vue';
import {
  createMaster,
  listMaster,
  setMasterActive,
  updateMaster,
} from '../api/masterData';
import { useLookups } from './useLookups';
import { useSnackbarStore } from '../stores/snackbarStore';
import type {
  MasterActiveFilter,
  MasterItem,
  MasterKind,
  MasterStats,
  UpsertMasterItemPayload,
} from '../types/masterData';

export function useMasterData(kind: MasterKind) {
  const snackbar = useSnackbarStore();
  const lookups = useLookups();
  const items = ref<MasterItem[]>([]);
  const total = ref(0);
  const stats = ref<MasterStats>({ total: 0, active: 0, inactive: 0 });
  const loading = ref(false);
  const q = ref('');
  const activeFilter = ref<MasterActiveFilter>('all');
  const page = ref(1);
  const perPage = ref(10);
  const error = ref<string | null>(null);
  let searchTimer: ReturnType<typeof setTimeout> | null = null;
  let requestSeq = 0;

  const pageCount = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));

  async function fetch(): Promise<void> {
    const seq = ++requestSeq;
    loading.value = true;
    error.value = null;
    try {
      const response = await listMaster(kind, {
        q: q.value.trim() || undefined,
        active: activeFilter.value,
        page: page.value,
        per_page: perPage.value,
      });
      if (seq !== requestSeq) return;
      items.value = response.items;
      total.value = response.total;
      stats.value = response.stats;
    } catch (err) {
      if (seq !== requestSeq) return;
      const message = err instanceof Error ? err.message : 'โหลดข้อมูลไม่สำเร็จ';
      error.value = message;
      snackbar.error(message);
    } finally {
      if (seq === requestSeq) loading.value = false;
    }
  }

  watch(q, () => {
    page.value = 1;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      void fetch();
    }, 300);
  });

  watch([activeFilter, page, perPage], () => {
    void fetch();
  });

  function resetFilters(): void {
    q.value = '';
    activeFilter.value = 'all';
    page.value = 1;
    void fetch();
  }

  async function save(payload: UpsertMasterItemPayload, code?: string): Promise<MasterItem> {
    const saved = code
      ? await updateMaster(kind, code, payload)
      : await createMaster(kind, payload);
    snackbar.success('บันทึกข้อมูลแล้ว');
    await fetch();
    await lookups.reload();

    return saved;
  }

  async function toggleActive(item: MasterItem, next: boolean): Promise<void> {
    const previous = item.is_active;
    item.is_active = next;
    try {
      await setMasterActive(kind, item.code, next);
      snackbar.success(next ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว');
      await fetch();
      await lookups.reload();
    } catch (err) {
      item.is_active = previous;
      snackbar.error(err instanceof Error ? err.message : 'เปลี่ยนสถานะไม่สำเร็จ');
      throw err;
    }
  }

  return {
    items,
    total,
    stats,
    loading,
    q,
    activeFilter,
    page,
    perPage,
    pageCount,
    error,
    fetch,
    resetFilters,
    save,
    toggleActive,
  };
}
