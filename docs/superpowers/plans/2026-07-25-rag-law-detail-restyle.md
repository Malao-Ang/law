# RAG Page → Law-Detail 3-Column Restyle — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle `RagManageWorkspace.vue` (the `/documents/:id/rag` page) into the law-detail 3-column layout from the screenshot — left TOC sidebar, center law-header card + มาตรา content cards, right law-info panel — using ONLY real data and the page's EXISTING functions. No new functions, no new action buttons.

**Architecture:** Pure presentation change. The existing block/section model, selection bar (merge/delete/split), split-line dialog, chunk-type menu, temp-history FAB/drawer, stepper, and footer bar are all preserved and reused as-is. New UI is assembled from existing building blocks: `useLawSections` (`buildSections`, `buildTocGroups`), `BlockFlow`, and the existing `LawInfoPanel.vue` (reused verbatim for the right column). One small new component renders the law-header card from `review.law_meta`.

**Tech Stack:** Vue 3 + Vuetify 3 + TypeScript.

**Real data used:** `review.law_meta` (law_type, status, title, promulgation_date, effective_date, gazette_reference, agencies, law_groups, section_count, royal_command, imported_by, keywords, repealed_laws), TOC from `buildTocGroups(buildSections(review))`, per-section badge + `BlockFlow` content, `review.relations`.

**Omitted (no real data — would be mocked):** law code (LAW-xxxx), per-มาตรา status badges (แก้ไข/ยกเลิก/แทนที่/รอมีผล), status-stat summary, version labels/count (v1.0, "3 ครั้ง"), Timeline tab, published_date/expiry_date, "edited by" user. The screenshot's ค้นหามาตรา TOC search is also omitted (it is a new control) — flag: say if you want it.

**Verification:** frontend has no JS test runner — verify with `npm run typecheck`, `npm run build`, and a manual check.

---

### Task 1: Law-header card component (`RagLawHeader.vue`)

**Files:**
- Create: `apps/app-laravel/resources/js/components/rag/RagLawHeader.vue`

Renders the center-top law header from `law_meta`. Real fields only; omits the (unbacked) law code.

- [ ] **Step 1: Create the component**

```vue
<template>
  <v-card flat border rounded="lg" class="rag-law-header pa-5">
    <div class="d-flex align-center flex-wrap ga-2 mb-2">
      <v-chip v-if="meta.law_type" size="small" color="admin-primary" variant="flat" class="font-weight-bold">
        {{ meta.law_type }}
      </v-chip>
      <v-chip v-if="meta.status" size="small" color="success" variant="tonal" prepend-icon="mdi-circle-medium">
        {{ meta.status }}
      </v-chip>
    </div>
    <h2 class="text-h6 font-weight-bold mb-4">{{ meta.title || 'ไม่มีชื่อกฎหมาย' }}</h2>
    <div class="rag-law-header__grid">
      <div v-for="item in fields" :key="item.label" class="rag-law-header__cell">
        <div class="text-caption text-medium-emphasis">{{ item.label }}</div>
        <div class="text-body-2 font-weight-semibold">{{ item.value }}</div>
      </div>
    </div>
  </v-card>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { LawMeta } from '../../types/document';

const props = defineProps<{ meta: LawMeta }>();

const fields = computed(() =>
  [
    { label: 'วันที่ประกาศ', value: props.meta.promulgation_date },
    { label: 'วันที่มีผลบังคับ', value: props.meta.effective_date },
    { label: 'ราชกิจจานุเบกษา', value: props.meta.gazette_reference },
    { label: 'หน่วยงานเจ้าของ', value: props.meta.agencies?.[0] ?? props.meta.agency },
    { label: 'กลุ่มกฎหมาย', value: props.meta.law_groups?.[0] ?? props.meta.law_group },
    { label: 'จำนวนมาตรา', value: props.meta.section_count != null ? `${props.meta.section_count} มาตรา` : '' },
  ].filter((f) => (f.value ?? '') !== ''),
);
</script>

<style scoped>
.rag-law-header__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px 20px;
}
.rag-law-header__cell {
  min-width: 0;
}
@media (max-width: 900px) {
  .rag-law-header__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
```

- [ ] **Step 2: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/components/rag/RagLawHeader.vue
git commit -m "feat(rag): add law-header card from real law_meta"
```

---

### Task 2: Restructure `RagManageWorkspace` into a 3-column grid

**Files:**
- Modify: `apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue`

Keep every existing handler and piece. Only the `<template>` layout inside `<template v-else>` and CSS change; the section-list markup (`.rag-block-list` and its `v-for`) is preserved verbatim, just moved into the center column.

- [ ] **Step 1: Wrap the content in a 3-column grid**

Replace the `<div class="rag-content-area"> ... </div>` block (lines 26-133) so the selection bar + save alert stay on top, then a 3-column grid holds TOC / (header + block list) / info panel. Keep the existing `.rag-block-list` markup exactly — only move it inside `.rag-detail__center`:

```vue
      <div class="rag-content-area">
        <!-- Selection action bar (UNCHANGED) -->
        <div
          class="rag-selection-bar d-flex align-center ga-2 px-3 py-2 rounded-lg"
          :class="{ 'is-visible': selectedBlockIds.size > 0 }"
        >
          <!-- ...unchanged selection-bar buttons... -->
        </div>

        <!-- Save error (UNCHANGED) -->
        <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable
          @click:close="documentStore.setSaveError()">
          {{ documentStore.saveError }}
        </v-alert>

        <div class="rag-detail">
          <!-- LEFT: table of contents -->
          <aside class="rag-detail__toc">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-body-2 font-weight-bold">สารบัญ</span>
              <v-chip size="x-small" variant="tonal">{{ sections.length }} รายการ</v-chip>
            </div>
            <div class="rag-toc__scroll">
              <template v-for="group in tocGroups" :key="group.label">
                <div class="text-caption font-weight-bold text-medium-emphasis mt-3 mb-1">{{ group.label }}</div>
                <button
                  v-for="sid in group.sectionIds"
                  :key="sid"
                  class="rag-toc__item"
                  @click="scrollToSection(sid)"
                >
                  {{ tocBadge(sid) }}
                </button>
              </template>
            </div>
          </aside>

          <!-- CENTER: header card + existing block list -->
          <div class="rag-detail__center">
            <RagLawHeader v-if="lawMeta" :meta="lawMeta" />
            <!-- KEEP the existing .rag-block-list block here VERBATIM -->
            <div ref="blockListEl" class="rag-block-list">
              <!-- ...unchanged section/blockrow v-for markup... -->
            </div>
          </div>

          <!-- RIGHT: reused law info panel -->
          <aside v-if="lawMeta" class="rag-detail__info">
            <LawInfoPanel
              :meta="lawMeta"
              :article-count="lawMeta.section_count ?? sections.length"
              article-unit-label="มาตรา"
              :relations="composeStore.review?.relations"
            />
          </aside>
        </div>
      </div>
```

- [ ] **Step 2: Add imports + computeds + `scrollToSection`**

In `<script setup>`, add imports:

```ts
import RagLawHeader from './RagLawHeader.vue';
import LawInfoPanel from '../law/LawInfoPanel.vue';
import { buildSections, buildTocGroups, suggestChunkType, type LawSection } from '../../composables/useLawSections';
```

(Note: `buildSections` and `suggestChunkType` are already imported — merge, don't duplicate; just add `buildTocGroups`.)

Add computeds/helpers near `const sections = ...`:

```ts
const tocGroups = computed(() => buildTocGroups(sections.value));
const lawMeta = computed(() => composeStore.review?.law_meta ?? null);

function tocBadge(sectionId: string): string {
  return sections.value.find((s) => s.id === sectionId)?.badge ?? '';
}

function scrollToSection(sectionId: string): void {
  blockListEl.value
    ?.querySelector<HTMLElement>(`[data-section-id="${sectionId}"]`)
    ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
```

- [ ] **Step 3: Add the grid + TOC styles**

In `<style scoped>` add:

```css
.rag-detail {
  display: grid;
  grid-template-columns: 240px minmax(0, 1fr) 300px;
  gap: 16px;
  flex: 1 1 0;
  min-height: 0;
  overflow: hidden;
}
.rag-detail__toc,
.rag-detail__info {
  overflow-y: auto;
  min-height: 0;
}
.rag-detail__toc {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px 12px;
}
.rag-detail__center {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 0;
  overflow: hidden;
}
.rag-toc__scroll { display: flex; flex-direction: column; }
.rag-toc__item {
  text-align: left;
  padding: 6px 8px;
  border-radius: 8px;
  font-size: 0.85rem;
  color: #334155;
  background: none;
  border: none;
  cursor: pointer;
}
.rag-toc__item:hover { background: #f1f5f9; color: #1a3673; }
@media (max-width: 1100px) {
  .rag-detail { grid-template-columns: 200px minmax(0, 1fr); }
  .rag-detail__info { display: none; }
}
```

- [ ] **Step 4: Style the section cards to match**

The existing `.rag-sec` already renders as a bordered card. To match the screenshot's มาตรา badge, add a badge chip to each `.rag-sec__head` using the section's `badge` (this reuses existing data, adds no function). In the section `v-for`, add before the type chip menu:

```vue
            <div class="rag-sec__head">
              <v-chip size="small" color="admin-primary" variant="tonal" class="font-weight-bold">
                {{ section.badge }}
              </v-chip>
              <!-- ...existing chunk-type v-menu chip unchanged... -->
            </div>
```

- [ ] **Step 5: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue
git commit -m "feat(rag): 3-column law-detail restyle (TOC + header + info panel)"
```

---

### Task 3: Verify

**Files:** none (verification only)

- [ ] **Step 1: Manual check**

Open `http://localhost:8000/documents/{id}/rag` for a reviewed document. Confirm:
- Left TOC lists chapters + section badges; clicking scrolls the center list to that section.
- Center shows the law-header card (real meta, no law code) above the existing section/มาตรา cards; each card has its มาตรา badge + type chip.
- Right panel shows real law info (status, dates, type, group, agency, จำนวนมาตรา, keywords, related laws).
- **All existing functions still work:** select → merge/delete/split bar, per-row split-line, chunk-type menu, history FAB/drawer, stepper next → law-info. No new buttons added.
- No mocked values anywhere (no v1.0, no status badges, no timeline).

- [ ] **Step 2: Commit (only if manual check required tweaks)**

```bash
git add -A && git commit -m "fix(rag): law-detail restyle polish after manual check"
```

---

## Self-Review

- **Scope coverage:** TOC (Task 2), header card (Task 1), center cards + existing actions preserved (Task 2 Steps 1,4), right info panel reused (Task 2 Step 1). ✓
- **No new functions/buttons:** only presentation + a read-only TOC nav (approved in scope). All handlers reused unchanged. ✓
- **No mock:** every value from `review.law_meta` / sections / relations; unbacked fields omitted, listed explicitly. ✓
- **Reuse:** `LawInfoPanel`, `useLawSections`, `BlockFlow` reused rather than reinvented. ✓
- **Placeholders:** the plan marks two spots as "keep existing markup verbatim" (selection bar, block-list `v-for`) — these are existing code to preserve, not missing code. ✓
