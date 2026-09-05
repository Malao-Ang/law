# Plan: ปรับ /law/:id ให้ตรง Figma — กฎหมายแม่ card + ย้าย download + info panel ครบ

## Goal
หน้า `/law/:id` แสดง UI ตรง Figma: กฎหมายแม่ card บนขวา, ปุ่ม download บนขวา (ไม่ใช่ card ตรงกลาง), info panel ครบทุก field, ลบ download card ออกจาก document body

## Files
1. `apps/app-laravel/resources/js/components/law/LawDocumentView.vue` — main view
2. `apps/app-laravel/resources/js/components/law/LawInfoPanel.vue` — sidebar info

## Task 1: ลบ download card จาก document body
File: `LawDocumentView.vue` around line 125-170

ลบ `<v-card class="lawx-card lawx-download-card">` ทั้ง block — ไม่ต้อง download card ตรงกลาง
ปุ่มดาวน์โหลดจะย้ายไปบน subbar (Task 2)

ลบตั้งแต่ `<v-card class="lawx-card lawx-download-card" elevation="0">` ถึง `</v-card>` ที่ปิด (around line 125-170)

## Task 2: ย้ายปุ่มดาวน์โหลดไปบน subbar (ขวาบน)
File: `LawDocumentView.vue` around line 27-44

ปัจจุบัน subbar มี: ย้อนกลับ + ซ่อนสารบัญ + ซ่อนข้อมูล + พิมพ์ + ดาวน์โหลด PDF

ปุ่ม "ดาวน์โหลด PDF" ที่มีอยู่แล้ว (line 39-42) ถูกต้องแล้ว — เป็นปุ่มสีแดง `color="error"` ตรง Figma

เปลี่ยน: ปุ่มนี้เมื่อ click → download signed PDF (ถ้ามี) หรือ source file (ถ้าเป็น old doc):
```vue
<v-btn variant="outlined" size="small" color="error" prepend-icon="mdi-file-pdf-box"
  :loading="exportingPdf"
  :disabled="exportingPdf"
  @click="downloadPdf()">ดาวน์โหลด PDF</v-btn>
```

Function `downloadPdf`:
```ts
async function downloadPdf(): Promise<void> {
  exportingPdf.value = true;
  pdfExportError.value = '';
  try {
    if (usesOriginalPdfLayout.value) {
      // Old doc: download source PDF directly
      const a = document.createElement('a');
      a.href = downloadUrl.value;
      a.download = safeFileName.value;
      a.click();
    } else {
      // New doc: download exported/signed PDF
      await downloadPdfExport(props.documentId);
    }
  } catch (e) {
    pdfExportError.value = e instanceof Error ? e.message : 'ดาวน์โหลดไม่สำเร็จ';
  } finally {
    exportingPdf.value = false;
  }
}
```

Computed:
```ts
const safeFileName = computed(() => {
  const title = meta.value.title || documentStore.review?.source_file || props.documentId;
  return title.replace(/[/\\?%*:|"<>]/g, '_').substring(0, 100) + '.pdf';
});
```

## Task 3: เพิ่ม "กฎหมายแม่" card ใน sidebar ขวา (ก่อน LawInfoPanel)
File: `LawDocumentView.vue` around line 240-304

ปัจจุบันมี `<section v-if="docRelations.length" class="lawx-parentcard">` อยู่แล้ว (line 241)
แต่ต้องเพิ่ม card "กฎหมายแม่" แบบ Figma ที่แสดง: icon + ชื่อ + มาตรา (จาก `parent_document_id` relation)

เพิ่ม ก่อน docRelations section:
```vue
<!-- กฎหมายแม่ card (Figma: สีฟ้าอ่อน บนสุด sidebar ขวา) -->
<v-card v-if="parentLawRelation" flat class="lawx-parent-law-card mb-4" rounded="lg">
  <div class="d-flex align-start ga-3 pa-4">
    <v-avatar color="primary" variant="tonal" size="36">
      <v-icon icon="mdi-bank" size="18" />
    </v-avatar>
    <div>
      <div class="text-caption font-weight-bold text-medium-emphasis">กฎหมายแม่</div>
      <a class="text-body-2 font-weight-bold d-block" style="color: #1e3a5f; cursor: pointer"
        @click="router.push(`/law/${parentLawRelation.target_document_id}`)">
        {{ parentLawRelation.target_title }}
      </a>
      <div v-if="parentLawRelation.target_section" class="text-caption text-medium-emphasis mt-1">
        {{ parentLawRelation.target_section }}
        <span v-if="parentLawRelation.note"> — {{ parentLawRelation.note }}</span>
      </div>
    </div>
  </div>
</v-card>
```

Computed:
```ts
const parentLawRelation = computed(() => {
  const rels = documentStore.review?.relations ?? [];
  return rels.find(r => r.type === 'issued_under' && r.scope === 'document') ?? null;
});
```

CSS:
```css
.lawx-parent-law-card {
  background: #eff6ff;
  border: 1px solid #bfdbfe;
}
```

## Task 4: Info panel — ตรวจว่าข้อมูลครบตาม Figma
File: `LawInfoPanel.vue`

Figma แสดง:
- สถานะ: ● มีผลใช้บังคับ ✅ มีแล้ว
- วันที่ประกาศ: 19 มกราคม 2569 ✅ มีแล้ว (Thai date format fixed)
- วันที่มีผลบังคับ: 19 มกราคม 2569 ✅ มีแล้ว
- ประเภท: ประกาศ ✅ มีแล้ว
- หน่วยงาน: สำนักนายกรัฐมนตรี ✅ มีแล้ว
- อ้างอิง: มาตรา 19 ✅ มีแล้ว (gazette_reference)

Info panel ดูครบแล้ว ตรวจ formatting:
- วันที่ต้องเป็น Thai format (แก้แล้วใน thaiDate.ts)
- สถานะมี dot icon + สี (มีแล้ว)

ไม่ต้องแก้อะไรเพิ่ม

## Task 5: ดำเนินการ section — เหลือแค่ปุ่ม "ดูประวัติการแก้ไข"
File: `LawInfoPanel.vue` around line 81-116

ลบปุ่ม "ดูโครงสร้างความสัมพันธ์ (Hierarchy)" (line 105-114) ออก เหลือแค่ "ดูประวัติการแก้ไข" ปุ่มเดียว

ลบ:
```vue
        <v-btn
          flat
          variant="outlined"
          prepend-icon="mdi-sitemap"
          class="justify-start text-none"
          :disabled="!viewedDocumentId"
          :to="viewedDocumentId ? `/law/relations/${encodeURIComponent(viewedDocumentId)}` : undefined"
        >
          ดูโครงสร้างความสัมพันธ์ (Hierarchy)
        </v-btn>
```

## Verify
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel && npm run typecheck
```

## Commit
```
feat(law-view): match Figma — parent law card, move download to subbar, remove body download card
```
