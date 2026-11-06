export function isEmptyTableValue(value: unknown): boolean {
  return value == null || (Array.isArray(value) && value.length === 0)
    || (typeof value === 'string' && ['', '-', '—', 'ไม่ระบุ'].includes(value.trim()));
}

export function tableValue(value: unknown): unknown {
  return isEmptyTableValue(value) ? '-' : value;
}
