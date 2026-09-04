# Plan: Redesign Edit Page as Document Management Hub

## Goal

แยก `/documents/:id/edit` ออกจาก `ESignDocumentWorkspace` สร้าง `EditHubWorkspace.vue` ใหม่เป็น "ศูนย์จัดการเอกสาร" ที่แตกต่างจากหน้า e-sign อย่างชัดเจน

## Current Problem

`EditPage.vue` ใช้ `ESignDocumentWorkspace` กับ `mode="edit"` → ดูเหมือน e-sign 95% ผู้ใช้สับสน

## Design (ตาม session ที่เคยคุย)

Layout: **2 columns** (ไม่มี TOC sidebar เหมือน e-sign)

```
┌─────────────────────────────────────────────────────┐
│ AppShell + Breadcrumb: เมนูหลัก / จัดการตัวบท / ชื่อ │
│ [← ย้อนกลับ]                                        │
│                                                      │
│ ┌──── Hero Card (compact meta) ──────────────────┐  │
│ │ [ประเภท] [สถานะ]  #ID                          │  │
│ │ ชื่อเอกสาร (h5)                                 │  │
│ │ ประกาศ | มีผล | ราชกิจจา | หน่วยงาน | กลุ่ม    │  │
│ └────────────────────────────────────────────────┘  │
│                                                      │
│ ┌──── Left: Actions ──────┐ ┌── Right: Info ──────┐ │
│ │                          │ │                     │ │
│ │ 📝 แก้ไขเนื้อหา        │ │ ข้อมูลเอกสาร        │ │
│ │ ตรวจทาน/แก้ไขบล็อก      │ │ (key-value)          │ │
│ │ [ปุ่ม]                  │ │                     │ │
│ │                          │ │ ──────────────────  │ │
│ │ 📋 แก้ไขข้อมูลกฎหมาย   │ │                     │ │
│ │ metadata, วันที่        │ │ ความสัมพันธ์         │ │
│ │ [ปุ่ม]                  │ │ (สรุปจำนวน+list)    │ │
│ │                          │ │                     │ │
│ │ 🔗 ความสัมพันธ์          │ │ ──────────────────  │ │
│ │ เพิ่ม/แก้ไขฉบับที่เกี่ยว │ │                     │ │
│ │ [ปุ่ม]                  │ │ ประวัติเวอร์ชัน      │ │
│ │                          │ │ (VersionTimeline)   │ │
│ │ 🔒 กำหนดสิทธิ์          │ │                     │ │
│ │ public/private          │ │ ──────────────────  │ │
│ │ [ปุ่ม]                  │ │                     │ │
│ │                          │ │ การเผยแพร่          │ │
│ │ 👁 ดูตัวอย่าง           │ │ toggle switch       │ │
│ │ preview PDF             │ │                     │ │
│ │ [ปุ่ม]                  │ └─────────────────────┘ │
│ │                          │                        │
│ │ ✍️ ส่งลงนาม e-Sign     │                        │
│ │ [ปุ่ม/disabled]         │                        │
│ └──────────────────────────┘                        │
│                                                      │
│ ┌──── Read-only Document (collapsed by default) ──┐ │
│ │ ▶ แสดงเนื้อหาเอกสาร                             │ │
│ │ (BlockFlow sections when expanded)               │ │
│ └────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────┘
```

User preferences from session:
1. Card ดูง่ายๆ
2. ดูเนื้อหา read-only ได้เลย
3. เน้น metadata + ความสัมพันธ์

## Step-by-step Tasks

### Task 1: Create `EditHubWorkspace.vue`

**File:** `apps/app-laravel/resources/js/components/edit/EditHubWorkspace.vue`

Create new component with:

**Template structure:**
- `<AppShell>` with breadcrumbs `['เมนูหลัก', 'จัดการตัวบทกฎหมาย', docTitle]`
- Back button (`router.back()`)
- Hero card: law_type chip + status chip + #ID + title (h5) + 6-cell meta grid (reuse exact same grid from ESignDocumentWorkspace lines 111-168)
- 2-column grid below hero:
  - **Left column: Action cards** — each is a `v-card flat border rounded="lg"` with icon, title, description, and button:
    1. แก้ไขเนื้อหา → `/documents/:id/review`
    2. แก้ไขข้อมูลกฎหมาย → `/documents/:id/law-info?mode=edit`
    3. ความสัมพันธ์ → `/documents/:id/relations`
    4. กำหนดสิทธิ์ → `/documents/:id/permissions`
    5. ดูตัวอย่าง → `/documents/:id/esign/preview`
    6. ส่งลงนาม e-Sign → `/documents/:id/esign`
  - **Right column: Info panel** — no tabs, all sections visible:
    1. ข้อมูลเอกสาร (key-value list, same data as ESignDocumentWorkspace lines 252-311)
    2. ความสัมพันธ์กฎหมาย summary (count + type breakdown from `documentStore.review?.relations`)
    3. ประวัติเวอร์ชัน (`VersionHistoryTimeline` component)
    4. การเผยแพร่ (publish toggle switch)
- Collapsible read-only document view: `v-expansion-panels` with BlockFlow sections (same as ESignDocumentWorkspace lines 178-212 but in expansion panel)

**Script:** Import from:
- `useDocumentStore` — fetch document
- `useVersionStore` — fetch versions
- `buildSections` from `useLawSections` — for read-only doc view
- `formatThaiDate` from `utils/thaiDate`
- `documentFileUrl` from `api/client`
- `BlockFlow` from `shared/BlockFlow.vue`
- `VersionHistoryTimeline` from `law/VersionHistoryTimeline.vue`

**Style:**
- `.edit-hub` background `#f8f7f5`
- `.edit-hub-grid` — `grid-template-columns: 1fr 380px; gap: 20px;` (responsive: single column under 900px)
- Action cards: `padding: 20px`, icon left, title bold, description caption, button right-aligned
- Info panel: sticky top

### Task 2: Update `EditPage.vue`

**File:** `apps/app-laravel/resources/js/pages/edit/EditPage.vue`

Change from:
```vue
<ESignDocumentWorkspace :document-id="documentId" mode="edit" />
```
To:
```vue
<EditHubWorkspace :document-id="documentId" />
```

### Task 3: Create `edit/` component directory

**File:** `apps/app-laravel/resources/js/components/edit/` (new directory)

Only `EditHubWorkspace.vue` goes here.

### Task 4: Verify

Run `npm run typecheck` in `apps/app-laravel`.

Commit with: `feat(edit): redesign edit page as document management hub`

## Key differences from ESign

| | Edit Hub (new) | E-Sign Page |
|---|---|---|
| TOC sidebar | ❌ no | ✅ yes |
| Document view | collapsed/expandable | full + scrollspy |
| Actions | card grid | toolbar buttons |
| Side panel | info+relations+timeline (no tabs) | tabs (info/timeline/actions) |
| "ส่งลงนาม" | link card to esign page | inline button |
| Publish toggle | in right panel | in side tab |

## Risks

- `ESignDocumentWorkspace` `mode="edit"` code paths become dead — clean up after verifying edit hub works
- BlockFlow component must work outside esign grid context — it's a standalone component, should be fine
