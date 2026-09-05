# Plan: admin/laws + admin/upload — นับกฎหมายภายนอกให้ถูก และชื่อยาวตัด ... พร้อม tooltip

## Goal
แก้หน้า `admin/laws` ให้ stat card "กฎหมายภายนอก" นับจาก `law_meta.source === 'external'`/เอกสารเก่าภายนอก ไม่ใช่นับจาก `law_type` และทำให้ชื่อกฎหมายในตาราง `admin/laws` + `admin/upload` ยาวเกิน 1 บรรทัดตัด `...` พร้อม tooltip.

## Current context / assumptions
- Project root: `D:/workspace/outside/docling-thai-poc`
- Laravel/Vue app: `apps/app-laravel`
- `admin/laws` file: `apps/app-laravel/resources/js/pages/admin/AdminLawListPage.vue`
- `admin/upload` table file: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`
- Report API: `apps/app-laravel/app/Http/Controllers/Api/ReportController.php`
- Current report documents do not expose `source`, so the frontend cannot count external-law documents correctly.
- Current `AdminLawListPage.vue` counts stat card `กฎหมายภายนอก` from `by_type` where `law_type === 'กฎหมายภายนอก'`, but real external docs may have law_type like `พระราชบัญญัติ`, `ระเบียบ`, etc. and `source === 'external'`.
- User requirement: no dash `—` in admin/upload tag columns; keep meaningful chips.

## Architecture / proposed approach
Expose `source` from the report summary API, type it on the frontend, then count the "กฎหมายภายนอก" card from document source instead of law type. Add a reusable Vuetify tooltip wrapping a single-line truncated title in both admin tables, with scoped CSS using `white-space: nowrap; overflow: hidden; text-overflow: ellipsis`.

## Step-by-step tasks

### Task 1 — Add `source` to report API documents
File: `apps/app-laravel/app/Http/Controllers/Api/ReportController.php`

In `documents()` add a `source` field next to `published_date`:

```php
            'published_date' => trim((string) ($r['published_date'] ?? '')),
            'source' => trim((string) ($r['source'] ?? '')),
```

Expected result: `/api/reports/summary` document rows include `source: "external"` for external/old docs and `source: "internal"` or empty for internal docs.

Verification command:

```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel && npm run typecheck
```

Expected output includes no TypeScript errors after Task 2.

### Task 2 — Type `source` in `ReportDocument`
File: `apps/app-laravel/resources/js/types/document.ts`

Add optional `source` field to `ReportDocument`:

```ts
  source?: DocumentSource;
```

Place it near `published_date?: string;`.

### Task 3 — Count external laws from `source`, not `law_type`
File: `apps/app-laravel/resources/js/pages/admin/AdminLawListPage.vue`

Update the `statCards` computed block so `กฎหมายภายนอก` uses `summary.value.documents.filter((d) => d.source === 'external')`.

Replace the count/recent logic inside `FEATURED_TYPES.map` with this exact shape:

```ts
    const docsForType = typeName === 'กฎหมายภายนอก'
      ? summary.value.documents.filter((d) => d.source === 'external')
      : summary.value.documents.filter((d) => matchesType(d.type));
    const count = docsForType.length;
    const recent = docsForType.filter(
      (d) => d.date && new Date(d.date).getTime() > thirtyDaysAgo,
    ).length;
```

Do not change `FEATURED_TYPES`.

### Task 4 — Truncate law title in `admin/laws` table with tooltip
File: `apps/app-laravel/resources/js/pages/admin/AdminLawListPage.vue`

Replace current title span:

```vue
<span class="text-body-2 font-weight-bold">{{ law.title }}</span>
```

with:

```vue
<v-tooltip :text="law.title" location="top">
  <template #activator="{ props: tooltipProps }">
    <span v-bind="tooltipProps" class="adm-law-title text-body-2 font-weight-bold">{{ law.title }}</span>
  </template>
</v-tooltip>
```

Add scoped CSS at bottom:

```css
.adm-law-title {
  display: inline-block;
  max-width: min(520px, 42vw);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  vertical-align: bottom;
}
```

### Task 5 — Truncate upload table title with tooltip
File: `apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue`

Replace current title template:

```vue
<template #item.title="{ item }">
  <span class="text-body-2 font-weight-medium">{{ item.title }}</span>
</template>
```

with:

```vue
<template #item.title="{ item }">
  <v-tooltip :text="item.title" location="top">
    <template #activator="{ props: tooltipProps }">
      <span v-bind="tooltipProps" class="pipeline-title text-body-2 font-weight-medium">{{ item.title }}</span>
    </template>
  </v-tooltip>
</template>
```

Add scoped CSS near existing table CSS:

```css
.pipeline-title {
  display: inline-block;
  max-width: min(560px, 44vw);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  vertical-align: bottom;
}
```

### Task 6 — Verify and commit
Run:

```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel && npm run typecheck
```

Expected output: command exits `0`.

Then run a quick grep to confirm source is exposed and tooltip CSS exists:

```bash
cd D:/workspace/outside/docling-thai-poc && grep -R "'source' =>" -n apps/app-laravel/app/Http/Controllers/Api/ReportController.php && grep -R "adm-law-title\|pipeline-title" -n apps/app-laravel/resources/js/pages/admin/AdminLawListPage.vue apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue
```

Expected output: lines for ReportController source and both CSS classes.

Commit:

```bash
git add apps/app-laravel/app/Http/Controllers/Api/ReportController.php apps/app-laravel/resources/js/types/document.ts apps/app-laravel/resources/js/pages/admin/AdminLawListPage.vue apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue .hermes/plans/2026-09-05_162000-admin-laws-external-count-title-tooltip.md && git commit -m "fix(admin): count external laws and truncate table titles"
```

## Tests / validation
- TDD here is UI/data plumbing; no existing component test harness is visible. Validate by typecheck and manual browser check.
- Manual check:
  1. Open `http://localhost:8500/admin/laws`
  2. Verify card `กฎหมายภายนอก` count equals rows/documents where source is external, even if law type is `พระราชบัญญัติ`, `ระเบียบ`, etc.
  3. Verify long title in `admin/laws` is 1 line with `...` and hover tooltip shows full title.
  4. Open `http://localhost:8500/admin/upload`
  5. Verify long document title is 1 line with `...` and hover tooltip shows full title.

## Risks, tradeoffs, and open questions
- If backend `ReviewStore::listLawMeta()` does not include `source`, Codex must inspect `apps/app-laravel/app/Services/ReviewStore.php` and add `source` from `law_meta.source` to each row there instead of only changing `ReportController.php`.
- If some historical documents use `document_type === 'old'` but missing `source`, decide whether to treat old docs as external. Default in this plan: only count `source === 'external'` to avoid misclassifying internal historical imports.
- Do not rename law types; `กฎหมายภายนอก` card is a source bucket, not a law type chip.
