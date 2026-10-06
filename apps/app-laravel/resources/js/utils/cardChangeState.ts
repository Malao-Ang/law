import { useLawStatus } from '../composables/useLawStatus';
import { createChangeStatusCatalog } from '../composables/useChangeStatus';

const _cs = createChangeStatusCatalog({ types: [], typesAll: [], details: [], detailsAll: [] });

export type CardChangeVariant = 'cancelled' | 'partial' | 'revise' | 'new';

export interface CardChangeState {
  variant: CardChangeVariant;
  label: string;
}

/**
 * Map a document's raw change_status + enforcement status to one content-box variant.
 * First match wins. Unknown/empty → 'new' (green agency strip / default).
 */
export function cardChangeState(changeStatus?: string | null, status?: string | null): CardChangeState {
  const { isRepealed } = useLawStatus();
  const change = (changeStatus ?? '').trim();
  const use = (status ?? '').trim();

  if (isRepealed(use)) return { variant: 'new', label: '' };
  if (change.startsWith('ยกเลิก')) return { variant: 'partial', label: 'ยกเลิกบางส่วน' };
  if (_cs.isWhole(change) || _cs.isSection(change)) return { variant: 'revise', label: _cs.label(change) };
  return { variant: 'new', label: '' };
}
