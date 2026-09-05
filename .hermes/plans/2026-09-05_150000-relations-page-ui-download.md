# Plan: PublicShowRelationsPage UI improvement + PDF download

## Goal
ปรับ UI หน้า `/law/relations/:documentId` ให้ดูง่ายขึ้น + เพิ่ม feature download PDF (เดี่ยว + ทั้งหมด)

## Files to modify
1. `apps/app-laravel/resources/js/pages/public/PublicShowRelationsPage.vue` — main page
2. `apps/app-laravel/resources/js/api/client.ts` — download helper (existing)

## Task 1: ปรับ ROOT card ให้ดูง่ายขึ้น
File: `PublicShowRelationsPage.vue` around line 171-210

ปัจจุบัน: card ดูเรียบ ๆ ไม่มีสี
เปลี่ยน:
- เพิ่ม DocBadge แทน chip สีเทาสำหรับ law_type
- เพิ่ม header gradient สีอ่อน (เหมือน LawDocumentView head card)
- ลบ "กฎหมายที่เลือก (ROOT)" → เปลี่ยนเป็น icon + "กฎหมายหลัก"
- meta grid: ใส่ icon + label/value format เหมือน EditHubWorkspace

```vue
<!-- ปรับ ROOT card -->
<v-card flat border rounded="xl" class="rel-root pa-6 mb-4">
  <div class="d-flex align-center ga-2 mb-2">
    <v-icon icon="mdi-scale-balance" size="20" color="primary" />
    <span class="text-caption font-weight-bold text-medium-emphasis">กฎหมายหลัก</span>
  </div>
  <h2 class="text-h6 font-weight-bold mb-3">{{ selectedRow.title }}</h2>
  <div class="d-flex flex-wrap ga-2 mb-4">
    <DocBadge v-if="lawTypeBadge" :type="lawTypeBadge" />
    <v-chip size="small" :color="statusColor" variant="tonal" rounded="lg">
      <v-icon start icon="mdi-circle" size="8" />
      {{ selectedRow.metaStatus || selectedRow.workflowStage }}
    </v-chip>
    <v-spacer />
    <!-- Download buttons -->
    <v-btn size="small" variant="outlined" prepend-icon="mdi-download" class="text-none" @click="downloadSingle">
      ดาวน์โหลด PDF
    </v-btn>
    <v-btn size="small" variant="outlined" prepend-icon="mdi-download-multiple" class="text-none" @click="downloadAll">
      ดาวน์โหลดทั้งหมด
    </v-btn>
    <v-btn size="small" variant="text" class="text-none" prepend-icon="mdi-swap-horizontal" @click="pickerOpen = true">
      เปลี่ยนกฎหมาย
    </v-btn>
  </div>
  <div class="rel-root__meta">
    <div>
      <div class="rel-root__meta-label"><v-icon icon="mdi-calendar-outline" size="14" /> วันที่ประกาศ</div>
      <div class="rel-root__meta-value">{{ displayLawDate(rootMeta?.promulgation_date) }}</div>
    </div>
    <div>
      <div class="rel-root__meta-label"><v-icon icon="mdi-calendar-check-outline" size="14" /> วันที่มีผลใช้บังคับ</div>
      <div class="rel-root__meta-value">{{ displayLawDate(rootMeta?.effective_date) }}</div>
    </div>
    <div>
      <div class="rel-root__meta-label"><v-icon icon="mdi-office-building-outline" size="14" /> หน่วยงาน</div>
      <div class="rel-root__meta-value">{{ selectedRow.org || '—' }}</div>
    </div>
    <div v-if="rootMeta?.law_group">
      <div class="rel-root__meta-label"><v-icon icon="mdi-folder-outline" size="14" /> กลุ่มกฎหมาย</div>
      <div class="rel-root__meta-value">{{ rootMeta.law_group }}</div>
    </div>
  </div>
</v-card>
```

## Task 2: เพิ่ม Download functions
File: `PublicShowRelationsPage.vue` script section

Import:
```ts
import { documentFileDownloadUrl, downloadPdfExport } from '../../api/client';
import DocBadge from '../../components/shared/DocBadge.vue';
import { lawTypeToBadge, type LawTypeBadge } from '../../components/shared/lawBadge';
```

Add computed + functions:
```ts
const lawTypeBadge = computed<LawTypeBadge | null>(() =>
  selectedRow.value ? lawTypeToBadge(selectedRow.value.lawType) : null,
);

// Download current document PDF
function downloadSingle(): void {
  if (!selectedId.value) return;
  const title = selectedRow.value?.title || selectedId.value;
  const safeName = title.replace(/[/\\?%*:|"<>]/g, '_').substring(0, 100);
  const url = documentFileDownloadUrl(selectedId.value);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${safeName}.pdf`;
  a.click();
}

// Download all related documents as individual PDFs
async function downloadAll(): Promise<void> {
  // Download root document
  downloadSingle();
  // Download each child/related document
  const children = filteredChildren.value;
  for (const child of children) {
    const safeName = (child.title || child.id).replace(/[/\\?%*:|"<>]/g, '_').substring(0, 100);
    const url = documentFileDownloadUrl(child.id);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${safeName}.pdf`;
    a.click();
    await new Promise(r => setTimeout(r, 500)); // stagger downloads
  }
}
```

## Task 3: ปรับ relation card ใน hierarchy/tree view
File: `PublicShowRelationsPage.vue`

ปัจจุบัน card ใน hierarchy view แสดง:
- ชื่อ + สถานะ + "เอกสารปัจจุบัน" badge

เพิ่ม:
- DocBadge สำหรับ law_type
- วันที่ประกาศ (ถ้ามี)
- หน่วยงาน (ถ้ามี)

## Task 4: ปรับ meta grid style
File: `PublicShowRelationsPage.vue` CSS

```css
.rel-root__meta {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 16px 20px;
}

.rel-root__meta-label {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 4px;
}

.rel-root__meta-value {
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}
```

## Verify
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel && npm run typecheck
```

## Commit
```
feat(relations): improve public relations page UI + add PDF download (single & all)
```

## Notes
- `documentFileDownloadUrl()` returns `/api/documents/:id/file?download=1` — works for both old (original PDF) and new (e-Sign exported PDF) documents
- Backend `DocumentFileController` already handles returning the right file (MinIO or local)
- Download all: stagger 500ms between downloads to avoid browser blocking multiple downloads
- File name: sanitize title to safe filename, limit 100 chars
