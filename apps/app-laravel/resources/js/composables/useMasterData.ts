import { computed, ref, watch } from 'vue';
import {
  createMaster,
  deleteMaster,
  listMaster,
  reorderMaster,
  setMasterActive,
  updateMaster,
} from '../api/masterData';
import { alertError } from '../components/master/masterDialogs';
import { useLookups } from './useLookups';
import { useSnackbarStore } from '../stores/snackbarStore';
import type { ApiRequestError } from '../api/client';
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
  const nextCode = ref('');
  const loading = ref(false);
  const refreshing = ref(false);
  const pendingCodes = ref(new Set<string>());
  const q = ref('');
  const activeFilter = ref<MasterActiveFilter>('all');
  const page = ref(1);
  const perPage = ref(10);
  const error = ref<string | null>(null);
  let searchTimer: ReturnType<typeof setTimeout> | null = null;
  let requestSeq = 0;

  const pageCount = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));

  async function fetch(silent = false, settledCodes: string[] = []): Promise<void> {
    const seq = ++requestSeq;
    loading.value = !silent;
    refreshing.value = silent;
    error.value = null;
    try {
      const response = await listMaster(kind, {
        q: q.value.trim() || undefined,
        active: activeFilter.value,
        page: page.value,
        per_page: perPage.value,
      });
      if (seq !== requestSeq) return;
      items.value = response.items.map((item) => pendingCodes.value.has(item.code) && !settledCodes.includes(item.code)
        ? items.value.find((current) => current.code === item.code) ?? item : item);
      total.value = response.total;
      stats.value = response.stats;
      nextCode.value = response.next_code;
    } catch (err) {
      if (seq !== requestSeq) return;
      const message = err instanceof Error ? err.message : 'โหลดข้อมูลไม่สำเร็จ';
      error.value = message;
      snackbar.error(message);
    } finally {
      if (seq === requestSeq) {
        loading.value = false;
        refreshing.value = false;
      }
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

  function applyItem(saved: MasterItem): void {
    const index = items.value.findIndex((item) => item.code === saved.code);
    if (index >= 0) items.value[index] = saved;
    else items.value.unshift(saved);
  }

  function beginAction(code: string): void {
    ++requestSeq;
    loading.value = false;
    refreshing.value = false;
    pendingCodes.value.add(code);
  }

  async function refreshAfterAction(codes: string[]): Promise<void> {
    void lookups.reload().catch(() => { /* Keep the last successful lookup values. */ });
    await fetch(true, codes);
  }

  async function save(payload: UpsertMasterItemPayload, code?: string): Promise<MasterItem> {
    const pendingCode = code ?? nextCode.value;
    if (pendingCodes.value.has(pendingCode)) throw new Error('กำลังบันทึกรายการนี้');
    beginAction(pendingCode);
    try {
      const saved = code
        ? await updateMaster(kind, code, payload)
        : await createMaster(kind, payload);
      applyItem(saved);
      snackbar.success('บันทึกข้อมูลแล้ว');
      await refreshAfterAction([saved.code]);
      return saved;
    } catch (err) {
      const apiError = err as ApiRequestError;
      if (Object.keys(apiError.errors ?? {}).length === 0) {
        await alertError(apiError instanceof Error ? apiError.message : 'บันทึกข้อมูลไม่สำเร็จ');
      }
      throw err;
    } finally {
      pendingCodes.value.delete(pendingCode);
    }
  }

  async function toggleActive(item: MasterItem, next: boolean): Promise<void> {
    if (pendingCodes.value.has(item.code)) return;
    beginAction(item.code);
    const previous = { ...item };
    item.is_active = next;
    try {
      applyItem(await setMasterActive(kind, item.code, next));
      snackbar.success(next ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว');
      await refreshAfterAction([item.code]);
    } catch (err) {
      applyItem(previous);
      await alertError(err instanceof Error ? err.message : 'เปลี่ยนสถานะไม่สำเร็จ');
      throw err;
    } finally {
      pendingCodes.value.delete(item.code);
    }
  }

  async function remove(item: MasterItem): Promise<void> {
    if (pendingCodes.value.has(item.code)) return;
    beginAction(item.code);
    try {
      await deleteMaster(kind, item.code);
      items.value = items.value.filter((row) => row.code !== item.code);
      total.value = Math.max(0, total.value - 1);
      snackbar.success('ลบรายการแล้ว');
      if (page.value > pageCount.value) page.value = pageCount.value;
      await refreshAfterAction([item.code]);
    } catch (err) {
      await alertError(err instanceof Error ? err.message : 'ลบรายการไม่สำเร็จ');
      throw err;
    } finally {
      pendingCodes.value.delete(item.code);
    }
  }

  async function reorder(codes: string[]): Promise<void> {
    if (codes.some((code) => pendingCodes.value.has(code))) return;
    codes.forEach(beginAction);
    try {
      await reorderMaster(kind, codes);
      const order = new Map(codes.map((code, index) => [code, index]));
      items.value = [...items.value].sort((a, b) => (order.get(a.code) ?? Infinity) - (order.get(b.code) ?? Infinity));
      await refreshAfterAction(codes);
    } catch (err) {
      await alertError(err instanceof Error ? err.message : 'บันทึกลำดับไม่สำเร็จ');
      throw err;
    } finally {
      codes.forEach((code) => pendingCodes.value.delete(code));
    }
  }

  return {
    items,
    total,
    stats,
    nextCode,
    loading,
    refreshing,
    pendingCodes,
    q,
    activeFilter,
    page,
    perPage,
    pageCount,
    error,
    applyItem,
    fetch,
    resetFilters,
    save,
    toggleActive,
    remove,
    reorder,
  };
}
