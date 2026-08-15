type DateInput = string | Date | null | undefined;

function toDate(value: DateInput): Date | null {
  if (!value) return null;

  const date = value instanceof Date ? value : new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

function pad(value: number): string {
  return String(value).padStart(2, '0');
}

export function formatThaiDate(value: DateInput): string {
  const date = toDate(value);
  if (!date) return '';

  return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear() + 543}`;
}

export function formatThaiDateTime(value: DateInput): string {
  const date = toDate(value);
  if (!date) return '';

  return `${formatThaiDate(date)} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
