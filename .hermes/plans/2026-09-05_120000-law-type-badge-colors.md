# Plan: Law Type Badge — สี + tag ครบทุกประเภท (Homepage + Database)

## Goal
ทุก card ทั้งหน้า homepage และ /database แสดง tag ประเภทกฎหมาย (ระเบียบ/ประกาศ/ข้อบังคับ/กฎกระทรวง/พ.ร.บ. ฯลฯ) ด้วยสีที่แตกต่างกันชัดเจน ไม่มี "กฎหมายภายนอก" เป็น catch-all

## Current State

### DocType enum (lawBadge.ts)
มีแค่ 5 type: rabiap | kho-bangkhab | prakat | kotmai-phaainok | other
ปัญหา: law_type จริงมี ~10 ค่า แต่ทุกอัน external map ไปหา kotmai-phaainok = badge "กฎหมายภายนอก"

### DocBadge.vue
มีสีแค่ 4 type: กฎหมายภายนอก/ระเบียบ/ข้อบังคับ/ประกาศ
ขาด: กฎกระทรวง, พ.ร.บ., พระราชกำหนด, ประกาศกระทรวง, คำสั่ง, มติ

### LawDatabasePage.vue
มี `lawTypeBadgeKey()` + `DocBadge` อยู่แล้ว ที่ line 331
LAW_TYPE_TO_BADGE มีค่าบางส่วน แต่ไม่ครบ

## Canonical law_type values (จาก lookups.php / law-types-and-statuses.md)

| law_type | source | กลุ่ม | สีที่ควรใช้ |
|---|---|---|---|
| ประกาศ | internal | internal | #fb923c (ส้ม) |
| ระเบียบ | internal | internal | #3b82f6 (น้ำเงิน) |
| ข้อบังคับ | internal | internal | #10b981 (เขียว) |
| ประกาศที่ออกโดยมหาวิทยาลัย | internal | internal | #f59e0b (เหลืองส้ม) |
| ประกาศที่ออกโดยสภามหาวิทยาลัย | internal | internal | #d97706 (เหลืองเข้ม) |
| พระราชบัญญัติ | external | external | #7c3aed (ม่วง) |
| พระราชกำหนด | external | external | #6d28d9 (ม่วงเข้ม) |
| กฎกระทรวง | external | external | #2563eb (น้ำเงินเข้ม) |
| ประกาศกระทรวง | external | external | #0369a1 (ฟ้าเข้ม) |
| คำสั่ง | — | — | #64748b (เทา) |
| มติ | — | — | #475569 (เทาเข้ม) |

## Tasks

### Task 1: ขยาย DocBadge.vue — เพิ่ม BadgeType + สีครบ
File: `apps/app-laravel/resources/js/components/shared/DocBadge.vue`

เพิ่ม types และสีใน STYLES:
```ts
type BadgeType =
  | 'กฎหมายภายนอก' | 'ระเบียบ' | 'ข้อบังคับ' | 'ประกาศ'
  | 'ประกาศมหาวิทยาลัย' | 'ประกาศสภา'
  | 'พระราชบัญญัติ' | 'พระราชกำหนด' | 'กฎกระทรวง' | 'ประกาศกระทรวง'
  | 'คำสั่ง' | 'มติ'
  | 'ใหม่ล่าสุด' | 'ปรับปรุงรายมาตรา' | 'ปรับปรุงทั้งฉบับ' | 'ยกเลิกบางส่วน' | 'ยกเลิกแล้ว';

const STYLES: Record<BadgeType, { bg: string; fg: string; icon: string }> = {
  // Internal
  'ระเบียบ':                    { bg: '#3b82f6', fg: '#ffffff', icon: 'mdi-file-document-outline' },
  'ข้อบังคับ':                   { bg: '#10b981', fg: '#ffffff', icon: 'mdi-gavel' },
  'ประกาศ':                     { bg: '#fb923c', fg: '#ffffff', icon: 'mdi-bullhorn-outline' },
  'ประกาศมหาวิทยาลัย':           { bg: '#f59e0b', fg: '#ffffff', icon: 'mdi-school-outline' },
  'ประกาศสภา':                   { bg: '#d97706', fg: '#ffffff', icon: 'mdi-bank-outline' },
  // External
  'พระราชบัญญัติ':               { bg: '#7c3aed', fg: '#ffffff', icon: 'mdi-scale-balance' },
  'พระราชกำหนด':                 { bg: '#6d28d9', fg: '#ffffff', icon: 'mdi-shield-crown-outline' },
  'กฎกระทรวง':                   { bg: '#2563eb', fg: '#ffffff', icon: 'mdi-office-building-outline' },
  'ประกาศกระทรวง':               { bg: '#0369a1', fg: '#ffffff', icon: 'mdi-bullhorn-variant-outline' },
  'กฎหมายภายนอก':               { bg: '#854d0e', fg: '#ffffff', icon: 'mdi-bank' },
  // Other
  'คำสั่ง':                     { bg: '#64748b', fg: '#ffffff', icon: 'mdi-gavel' },
  'มติ':                        { bg: '#475569', fg: '#ffffff', icon: 'mdi-vote-outline' },
  // Status badges
  'ใหม่ล่าสุด':                  { bg: '#eebf6d', fg: '#271900', icon: 'mdi-star' },
  'ปรับปรุงรายมาตรา':             { bg: '#eef2ff', fg: '#4f46e5', icon: 'mdi-file-document-edit-outline' },
  'ปรับปรุงทั้งฉบับ':             { bg: '#f0f9ff', fg: '#0284c7', icon: 'mdi-history' },
  'ยกเลิกบางส่วน':               { bg: '#fffbeb', fg: '#d97706', icon: 'mdi-content-cut' },
  'ยกเลิกแล้ว':                  { bg: '#e11d48', fg: '#ffffff', icon: 'mdi-close-circle-outline' },
};
```

### Task 2: ขยาย lawBadge.ts — map law_type → badge label ครบ
File: `apps/app-laravel/resources/js/components/shared/lawBadge.ts`

```ts
export type LawTypeBadge =
  | 'ระเบียบ' | 'ข้อบังคับ' | 'ประกาศ' | 'ประกาศมหาวิทยาลัย' | 'ประกาศสภา'
  | 'พระราชบัญญัติ' | 'พระราชกำหนด' | 'กฎกระทรวง' | 'ประกาศกระทรวง'
  | 'กฎหมายภายนอก' | 'คำสั่ง' | 'มติ';

const LAW_TYPE_TO_BADGE: Record<string, LawTypeBadge> = {
  ระเบียบ: 'ระเบียบ',
  ข้อบังคับ: 'ข้อบังคับ',
  ประกาศ: 'ประกาศ',
  'ประกาศที่ออกโดยมหาวิทยาลัย': 'ประกาศมหาวิทยาลัย',
  'ประกาศที่ออกโดยสภามหาวิทยาลัย': 'ประกาศสภา',
  พระราชบัญญัติ: 'พระราชบัญญัติ',
  'พ.ร.บ.': 'พระราชบัญญัติ',
  phrb: 'พระราชบัญญัติ',
  พระราชกำหนด: 'พระราชกำหนด',
  กฎกระทรวง: 'กฎกระทรวง',
  ประกาศกระทรวง: 'ประกาศกระทรวง',
  'kotmai-phaainok': 'กฎหมายภายนอก',
  คำสั่ง: 'คำสั่ง',
  command: 'คำสั่ง',
  มติ: 'มติ',
  resolution: 'มติ',
};
```

### Task 3: ELawLawCard.vue — ใช้ law_type แทน docType สำหรับ badge
File: `apps/app-laravel/resources/js/components/shared/ELawLawCard.vue`

เพิ่ม prop `lawType?: string` และแสดง badge จาก law_type ก่อน ถ้าไม่มีจึง fallback docType:

```ts
// เพิ่ม prop
lawType: { type: String, default: '' },

// computed badge
const typeBadge = computed<LawTypeBadge | null>(() => {
  if (props.lawType) return LAW_TYPE_TO_BADGE[props.lawType] ?? docTypeToBadge(props.docType);
  return docTypeToBadge(props.docType);
});
```

### Task 4: Pass law_type ใน PublicHomePage.vue
File: `apps/app-laravel/resources/js/pages/public/PublicHomePage.vue`

`DocumentVersion.metadata` มี `documentType` (DocType) แต่ไม่มี `law_type` string ตรงๆ
แก้ `buildDocumentVersion()` ให้เก็บ raw `law_type` ใน metadata custom field หรือ pass ผ่าน prop ใหม่

หรือ approach ง่ายกว่า: เพิ่ม field `lawTypeName?: string` ใน `DocumentVersion.metadata` type แล้ว pass จาก search result

### Task 5: LawDatabasePage.vue — ตรวจ LAW_TYPE_TO_BADGE ครบ
File: `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`

ตรวจ `lawTypeBadgeKey()` และ `LAW_TYPE_TO_BADGE` ว่าครบไหม เพิ่ม mapping ที่ขาด

### Task 6: Border-left colors — ELawLawCard.vue
เพิ่ม CSS class สำหรับ external types ใหม่:
```css
.elaw-card--prb          { border-left-color: #7c3aed; }
.elaw-card--phrk         { border-left-color: #6d28d9; }
.elaw-card--kotmai-krw   { border-left-color: #2563eb; }
.elaw-card--prakat-krw   { border-left-color: #0369a1; }
.elaw-card--command      { border-left-color: #64748b; }
.elaw-card--resolution   { border-left-color: #475569; }
```

## Verify
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel && npm run typecheck
```

## Commit
```
feat(badges): full law-type badge colors for all types on homepage + database cards
```
