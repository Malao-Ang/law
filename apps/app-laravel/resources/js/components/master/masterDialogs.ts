import Swal from 'sweetalert2';
import type { MasterItem } from '../../types/masterData';

type DialogItem = Pick<MasterItem, 'name' | 'usage_count'>;

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function usageText(item: DialogItem): string {
  return (item.usage_count ?? 0).toLocaleString('th-TH');
}

export function isLockedByUsage(item: Pick<MasterItem, 'is_system' | 'is_active' | 'usage_count'>): boolean {
  return !item.is_system && item.is_active && (item.usage_count ?? 0) > 0;
}

export async function confirmDeactivate(item: DialogItem): Promise<boolean> {
  const result = await Swal.fire({
    icon: 'warning',
    title: `ปิดใช้งาน "${escapeHtml(item.name)}"?`,
    html: `รายการนี้จะไม่แสดงเป็นตัวเลือกในระบบ<br>เอกสารที่ใช้อยู่ ${usageText(item)} รายการจะยังคงแสดงค่าเดิม`,
    showCancelButton: true,
    confirmButtonText: 'ปิดใช้งาน',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#b42318',
    cancelButtonColor: '#64748b',
  });

  return result.isConfirmed;
}

export async function alertInUse(item: DialogItem): Promise<void> {
  await Swal.fire({
    icon: 'warning',
    title: `ไม่สามารถปิดใช้งาน "${escapeHtml(item.name)}" ได้`,
    html: `มีเอกสารใช้งานรายการนี้อยู่ ${usageText(item)} รายการ จึงไม่สามารถปิดใช้งานได้`,
    confirmButtonText: 'ตกลง',
    confirmButtonColor: '#475467',
  });
}

export async function alertError(message: string): Promise<void> {
  await Swal.fire({
    icon: 'error',
    title: 'ดำเนินการไม่สำเร็จ',
    text: message,
    confirmButtonText: 'ตกลง',
    confirmButtonColor: '#475467',
  });
}
