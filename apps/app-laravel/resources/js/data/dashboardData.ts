// apps/app-laravel/resources/js/data/dashboardData.ts
// ponytail: static mock — no API backs these widgets yet (POC). Wire real data later.

export interface MetricCard {
  label: string;
  value: string;
  footnote: string;
  accent: string;   // vuetify color token for the label / value
  filled?: boolean;  // navy inverted card
}

export interface CompletenessRow {
  label: string;
  count: string;     // e.g. "920 ฉบับ"
  linkedPct: number; // 0-100, blue portion; remainder is the red gap
}

export interface UrgentAlert {
  id: string;
  level: 'error' | 'warning';
  title: string;
  sub: string;
  actionLabel: string;
}

export type MappingStatus = 'done' | 'ocr-wait';

export interface RecentImportRow {
  id: string;
  name: string;
  mapping: MappingStatus;
  complexity: string;         // e.g. "สูง (24 มาตราสั่งการ)"
  actionLabel: string;        // e.g. "เปิดดูผัง"
  actionIcon: string;         // mdi icon
  actionColor: string;        // vuetify color token
}

export const METRIC_CARDS: MetricCard[] = [
  { label: 'จุดเสี่ยง: ขาดกฎหมายลูก', value: '84', footnote: '* มาตราที่สั่งให้ออกกฎหมายลูก แต่ยังไม่มีในระบบ', accent: 'error' },
  { label: 'รอปรับปรุง (LEGACY LINK)', value: '216', footnote: '* กฎหมายลูกที่อ้างอิง พ.ร.บ. ฉบับที่ยกเลิกแล้ว', accent: 'warning' },
  { label: 'คิวประมวลผล OCR', value: '12', footnote: '* จำนวนไฟล์ PDF ที่รอยืนยันความถูกต้อง', accent: 'primary' },
  { label: 'ความสัมพันธ์ที่บันทึกแล้ว', value: '12,402', footnote: 'เส้นเชื่อมโยง (Linkage)', accent: 'elaw-gold', filled: true },
];

export const COMPLETENESS_ROWS: CompletenessRow[] = [
  { label: 'พ.ร.บ. (พระราชบัญญัติ)', count: '920 ฉบับ', linkedPct: 90 },
  { label: 'พ.ร.ฎ. (พระราชกฤษฎีกา)', count: '4,120 ฉบับ', linkedPct: 75 },
];

export const URGENT_ALERTS: UrgentAlert[] = [
  { id: 'a1', level: 'error', title: 'พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคลฯ (ฉบับใหม่)', sub: 'พบ 12 มาตรา สั่งให้ออกกฎหมายลูกแต่ยังไม่มีข้อมูล', actionLabel: 'จัดการ' },
  { id: 'a2', level: 'warning', title: 'พ.ร.บ. การศึกษาแห่งชาติ พ.ศ. 2562', sub: 'กฎหมายลูก 5 ฉบับ อ้างอิงมาตราที่ถูกยกเลิกแล้ว', actionLabel: 'ตรวจสอบ' },
];

export const RECENT_IMPORTS: RecentImportRow[] = [
  { id: 'r1', name: 'พ.ร.บ. พลังงานนิวเคลียร์เพื่อสันติ (ฉบับที่ ๒)', mapping: 'done', complexity: 'สูง (24 มาตราสั่งการ)', actionLabel: 'เปิดดูผัง', actionIcon: 'mdi-sitemap-outline', actionColor: 'admin-primary' },
  { id: 'r2', name: 'ระเบียบกฤษฎีกาว่าด้วยการบริหารความเสี่ยงฯ', mapping: 'ocr-wait', complexity: 'ต่ำ (2 มาตราสั่งการ)', actionLabel: 'ตรวจสอบเอกสาร', actionIcon: 'mdi-eye-outline', actionColor: 'warning' },
];

export const MAPPING_META: Record<MappingStatus, { label: string; color: string }> = {
  'done': { label: 'เสร็จสมบูรณ์', color: 'success' },
  'ocr-wait': { label: 'รอตรวจสอบ OCR', color: 'info' },
};
