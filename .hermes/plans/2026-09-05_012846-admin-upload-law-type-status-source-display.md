# Plan: ปรับตาราง "รายการเอกสารที่นำเข้า" ให้แสดงข้อมูลถูกต้องครบถ้วน

## Goal
ปรับตาราง `/admin/upload` ให้แสดง ประเภทกฎหมาย / สถานะกฎหมาย / สถานะเผยแพร่ / ขั้นตอน pipeline อย่างถูกต้อง พร้อม header จัดกลาง และปุ่ม action ที่พาไปหน้าที่ถูกต้องตาม document type

## ตัวอย่างตารางที่ต้องการ (จำลอง)

```
┌──────┬──────────────────────────┬────────────┬─────────────┬──────────────┬──────────────┬────────────────┬────────────┐
│ ลำดับ │        เอกสาร           │ ประเภท     │ สถานะกฎหมาย │ สถานะเผยแพร่ │ ขั้นตอน      │ อัปเดตล่าสุด   │ การดำเนินการ│
├──────┼──────────────────────────┼────────────┼─────────────┼──────────────┼──────────────┼────────────────┼────────────┤
│  1   │ ประกาศ เรื่อง ค่าตอบแทน  │ [ประกาศ]   │ [ร่าง]      │ [ยังไม่เผยแพร่]│ [กรอกข้อมูล] │ 5 ก.ย. 2569   │ [กรอกข้อมูล]│
│  2   │ พ.ร.บ. มหาวิทยาลัยบูรพา │ [พ.ร.บ.]   │ [มีผลบังคับ]│ [เผยแพร่แล้ว] │ [เผยแพร่แล้ว]│ 4 ก.ย. 2569   │ [ดูเอกสาร] │
│  3   │ ระเบียบ สวัสดิการ         │ [ระเบียบ]  │ [ร่าง]      │ [ยังไม่เผยแพร่]│ [รอตรวจทาน]  │ 3 ก.ย. 2569   │ [เริ่มตรวจ] │
│  4   │ old-law.pdf              │ [รอกรอกข้อมูล]│ [ร่าง]    │ [ยังไม่เผยแพร่]│ [กรอกข้อมูล] │ 2 ก.ย. 2569   │ [แก้ไข]    │
│  5   │ scan-document.pdf        │ [รอประมวลผล]│ —          │ —            │ [กำลังประมวล] │ 1 ก.ย. 2569   │ ⏳          │
└──────┴──────────────────────────┴────────────┴─────────────┴──────────────┴──────────────┴────────────────┴────────────┘
```

คำอธิบาย columns:
- **ลำดับ** — ลำดับ row
- **เอกสาร** — ชื่อเอกสาร (align left)
- **ประเภท** — `law_type` เช่น "ประกาศ" "ระเบียบ" "พระราชบัญญัติ"; ถ้ายังไม่มี → chip "รอกรอกข้อมูล" (old) / "รอประมวลผล" (new)
- **สถานะกฎหมาย** — `LawMeta.status` = "ร่าง" / "มีผลบังคับใช้" / "ยกเลิกการใช้งาน"; ถ้ายังไม่มี meta → "—"
- **สถานะเผยแพร่** — ดูจาก `published_date`: มีวันที่ = "เผยแพร่แล้ว" (success), ว่าง = "ยังไม่เผยแพร่" (grey)
- **ขั้นตอน** — Pipeline stage chip ที่มีอยู่แล้ว (`PipelineStageChip`)
- **อัปเดตล่าสุด** — วันที่
- **การดำเนินการ** — ปุ่ม action ตาม stage + ลบ

## สีของ chip ที่ใช้

| ค่า | Chip สี |
|---|---|
| **ประเภท**: law_type ที่มีค่า (ประกาศ/ระเบียบ/ข้อบังคับ/...) | `admin-primary` tonal |
| **ประเภท**: "รอกรอกข้อมูล" (old ยังไม่กรอก) | `warning` tonal |
| **ประเภท**: "รอประมวลผล" (new ยังไม่เสร็จ) | `grey` tonal |
| **สถานะกฎหมาย**: "ร่าง" | `warning` tonal |
| **สถานะกฎหมาย**: "มีผลบังคับใช้" | `success` tonal |
| **สถานะกฎหมาย**: "ยกเลิกการใช้งาน" | `error` tonal |
| **สถานะเผยแพร่**: "เผยแพร่แล้ว" | `success` tonal |
| **สถานะเผยแพร่**: "ยังไม่เผยแพร่" | `grey` tonal |
| **ขั้นตอน**: ตาม `PipelineStageChip` เดิม | ตาม `STAGE_MAP` |

## ปุ่ม action ตาม document type

| สถานการณ์ | ปุ่ม action | route |
|---|---|---|
| เอกสารใหม่ที่ยังประมวลผล (queue/processing) | ⏳ spinner | ไม่มีปุ่ม |
| เอกสารใหม่ที่ประมวลผลเสร็จ (processed/normalize) | "เริ่มตรวจ" | `/documents/:id/review` |
| เอกสารใหม่ stage อื่น ๆ | ตาม `STAGE_MAP[stage].action` เดิม | ตาม `STAGE_MAP` |
| เอกสารเก่า stage=info | "แก้ไข" | `/documents/:id/edit` |
| เอกสารเก่า stage อื่น | ตาม `STAGE_MAP[stage].action` เดิม | ตาม `STAGE_MAP` |
| เอกสาร public | "ดูเอกสาร" | `/law/:id` |
| เอกสาร failed | ไม่มีปุ่ม | — |

## Current state / ปัญหาปัจจุบัน

ไฟล์: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`

ปัญหา:
1. Header ไม่จัดกลาง
2. ไม่มีคอลัมน์ "สถานะกฎหมาย" (`LawMeta.status`)
3. ไม่มีคอลัมน์ "สถานะเผยแพร่" (`published_date`)
4. คอลัมน์ "แหล่งที่มา" ยังมี fallback `<span>—</span>`
5. คอลัมน์ "ประเภท" ยังมี fallback `<span>—</span>` และ chip "เอกสารเก่า" ที่ไม่ต้องการ
6. `DocumentListItem` ไม่มี field `law_status` / `published_date` / `source_file` ที่ต้องใช้แสดงใหม่
7. Backend `listDocuments()` อาจไม่ส่ง `law_meta.status` / `law_meta.published_date` มากับ list — ต้องตรวจ

## Architecture

เพิ่ม field ที่ backend list response ส่ง `law_status` + `published_date` มาใน `DocumentListItem` เพื่อไม่ต้อง fetch review document ทีละ row. ถ้า `ReviewStore::listDocuments()` อ่าน law_meta อยู่แล้ว ให้เพิ่ม 2 field ในผลลัพธ์. Frontend แก้ `DocumentPipelineTable.vue` เพิ่มคอลัมน์ใหม่, ลบ `—` fallback, จัด header กลาง

## Step-by-step tasks

### Task 1 — ตรวจ backend listDocuments ว่าส่ง field อะไรบ้าง

อ่าน `apps/app-laravel/app/Services/ReviewStore.php` method `listDocuments()` เพื่อตรวจว่า response มี field อะไรบ้าง

ถ้ายังไม่มี `law_status` / `published_date` ให้เพิ่มใน method นี้:

```php
// ใน loop ที่ build document list item
$lawMeta = $review['law_meta'] ?? [];
// เพิ่ม 2 field:
'law_status' => (string) ($lawMeta['status'] ?? ''),
'published_date' => (string) ($lawMeta['published_date'] ?? ''),
```

Run:
```bash
docker compose exec laravel-app php artisan test --filter="DocumentApi"
```
(ถ้ามี test ที่เกี่ยว — ถ้าไม่มีก็ข้ามได้)

### Task 2 — เพิ่ม field ใน `DocumentListItem` type

ไฟล์: `apps/app-laravel/resources/js/types/document.ts`

เพิ่มใน interface `DocumentListItem`:
```ts
  law_status?: string | null;
  published_date?: string | null;
  source_file?: string | null;
```

### Task 3 — เพิ่ม field ใน Row interface + rows computed

ไฟล์: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`

เพิ่มใน `interface Row`:
```ts
  lawStatus: string;
  publishedDate: string;
```

เพิ่มใน `rows` computed:
```ts
  lawStatus: doc.law_status ?? '',
  publishedDate: doc.published_date ?? '',
```

### Task 4 — แก้ headers + จัด align center

ไฟล์: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`

เปลี่ยน `headers` เป็น:
```ts
const headers = [
  { title: 'ลำดับ', key: 'no', sortable: false, align: 'center' as const, width: 72 },
  { title: 'เอกสาร', key: 'title', sortable: false },
  { title: 'ประเภท', key: 'lawType', sortable: false, align: 'center' as const },
  { title: 'สถานะกฎหมาย', key: 'lawStatus', sortable: false, align: 'center' as const },
  { title: 'สถานะเผยแพร่', key: 'publishedDate', sortable: false, align: 'center' as const },
  { title: 'ขั้นตอน', key: 'stage', sortable: false, align: 'center' as const },
  { title: 'อัปเดตล่าสุด', key: 'updatedAt', sortable: false, align: 'center' as const },
  { title: 'การดำเนินการ', key: 'actions', sortable: false, align: 'center' as const },
];
```

ลบคอลัมน์ `source` (แหล่งที่มา) ออก เพราะ derive จาก law_type ได้ใน metadata page อยู่แล้ว ไม่ต้องแสดงซ้ำ

### Task 5 — แก้ template slots ให้ใช้ chip ที่มีความหมาย

ไฟล์: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`

**ลบ** slot `#item.source` ทั้งหมด

**แก้** slot `#item.lawType`:
```vue
<template #item.lawType="{ item }">
  <v-chip v-if="item.lawType" size="small" color="admin-primary" variant="tonal">
    {{ item.lawType }}
  </v-chip>
  <v-chip v-else-if="item.documentType === 'old'" size="small" color="warning" variant="tonal">
    รอกรอกข้อมูล
  </v-chip>
  <v-chip v-else size="small" color="grey" variant="tonal">
    รอประมวลผล
  </v-chip>
</template>
```

**เพิ่ม** slot `#item.lawStatus`:
```vue
<template #item.lawStatus="{ item }">
  <v-chip
    v-if="item.lawStatus"
    size="small"
    :color="item.lawStatus === 'มีผลบังคับใช้' ? 'success' : item.lawStatus === 'ยกเลิกการใช้งาน' ? 'error' : 'warning'"
    variant="tonal"
  >
    {{ item.lawStatus }}
  </v-chip>
  <span v-else class="text-caption text-medium-emphasis">—</span>
</template>
```

**เพิ่ม** slot `#item.publishedDate`:
```vue
<template #item.publishedDate="{ item }">
  <v-chip
    size="small"
    :color="item.publishedDate ? 'success' : 'grey'"
    variant="tonal"
  >
    {{ item.publishedDate ? 'เผยแพร่แล้ว' : 'ยังไม่เผยแพร่' }}
  </v-chip>
</template>
```

**คง** slot `#item.stage` เดิม (ใช้ `PipelineStageChip`)

### Task 6 — เพิ่ม CSS จัด header ตรงกลาง

ไฟล์: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`

เพิ่ม/แก้ CSS:
```css
.pipeline-table :deep(thead th) {
  color: rgba(var(--v-theme-secondary), 0.6) !important;
  font-size: 0.75rem !important;
  font-weight: 600 !important;
  text-align: center !important;
}

.pipeline-table :deep(thead th:nth-child(2)) {
  text-align: left !important;
}
```

คอลัมน์ "เอกสาร" (ที่ 2) ยังคง align left ส่วนที่เหลือ center ทั้งหมด

### Task 7 — ลบ filter แหล่งที่มา

ไฟล์: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`

ลบ `filterSource` ref + v-select แหล่งที่มาในส่วน filter bar ออก
ลบ condition `(!filterSource.value || d.source === filterSource.value)` ออกจาก `filteredDocs`

(ผู้ใช้จะเลือกแหล่งที่มาในหน้า metadata แทน ไม่ต้อง filter ที่นี่)

### Task 8 — Verify

```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
npm run build
```

Expected:
```
> typecheck
> tsc --noEmit
```
exit 0

```
✓ built in ...
```

## Risks / open questions

- **Backend `listDocuments()` ต้องตรวจว่าอ่าน review JSON ได้**: ถ้า method ปัจจุบันอ่านแค่ status JSON (ไม่อ่าน review) ต้องเพิ่มการอ่าน `law_meta` จาก review file ด้วย ซึ่งอาจกระทบ performance ถ้ามีเอกสารเยอะ → mitigation: อ่านแค่ field ที่ต้องการ (`status`, `published_date`) ไม่อ่านทั้ง review
- **เอกสารที่ยังไม่มี review file**: เอกสารที่ยัง processing จะไม่มี review file → `law_status` + `published_date` จะเป็น empty string → แสดง "—" ในคอลัมน์สถานะกฎหมาย / "ยังไม่เผยแพร่" ในเผยแพร่ ซึ่งถูกต้อง
- **คอลัมน์เยอะขึ้น**: จาก 7 เป็น 8 คอลัมน์ (ลบ source +2 ใหม่) อาจแน่นบนจอเล็ก → responsive ตาราง horizontal scroll ของ Vuetify จัดการได้
