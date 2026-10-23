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

export async function confirmDeactivate(item: DialogItem): Promise<boolean> {
  const result = await Swal.fire({
    icon: 'warning',
    title: `ปิดใช้งาน "${escapeHtml(item.name)}"?`,
    html: (item.usage_count ?? 0) > 0
      ? `จะไม่แสดงในตัวกรองการค้นหาและตัวเลือกใหม่ เอกสาร ${usageText(item)} ฉบับที่ใช้อยู่ยังแสดงค่าเดิม`
      : `รายการนี้จะไม่แสดงเป็นตัวเลือกในระบบ<br>เอกสารที่ใช้อยู่ ${usageText(item)} รายการจะยังคงแสดงค่าเดิม`,
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
    title: 'ไม่สามารถปิดใช้งานได้',
    html: `"${escapeHtml(item.name)}" มีเอกสารใช้งานอยู่ ${usageText(item)} รายการ`,
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

export async function confirmDelete(item: DialogItem): Promise<boolean> {
  const result = await Swal.fire({
    icon: 'warning',
    title: `ลบ "${escapeHtml(item.name)}" ถาวร?`,
    showCancelButton: true,
    confirmButtonText: 'ลบถาวร',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#b42318',
    cancelButtonColor: '#64748b',
  });
  return result.isConfirmed;
}
