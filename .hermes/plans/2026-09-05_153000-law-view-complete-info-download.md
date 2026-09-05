# Plan: ปรับ /law/:id ให้แสดงข้อมูลครบตาม Figma + download ที่ทำงานได้

## Goal
หน้า `/law/:id` (LawDocumentView + LawInfoPanel) แสดงข้อมูลครบตาม Figma: สถานะ, ประเภท, กฎหมายแม่, กฎหมายที่ถูกยกเลิก, ความสัมพันธ์รายข้อ, ปุ่มดาวน์โหลดที่ทำงานได้จริง

## Current State
- Head card: มี DocBadge + title + พ.ศ. + issuer + meta (ประกาศ/gazette/royal_command)
- Download card: มีปุ่ม "ดาวน์โหลดเอกสารต้นฉบับ" + "ดาวน์โหลด PDF" + เอกสารที่เชื่อมโยง
- Info panel (sidebar ขวา): สถานะ, change_status, วันที่, ประเภท, อ้างอิง, ออกโดย, กลุ่ม, หน่วยงาน, คำสำคัญ, จำนวนข้อ, กฎหมายที่ถูกยกเลิก
- Relations sidebar: กฎหมายแม่/เกี่ยวข้องทั้งฉบับ + ความสัมพันธ์รายข้อ

## Files
1. `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
2. `apps/app-laravel/resources/js/components/law/LawInfoPanel.vue`

## Task 1: Info Panel — เพิ่มฟิลด์ที่ขาด
File: `LawInfoPanel.vue`

เพิ่ม rows ที่ยังไม่มี (ถ้ามีค่า):
- `agencies` (หน่วยงานที่รับผิดชอบ — multi-chip) — ปัจจุบันแสดง `agency` เดี่ยว ถ้ามี `agencies[]` แสดงทั้งหมด
- `law_groups` (กลุ่มกฎหมาย — multi) — ปัจจุบันแสดง `law_group` เดี่ยว ถ้ามี `law_groups[]` แสดงทั้งหมด
- `expiry_date` (วันหมดอายุ) — ถ้ามี
- `parent_document_ids` — แสดงเป็น link ไปยังกฎหมายแม่

Template เพิ่มหลัง agency row (around line 46):
```vue
<div v-if="meta.agencies?.length > 1" class="law-info-row py-1">
  <span class="law-info-row__label text-medium-emphasis">หน่วยงานทั้งหมด</span>
  <div class="d-flex flex-wrap ga-1 justify-end">
    <v-chip v-for="a in meta.agencies" :key="a" size="x-small" variant="tonal">{{ a }}</v-chip>
  </div>
</div>
<div v-if="meta.law_groups?.length > 1" class="law-info-row py-1">
  <span class="law-info-row__label text-medium-emphasis">กลุ่มกฎหมายทั้งหมด</span>
  <div class="d-flex flex-wrap ga-1 justify-end">
    <v-chip v-for="g in meta.law_groups" :key="g" size="x-small" variant="tonal">{{ g }}</v-chip>
  </div>
</div>
<div v-if="meta.expiry_date" class="law-info-row py-1">
  <span class="law-info-row__label text-medium-emphasis">วันหมดอายุ</span>
  <span class="law-info-row__value font-weight-semibold text-error">{{ formatLawDate(meta.expiry_date) }}</span>
</div>
```

## Task 2: Download card — ตั้งชื่อไฟล์ตามชื่อกฎหมาย
File: `LawDocumentView.vue` around line 125-170

ปัจจุบัน `downloadUrl` เป็น `/api/documents/:id/file?download=1` ซึ่ง browser ตั้งชื่อเป็น documentId

เพิ่ม `download` attribute ที่มีชื่อกฎหมาย:
```vue
<v-btn
  :href="downloadUrl"
  :download="safeFileName"
  variant="tonal"
  size="small"
  prepend-icon="mdi-file-document-outline"
>
  ดาวน์โหลดเอกสารต้นฉบับ
</v-btn>
```

computed:
```ts
const safeFileName = computed(() => {
  const title = meta.value.title || documentStore.review?.source_file || props.documentId;
  return title.replace(/[/\\?%*:|"<>]/g, '_').substring(0, 100) + '.pdf';
});
```

## Task 3: ปุ่มดาวน์โหลด PDF ต้องทำงานจริง
File: `LawDocumentView.vue`

ปัจจุบันมี `handlePdfExport` + `pdfExportLoading` อยู่แล้ว ตรวจว่า:
1. `downloadPdfExport()` ถูก import
2. function ทำงานจริง (สร้าง blob + download)
3. error handling มี pdfExportError alert

ตรวจ script section ว่ามี `handlePdfExport` function:
```ts
const pdfExportLoading = ref(false);
const pdfExportError = ref('');

async function handlePdfExport(): Promise<void> {
  pdfExportLoading.value = true;
  pdfExportError.value = '';
  try {
    await downloadPdfExport(props.documentId);
  } catch (e) {
    pdfExportError.value = e instanceof Error ? e.message : 'ดาวน์โหลดไม่สำเร็จ';
  } finally {
    pdfExportLoading.value = false;
  }
}
```

ถ้ามีแล้วไม่ต้องแก้ ถ้าไม่มีให้เพิ่ม

## Task 4: กฎหมายแม่ card — ปรับ style ตาม Figma
File: `LawDocumentView.vue`

ปัจจุบัน `.lawx-parentcard` (line 241-268) แสดง "กฎหมายแม่ / เกี่ยวข้องทั้งฉบับ" อยู่แล้ว

ปรับ CSS ให้:
- Background สีอุ่น `#fffbeb` + border `#fde68a`
- Icon สีทอง `#b68d40`
- Repeals group: icon สีแดง, label สีแดง
- Amends group: icon สีม่วง

```css
.lawx-parentcard {
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 16px;
}

.lawx-parentcard__head {
  font-weight: 700;
  font-size: 14px;
  color: #92400e;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.lawx-relgroup__label.is-repeals { color: #dc2626; }
.lawx-relgroup__label.is-amends { color: #7c3aed; }
.lawx-relgroup__label.is-related { color: #2563eb; }
.lawx-relgroup__label.is-issued_under { color: #0284c7; }
.lawx-relgroup__label.is-supersedes { color: #d97706; }

.lawx-relrow__icon.is-repeals { color: #dc2626; }
.lawx-relrow__icon.is-amends { color: #7c3aed; }
```

## Task 5: Section relation card — ปรับ style
File: `LawDocumentView.vue`

ปัจจุบัน `.lawx-section-relcard` (line 207-235) มีอยู่แล้ว ตรวจ CSS:

```css
.lawx-section-relcard {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 16px;
}

.lawx-section-relcard__head {
  font-weight: 700;
  font-size: 13px;
  color: #1e3a5f;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
}

.lawx-section-relcard__group-label.is-repeals { color: #dc2626; }
.lawx-section-relcard__group-label.is-amends { color: #7c3aed; }
```

## Verify
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel && npm run typecheck
```

## Commit
```
feat(law-view): complete info panel fields, download file naming, relation card Figma styling
```

## Open Questions
- เอกสาร old (PDF นำเข้า) ดาวน์โหลดจาก `/api/documents/:id/file` ได้ตรง ๆ
- เอกสาร new ดาวน์โหลด PDF export จาก `downloadPdfExport()` — ต้องตรวจว่า endpoint ทำงาน
- ถ้า PDF export ยังไม่พร้อม (ยังไม่ export) → แสดง error "ยังไม่มี PDF — กรุณาส่ง eSign ก่อน"
