# Plan: Public-facing "แสดงความสัมพันธ์" page for end users

## Goal

สร้างหน้า "แสดงความสัมพันธ์" ฝั่ง public (user) แยกจากหน้า admin `/admin/show-relations` โดย user เข้าถึงผ่าน public route ที่มี layout แตกต่างจาก admin

## Current Context / สิ่งที่มีอยู่แล้ว

| หน้า | Route | ผู้ใช้ | Layout | คำอธิบาย |
|------|-------|--------|--------|----------|
| `AdminShowRelationsPage.vue` | `/admin/show-relations/:documentId?` | Admin | `AppShell` (admin sidebar, breadcrumbs, bell icon) | เลือกกฎหมาย → ดู hierarchy/tree ของกฎหมายลำดับรอง — **data + logic สมบูรณ์แล้ว** |
| `LawRelationsPage.vue` | `/documents/:documentId/relations` | Admin (workflow step 5/6) | `AppShell` + `WorkflowFooterBar` | ขั้นตอนนำเข้า: เพิ่ม/ลบ relation, เลือก parent — **editor ไม่ใช่ view** |
| `LawVersionsPage.vue` | `/law/:documentId/versions` | Public user | `ELawNavbar` + `ELawFooter` (public law layout) | ดู version timeline + relation list (read-only) — **มีแล้วแต่เป็น per-document** |
| `LawPage.vue` | `/law/:documentId` | Public user | `ELawNavbar` + `ELawFooter` | อ่านเอกสารกฎหมาย, sidebar มีปุ่ม "ดูเวอร์ชันและความสัมพันธ์" → link ไป `/law/:id/versions` |

### สรุป

- **ฝั่ง admin** มี show-relations (hierarchy view) สมบูรณ์แล้ว — `AdminShowRelationsPage.vue` + composable `useShowRelations.ts`
- **ฝั่ง user/public** มี per-document versions + relations (`LawVersionsPage.vue`) แต่ **ไม่มีหน้า browse + hierarchy** เหมือน admin
- Business logic สำหรับ build tree, filter, stat, search อยู่ใน `composables/useShowRelations.ts` — reusable ได้เลย
- ต้องสร้างหน้า public ใหม่ที่ wrap logic เดียวกัน แต่ใช้ **public layout** (`ELawNavbar` / `ELawFooter`) แทน `AppShell`

## Architecture / Proposed Approach

สร้าง `PublicShowRelationsPage.vue` ใน `pages/public/` ที่ reuse composable `useShowRelations.ts` + component `RelationTreeView.vue` / `HierarchyList.vue` แต่ wrap ด้วย `ELawNavbar` + `ELawFooter` (เหมือน `LawVersionsPage.vue` / `LawPage.vue`) ลงทะเบียน route `/law/relations/:documentId?` ให้ user เข้าถึงได้จาก public search result หรือ LawPage sidebar

## Step-by-step Tasks

### Task 1: Create `PublicShowRelationsPage.vue`

**File:** `apps/app-laravel/resources/js/pages/public/PublicShowRelationsPage.vue`

Copy structure จาก `AdminShowRelationsPage.vue` แต่:
- Replace `<AppShell>` → `<ELawNavbar>` + `<ELawFooter>` + `<v-container>` wrapper (เหมือน `LawVersionsPage.vue`)
- Remove admin-specific features: bell icon, admin breadcrumbs, admin color scheme
- Keep: search/filter/list view, detail view with hierarchy/tree, picker dialog
- Style: match public law pages (light background, `max-width:1200px`)

Script section: import จาก `useShowRelations.ts` เหมือนเดิม, เปลี่ยน import AppShell → ELawNavbar/ELawFooter

### Task 2: Register public route

**File:** `apps/app-laravel/resources/js/router/index.ts`

```ts
// Add import
const PublicShowRelationsPage = () => import('../pages/public/PublicShowRelationsPage.vue');

// Add route (after law-versions)
{ path: '/law/relations/:documentId?', name: 'public-show-relations', component: PublicShowRelationsPage, props: true, meta: { bareLayout: true } },
```

**หมายเหตุ:** route ต้องอยู่ก่อน `/law/:documentId` เพื่อไม่ให้ `/law/relations` ถูก match เป็น documentId

### Task 3: Add navigation link from public pages

**3a.** `apps/app-laravel/resources/js/components/law/LawInfoPanel.vue`

เพิ่มปุ่ม "ดูโครงสร้างความสัมพันธ์" ข้างใต้ปุ่ม "ดูเวอร์ชันและความสัมพันธ์" ที่มีอยู่:

```vue
<v-btn
  block flat variant="outlined"
  prepend-icon="mdi-sitemap"
  class="justify-start text-none mt-2"
  :disabled="!viewedDocumentId"
  :to="viewedDocumentId ? `/law/relations/${encodeURIComponent(viewedDocumentId)}` : undefined"
>
  ดูโครงสร้างความสัมพันธ์ (Hierarchy)
</v-btn>
```

**3b.** `apps/app-laravel/resources/js/pages/law/LawVersionsPage.vue`

เพิ่มปุ่ม link ไป public-show-relations จากหน้า versions:

```vue
<v-btn size="small" variant="outlined" prepend-icon="mdi-sitemap" class="text-none"
  :to="`/law/relations/${encodeURIComponent(props.documentId)}`">
  ดูโครงสร้างความสัมพันธ์ (Hierarchy)
</v-btn>
```

**3c.** `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`

(Optional) เพิ่มลิ้ง "ดูความสัมพันธ์" ในรายการผลค้นหา — ถ้า document มี relations

### Task 4: Style adjustments

ปรับ style ของ `PublicShowRelationsPage.vue` ให้ match public theme:
- Background: `#f8fafc` (เหมือน `.lvp`)
- Color scheme: ใช้ `primary` แทน `admin-primary`
- Card rounded corners, border, no elevation (เหมือน LawVersionsPage)
- Top bar with back button (เหมือน LawVersionsPage `.lvp-topbar`)

### Task 5: ตรวจสอบว่า composable/component reuse ได้จริง

Files ที่ reuse:
- `composables/useShowRelations.ts` — ใช้ได้ตรงๆ (ไม่มี admin dependency)
- `components/admin/RelationTreeView.vue` — ย้ายไป `shared/` หรือ import จาก admin ก็ได้ (ไม่มี admin-specific logic)
- `components/admin/HierarchyList.vue` — เช่นเดียวกัน

**แนะนำ:** ย้าย `RelationTreeView.vue` และ `HierarchyList.vue` จาก `components/admin/` → `components/shared/` เพื่อให้ทั้ง admin และ public import ได้ แล้ว update import path ใน `AdminShowRelationsPage.vue`

### Task 6: Verify

```bash
cd apps/app-laravel && npm run typecheck
```

ตรวจสอบ browser:
1. `/law/relations` → แสดงรายการกฎหมายทั้งหมด (public layout)
2. `/law/relations/:documentId` → แสดง hierarchy ของกฎหมายที่เลือก
3. `/admin/show-relations` → ยังทำงานปกติ (admin layout)
4. `/law/:documentId` → sidebar ปุ่ม "ดูโครงสร้างความสัมพันธ์" link ไปถูกที่

## Risks, Tradeoffs, and Open Questions

| Item | Notes |
|------|-------|
| **Route ordering** | `/law/relations/:documentId?` ต้องอยู่ก่อน `/law/:documentId` ไม่งั้น "relations" จะถูก match เป็น documentId |
| **Component location** | ย้าย RelationTreeView/HierarchyList ไป shared/ จะเปลี่ยน import path ใน AdminShowRelationsPage — safe แต่ต้อง update |
| **Data source** | `useShowRelations.ts` เรียก `fetchReportSummary()` ที่ return `documents[]` — public user เข้าถึง API นี้ได้หรือไม่? ตรวจสอบ ReportController ว่าไม่มี auth guard |
| **Performance** | หน้า admin load ทุก document เข้า memory สำหรับ tree build — ถ้า document เยอะอาจต้อง lazy load ใน public (scope นอก plan นี้) |

## Open Question

- **ต้องการให้ public user มีสิทธิ์ edit relations ด้วยไหม** หรือเป็น read-only view เท่านั้น? → plan นี้สมมติ read-only เหมือน LawVersionsPage
- **ต้องการ link จาก search results (LawDatabasePage) ด้วยไหม** หรือแค่จาก LawPage sidebar?
