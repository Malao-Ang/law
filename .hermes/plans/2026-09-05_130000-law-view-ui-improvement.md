# Plan: Improve /law/:id page UI — match Figma design

## Goal
ปรับ `/law/:id` (LawDocumentView.vue + LawInfoPanel.vue) ให้แสดงรายละเอียดประเภทกฎหมาย, สถานะ, กฎหมายที่ถูกยกเลิก, และความสัมพันธ์ระดับข้อ/มาตราตาม screenshot

## Files to modify
1. `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
2. `apps/app-laravel/resources/js/components/law/LawInfoPanel.vue`

## Task 1: Head card — เพิ่ม พ.ศ. + issuer
File: `LawDocumentView.vue` around line 110-118

ปัจจุบัน head card มี: badge, title, meta (ประกาศ date, gazette, royal_command)

เพิ่ม:
- พ.ศ. year ใต้ title (ใช้ `meta.effective_date` or `meta.promulgation_date` แปลงเป็น พ.ศ.)
- issuer line (ถ้ามี `meta.issuer`)

```vue
<p v-if="buddhistYear" class="lawx-headcard__year">พ.ศ. {{ buddhistYear }}</p>
```

computed:
```ts
const buddhistYear = computed(() => {
  const d = meta.value.effective_date || meta.value.promulgation_date;
  if (!d) return '';
  const year = new Date(d).getFullYear();
  return year > 2400 ? year : year + 543;
});
```

## Task 2: Info panel — เพิ่มฟิลด์ที่ขาด
File: `LawInfoPanel.vue`

เพิ่ม rows (ถ้ามีค่า):
- `change_status` (สถานะการเปลี่ยนแปลง) — ถ้าไม่ใช่ 'กฎหมายใหม่' หรือ ''
- อ้างอิง — ถ้ามี `meta.gazette_reference` แสดงเป็น row label="อ้างอิง"

Template ตรง `<v-card-text>` เพิ่ม:
```vue
<div v-if="meta.change_status && meta.change_status !== 'กฎหมายใหม่'" class="law-info-row py-1">
  <span class="law-info-row__label text-medium-emphasis">สถานะการเปลี่ยนแปลง</span>
  <span class="law-info-row__value font-weight-semibold">{{ meta.change_status }}</span>
</div>
<div v-if="meta.gazette_reference" class="law-info-row py-1">
  <span class="law-info-row__label text-medium-emphasis">อ้างอิง</span>
  <span class="law-info-row__value font-weight-semibold">{{ meta.gazette_reference }}</span>
</div>
```

## Task 3: กฎหมายแม่ card — ปรับ style เป็น warm card
File: `LawDocumentView.vue` around line 236-264

ปัจจุบัน `.lawx-parentcard` มี relation groups อยู่แล้ว แต่ต้องปรับ:
- เปลี่ยนสี header background เป็นสีอุ่น (#fffbeb border + icon สีทอง) สำหรับ parent/related
- กฎหมายที่ถูกยกเลิก (type=repeals) แสดง icon mdi-cancel สีแดง
- กฎหมายที่แก้ไข (type=amends) แสดง icon mdi-pencil สีม่วง

CSS เพิ่ม:
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
```

## Task 4: Section-level inline relation cards
File: `LawDocumentView.vue` around line 178-232 (inside section v-for)

ปัจจุบันมี `sectionRels` computed + template ที่แสดง inline relation card ใน section
ตรวจว่ามันทำงานถูกต้อง — ถ้าไม่แสดง ให้ตรวจ `relationsForSection()` ว่า return ค่าถูกไหม

Template ควรเป็นแบบนี้ใน section card:
```vue
<div v-if="sectionRels(section.id).length" class="lawx-section-relcard">
  <div class="lawx-section-relcard__head">
    <span class="mdi mdi-scale-balance" /> กฎหมายที่เกี่ยวข้อง
  </div>
  <div v-for="relGroup in groupedSectionRels(section.id)" :key="relGroup.type">
    <div class="lawx-section-relcard__group-label" :class="`is-${relGroup.type}`">
      {{ relGroup.label }}
    </div>
    <div v-for="rel in relGroup.items" :key="rel.id" class="lawx-section-relcard__item">
      <span class="mdi" :class="RELATION_TYPE_ICONS[rel.type]" />
      {{ rel.target_title }}
    </div>
  </div>
</div>
```

CSS:
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

.lawx-section-relcard__group-label {
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 4px;
}

.lawx-section-relcard__group-label.is-repeals { color: #dc2626; }
.lawx-section-relcard__group-label.is-amends { color: #7c3aed; }
.lawx-section-relcard__group-label.is-related { color: #2563eb; }

.lawx-section-relcard__item {
  font-size: 13px;
  padding: 4px 0;
  display: flex;
  align-items: center;
  gap: 6px;
}
```

## Verify
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel && npm run typecheck
```

## Commit
```
feat(law-view): improve public law page UI — head card year/issuer, info panel fields, relation cards styling
```
