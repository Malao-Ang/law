import { computed } from 'vue';
import type { LawStatusOption, LawStatusRole } from '../api/client';
import { useLookups } from './useLookups';

const fallbackStatuses: LawStatusOption[] = [
  { title: 'มีผลบังคับใช้', value: 'STA01', code: 'STA01', color: 'success', role: 'in_force' },
  { title: 'ยกเลิกการใช้งาน', value: 'STA02', code: 'STA02', color: 'error', role: 'repealed' },
  { title: 'ร่าง', value: 'STA03', code: 'STA03', color: 'grey', role: 'draft' },
];

const fallbackAliases = new Map<string, string>([
  [normalize('มีผลบังคับใช้'), 'STA01'],
  [normalize('ยกเลิกการใช้งาน'), 'STA02'],
  [normalize('ร่าง'), 'STA03'],
  [normalize(''), 'STA03'],
]);

function normalize(value: unknown): string {
  return String(value ?? '').trim().replace(/\s+/g, ' ').toLocaleLowerCase();
}

function normalizeCode(value: unknown): string {
  return String(value ?? '').trim().toLocaleUpperCase();
}

function resolveStatus(value: unknown, items: LawStatusOption[]): LawStatusOption | null {
  const code = normalizeCode(value);
  const text = normalize(value);
  const allItems = items.length > 0 ? items : fallbackStatuses;

  const byCode = allItems.find((item) => item.code === code || item.value === code);
  if (byCode) return byCode;

  const byTitle = allItems.find((item) => normalize(item.title) === text);
  if (byTitle) return byTitle;

  const fallbackCode = fallbackAliases.get(text);
  return fallbackCode ? (allItems.find((item) => item.code === fallbackCode) ?? null) : null;
}

function codeForRole(role: Exclude<LawStatusRole, null>, items: LawStatusOption[]): string {
  return (items.find((item) => item.role === role) ?? fallbackStatuses.find((item) => item.role === role))?.code ?? '';
}

export function useLawStatus() {
  const { statuses, statusesAll } = useLookups();
  const allStatuses = computed(() => (statusesAll.value.length > 0 ? statusesAll.value : statuses.value));

  const statusItem = (value: unknown): LawStatusOption | null => resolveStatus(value, allStatuses.value);
  const statusLabel = (value: unknown): string => statusItem(value)?.title ?? String(value ?? '').trim();
  const statusColor = (value: unknown): LawStatusOption['color'] => statusItem(value)?.color ?? null;
  const statusRole = (value: unknown): LawStatusRole => statusItem(value)?.role ?? null;
  const isDraft = (value: unknown): boolean => statusRole(value) === 'draft';
  const isInForce = (value: unknown): boolean => statusRole(value) === 'in_force';
  const isRepealed = (value: unknown): boolean => statusRole(value) === 'repealed';

  const draftCode = computed(() => codeForRole('draft', allStatuses.value));
  const inForceCode = computed(() => codeForRole('in_force', allStatuses.value));
  const repealedCode = computed(() => codeForRole('repealed', allStatuses.value));

  return {
    statusItem,
    statusLabel,
    statusColor,
    statusRole,
    isDraft,
    isInForce,
    isRepealed,
    draftCode,
    inForceCode,
    repealedCode,
  };
}
