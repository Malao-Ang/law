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
  const change = (changeStatus ?? '').trim();
  const use = (status ?? '').trim();

  if (use === 'ยกเลิกการใช้งาน') return { variant: 'cancelled', label: 'ยกเลิกแล้ว' };
  if (change.startsWith('ยกเลิก')) return { variant: 'partial', label: 'ยกเลิกบางส่วน' };
  if (change.startsWith('ปรับปรุง')) return { variant: 'revise', label: change };
  return { variant: 'new', label: '' };
}
