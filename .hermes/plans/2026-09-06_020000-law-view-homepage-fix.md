# Plan: Fix /law/:id sidebar layout + homepage card sections

## Goal
Adjust /law/:id right sidebar to match Figma (parent law separate card, no วันหมดอายุ, no ความสัมพันธ์รายข้อ block on top, no ความสัมพันธ์กฎหมาย button in ดำเนินการ) and fix homepage card sections (equal width, 10 items, carousel >3, sorted ประกาศ).

## Tasks

### Task 1 — Remove สารบัญมาตรา for old documents
File: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
The TOC sidebar was recently shown for old docs (commit `baa3ef3`). Revert: old docs should NOT show TOC/สารบัญ.
- Restore `v-if="!usesOriginalPdfLayout"` on the TOC card and TOC toggle buttons in subbar.
- Remove fallback message added for old docs.

### Task 2 — Homepage: equal-width cards, 10 items per section, carousel when >3, ประกาศ sort
File: `apps/app-laravel/resources/js/pages/public/ELawHomePage.vue` (or wherever homepage sections are)
1. Each type section shows up to 10 cards (not 3 or 5).
2. If section has >3 cards, use horizontal carousel with prev/next arrows.
3. If ≤3 cards, show as grid row.
4. All cards must be equal width (flex: 0 0 320px or CSS grid equal columns).
5. ประกาศ section: sort ประกาศที่ออกโดยมหาวิทยาลัย before ประกาศที่ออกโดยสภามหาวิทยาลัย.
6. Sections: กฎหมายล่าสุด (all types mixed), ระเบียบ, ประกาศ, ข้อบังคับ.

### Task 3 — /law/:id: move ความสัมพันธ์รายข้อ into ข้อมูลกฎหมาย panel
File: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
Currently the section relation summary block (`lawx-parentcard--sections`) is a separate card above ข้อมูลกฎหมาย. Move it INSIDE the LawInfoPanel or below the ข้อมูลกฎหมาย card, not as a separate top-level block.
- Remove the `<section v-if="sectionRelationSummaries.length" class="lawx-parentcard lawx-parentcard--sections">` from the aside.
- Pass `sectionRelationSummaries` as prop to LawInfoPanel and render inside it.

### Task 4 — /law/:id: กฎหมายแม่ as separate card above ข้อมูลกฎหมาย
File: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
From Figma (image 2): กฎหมายแม่ is a separate card at the very top of the right sidebar, with blue background (#eff6ff), showing:
- Icon 🏛
- Title: "พ.ร.บ. มหาวิทยาลัยบูรพา พ.ศ.2550"
- Subtitle: "มาตรา 19 — สภามหาวิทยาลัย"

This card already exists as `lawx-parent-law-card`. Ensure it stays ABOVE ข้อมูลกฎหมาย and NOT inside it.
- Remove กฎหมายแม่ row from LawInfoPanel.vue (the one using parentNames).
- Keep the separate `lawx-parent-law-card` card in LawDocumentView aside.

### Task 5 — Remove ความสัมพันธ์กฎหมาย button from ดำเนินการ
File: `apps/app-laravel/resources/js/components/law/LawInfoPanel.vue`
Remove the `🔗 ความสัมพันธ์กฎหมาย` button that was just added in commit `1a5e98a`. Keep only ดูประวัติการแก้ไข.

From Figma: ดำเนินการ section has only ดูประวัติการแก้ไข.

### Task 6 — Rename วันหมดอายุ → วันที่สิ้นสุดการใช้
File: `apps/app-laravel/resources/js/components/law/LawInfoPanel.vue`
Change label from `วันหมดอายุ` to `วันที่สิ้นสุดการใช้`. Keep the row visible when `meta.expiry_date` has value.

## Verification
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: exit 0.

## Commits
1. `fix(law-view): revert old doc TOC sidebar`
2. `fix(homepage): equal card sections with carousel and sorting`
3. `fix(law-view): move section relations into info panel and separate parent law card`
4. `fix(law-info): remove relations button and expiry date from info panel`
