# Plan: แก้ไข /law/:id + database card + admin/upload + download filename — batch fix

## Goal
แก้ 8 จุดค้างทั้งหมดใน 1 batch: keywords ใน database card, ชื่อไฟล์ download, admin/upload law status dash, /law/:id breadcrumb+header+parent law card+สารบัญเอกสารเก่า+เอกสารที่อ้างถึง tag

## Files involved
- `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php` (backend: add keywords to search result)
- `apps/app-laravel/resources/js/types/lawSearch.ts` (type: add keywords field)
- `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue` (frontend: show keywords chips + highlight)
- `apps/app-laravel/resources/js/components/law/LawDocumentView.vue` (frontend: breadcrumb visible, header detail, parent law card above info, TOC for old docs, เอกสารที่อ้างถึง tag)
- `apps/app-laravel/resources/js/components/law/LawInfoPanel.vue` (frontend: move parent law above info card as separate card)
- `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue` (frontend: law status dash → chip)
- `apps/app-laravel/resources/js/pages/public/PublicShowRelationsPage.vue` (frontend: download filename)

## Step-by-step tasks

### Task 12 — Keywords ใน database search result card + highlight

**12a) Backend: add `keywords` to file-based search result**
File: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php`
In the `documents()` method inside `array_map` (around line 172-192), add after `'snippets'`:
```php
'keywords' => array_values(array_filter((array) ($r['keywords'] ?? []), 'is_string')),
```

**12b) Type: add keywords to LawSearchResult**
File: `apps/app-laravel/resources/js/types/lawSearch.ts`
Add to `LawSearchResult` interface after `snippets: string[];`:
```ts
keywords?: string[];
```

**12c) Frontend: show keyword chips in database card**
File: `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`
After the `.law-list-card__meta` div (around line 366), before `.law-list-card__children`, add:
```vue
<div v-if="law.keywords?.length" class="law-list-card__keywords">
  <v-icon size="12" icon="mdi-tag-outline" class="mr-1" />
  <v-chip
    v-for="kw in law.keywords.slice(0, 5)"
    :key="kw"
    size="x-small"
    variant="tonal"
    color="primary"
    rounded="pill"
    class="law-keyword-chip"
  >
    <span v-html="highlightKeyword(kw)"></span>
  </v-chip>
</div>
```

Add CSS:
```css
.law-list-card__keywords {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  margin-top: 6px;
}
.law-keyword-chip { font-size: 11px !important; }
```

Add function in `<script setup>`:
```ts
function highlightKeyword(kw: string): string {
  if (!query.value) return kw;
  const q = query.value.trim();
  if (!q) return kw;
  const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return kw.replace(new RegExp(`(${escaped})`, 'gi'), '<mark>$1</mark>');
}
```

### Task 13 — ชื่อไฟล์ download ใน /law/relations

File: `apps/app-laravel/resources/js/pages/public/PublicShowRelationsPage.vue`
Search for download functions (`downloadRowPdf` or similar). Ensure the downloaded file uses the law title as filename, not the document ID. If the anchor.download uses `row.id`, change to:
```ts
const safeName = (row.title || row.id).replace(/[/\\?%*:|"<>]/g, '_').substring(0, 100) + '.pdf';
```

### Task 15 — admin/upload law status `—` → chip

File: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`
Line 82 currently shows:
```vue
<span v-else class="text-caption text-medium-emphasis">—</span>
```
Replace with:
```vue
<v-chip v-else size="small" color="warning" variant="tonal">ร่าง</v-chip>
```

### Task 19 — /law/:id เอกสารเก่าแสดง "ประกาศ" เปล่า

File: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
The header card (line ~112) shows `meta.title || documentStore.review.source_file`. For old docs with no title, use `source_file` (which is the PDF filename without extension). This is already correct — the issue is that `meta.title` is empty and `source_file` shows the raw filename.

Change line ~112:
```vue
<h1 class="lawx-headcard__title">{{ meta.title || cleanSourceFile || 'เอกสาร' }}</h1>
```

Add computed:
```ts
const cleanSourceFile = computed(() => {
  const raw = documentStore.review?.source_file || '';
  return raw.replace(/\.[^.]+$/, '').replace(/_/g, ' ');
});
```

### Task 20 — กฎหมายแม่เป็น card แยกเหนือข้อมูลกฎหมาย

File: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
The parent law card (line ~189) is already inside `<aside>` above `LawInfoPanel`. This is correct per Figma. But it currently uses `parentLawRelation` which only finds `issued_under` type. Also needs to show parent names from `parentNames` computed too.

Ensure the parent law card appears FIRST in the aside, before `<section v-if="docRelations.length">` and before `<LawInfoPanel>`. It already does — verify visually.

### Task 20b — /law/:id breadcrumb not showing

File: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
The breadcrumb div (line 5-24) exists but is hidden behind the navbar. The `.lawx` wrapper needs `padding-top: 96px` like we did for `.psr`.

Search for `.lawx {` in the `<style>` section. If no padding-top, add:
```css
.lawx {
  padding-top: 96px;
}
```
If `.lawx` style block doesn't exist, add it.

### Task 21 — เอกสารเก่ามี TOC สารบัญ

File: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
Line ~79: `<template v-if="!usesOriginalPdfLayout">` hides TOC for old/PDF docs. But old docs should still show sidebar info panel. The TOC is section-based so it correctly hides for PDF-only docs (no sections to navigate).

This is CORRECT behavior — old docs embed PDF, no section-based TOC possible. The sidebar info panel (aside) already shows for old docs. No change needed.

### Task 22 — "เอกสารที่อ้างถึง" → ใช้ชื่อกฎหมายเป็น tag

File: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue`
In the document relations section (line ~207-234), the `lawx-parentcard` shows `กฎหมายแม่ / เกี่ยวข้องทั้งฉบับ`. Each relation row shows `rel.target_title`. This already shows the law name.

For the admin law list page (`AdminLawListPage.vue` line 115-118), the "กฎหมายที่อ้างถึง" chip shows count. This is correct.

No change needed — already uses target_title.

## Verify
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: exit 0

## Commit
```bash
git add -A && git commit -m "fix: keywords in database card, download filename, admin status chip, law view breadcrumb and header"
```
