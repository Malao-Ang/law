type DateInput = string | Date | null | undefined;

function toDate(value: DateInput): Date | null {
  if (!value) return null;

  const date = value instanceof Date ? value : new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

function pad(value: number): string {
  return String(value).padStart(2, '0');
}

const THAI_MONTHS = [
  'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน',
  'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม',
  'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
];

const THAI_MONTHS_SHORT = [
  'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.',
  'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.',
  'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
];

export function formatThaiDate(value: DateInput): string {
  const date = toDate(value);
  if (!date) return '';

  const day = date.getDate();
  const month = THAI_MONTHS[date.getMonth()];
  const year = date.getFullYear() > 2400 ? date.getFullYear() : date.getFullYear() + 543;
  return `${day} ${month} ${year}`;
}

export function formatThaiDateShort(value: DateInput): string {
  const date = toDate(value);
  if (!date) return '';

  const day = date.getDate();
  const month = THAI_MONTHS_SHORT[date.getMonth()];
  const year = date.getFullYear() > 2400 ? date.getFullYear() : date.getFullYear() + 543;
  return `${day} ${month} ${year}`;
}

export function formatThaiDateTime(value: DateInput): string {
  const date = toDate(value);
  if (!date) return '';

  return `${formatThaiDate(date)} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
