# Law View: Section-Relation Card Restyle — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the per-section "related laws" block on the public law view (`/law/:id`, `LawDocumentView.vue`) to match the screenshot — an always-visible bordered card grouped by relation type (e.g. a red "กฎหมายที่ถูกยกเลิก" group with boxed rows) — using the existing real relation data.

**Architecture:** The relation feature already exists and is fully wired to real data: `sectionRelations(id)` (via `relationsForSection`) renders section-scoped relations with their type, and `LawInfoPanel` shows document-scoped relations + repealed laws. This plan only changes the section block's presentation from a collapsible toggle + plain `<ul>` to an always-open card that groups relations by type with a Thai group header and boxed rows. No data, store, or backend changes; document-level panel unchanged.

**Tech Stack:** Vue 3 + Vuetify 3 + TypeScript.

**Verification:** frontend has no JS test runner — verify with `npm run typecheck`, `npm run build`, and manual check.

---

### Task 1: Group section relations by type

**Files:**
- Modify: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue` (`<script setup>`)

- [ ] **Step 1: Add group labels + a grouped accessor**

In `<script setup>`, add after the `sectionRelations` function (line 244):

```ts
// Thai group headers per relation type, matching the public law-view design.
const RELATION_GROUP_LABELS: Record<RelationType, string> = {
  repeals: 'กฎหมายที่ถูกยกเลิก',
  amends: 'กฎหมายที่แก้ไขเพิ่มเติม',
  supersedes: 'กฎหมายที่ถูกแทนที่',
  issued_under: 'ออกตามอำนาจของ',
  related: 'กฎหมายที่เกี่ยวข้อง',
};

// Display order: cancellations first (most consequential), then the rest.
const RELATION_GROUP_ORDER: RelationType[] = ['repeals', 'supersedes', 'amends', 'issued_under', 'related'];

function groupedSectionRelations(sectionId: string): Array<{ type: RelationType; label: string; items: LawRelation[] }> {
  const rels = sectionRelations(sectionId);
  return RELATION_GROUP_ORDER
    .map((type) => ({
      type,
      label: RELATION_GROUP_LABELS[type],
      items: rels.filter((rel) => rel.type === type),
    }))
    .filter((group) => group.items.length > 0);
}
```

- [ ] **Step 2: Remove the now-unused toggle state**

Delete the `expanded` ref and `toggleExpand` function (lines 240 and 246-251) — the card is always visible now, so the per-section expand toggle is no longer used.

- [ ] **Step 3: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS (after Task 2 removes the template references to `expanded`/`toggleExpand`). If run before Task 2, it flags unused — proceed to Task 2 then re-run.

---

### Task 2: Replace the toggle block with the grouped card

**Files:**
- Modify: `apps/app-laravel/resources/js/components/law/LawDocumentView.vue` (template + `<style scoped>`)

- [ ] **Step 1: Replace the `.lawx-rel` block**

Replace the current relation block (lines 131-152, the `<div v-if="sectionRelations(section.id).length" class="lawx-rel">` … `</div>`) with:

```vue
          <div v-if="sectionRelations(section.id).length" class="lawx-relcard">
            <div class="lawx-relcard__head">
              <span class="mdi mdi-scale-balance" />
              กฎหมายที่เกี่ยวข้อง
            </div>
            <div
              v-for="group in groupedSectionRelations(section.id)"
              :key="group.type"
              class="lawx-relgroup"
            >
              <div class="lawx-relgroup__label" :class="`is-${group.type}`">{{ group.label }}</div>
              <a
                v-for="rel in group.items"
                :key="rel.id"
                class="lawx-relrow"
                :class="`is-${group.type}`"
                :href="safeUrl(rel.url) ?? undefined"
                :target="safeUrl(rel.url) ? '_blank' : undefined"
                rel="noopener"
              >
                <span class="mdi lawx-relrow__icon" :class="RELATION_TYPE_ICONS[rel.type] ?? 'mdi-link-variant'" />
                <span class="lawx-relrow__title">{{ rel.target_title }}</span>
                <span v-if="rel.target_section" class="lawx-relrow__sec">{{ rel.target_section }}</span>
                <span v-if="rel.note" class="lawx-relrow__note">— {{ rel.note }}</span>
              </a>
            </div>
          </div>
```

- [ ] **Step 2: Replace the `.lawx-rel*` styles with the card styles**

In `<style scoped>`, replace the `.lawx-rel` rule block (lines 510-519) with:

```css
.lawx-relcard {
  margin-top: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  background: #f8fafc;
  padding: 14px 16px;
}
.lawx-relcard__head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  font-weight: 700;
  color: #1d4ed8;
  margin-bottom: 10px;
}
.lawx-relgroup { margin-top: 8px; }
.lawx-relgroup__label {
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 6px;
  color: #64748b;
}
.lawx-relgroup__label.is-repeals { color: #dc2626; }
.lawx-relgroup__label.is-supersedes { color: #ea580c; }
.lawx-relgroup__label.is-amends { color: #0d9488; }
.lawx-relgroup__label.is-issued_under { color: #7c3aed; }

.lawx-relrow {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
  margin-bottom: 6px;
  font-size: 13px;
  color: #334155;
  text-decoration: none;
}
.lawx-relrow:hover { border-color: #cbd5e1; background: #fcfcfd; }
.lawx-relrow__icon { flex-shrink: 0; }
.lawx-relrow.is-repeals .lawx-relrow__icon { color: #dc2626; }
.lawx-relrow.is-supersedes .lawx-relrow__icon { color: #ea580c; }
.lawx-relrow.is-amends .lawx-relrow__icon { color: #0d9488; }
.lawx-relrow.is-issued_under .lawx-relrow__icon { color: #7c3aed; }
.lawx-relrow.is-related .lawx-relrow__icon { color: #2563eb; }
.lawx-relrow__title { font-weight: 500; }
.lawx-relrow__sec { color: #64748b; font-size: 12px; }
.lawx-relrow__note { color: #94a3b8; font-size: 12px; }
```

- [ ] **Step 3: Remove the now-unused `relationListClass`**

`relationListClass` (lines 263-270) is no longer referenced (its per-`<li>` classes are replaced by the `is-${group.type}` classes). Delete it. `relationTypeLabel` may also become unused in this component — if `npm run typecheck` flags it as an unused import, remove `relationTypeLabel` from the `../../types/lawRelation` import (keep `RELATION_TYPE_ICONS`).

- [ ] **Step 4: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS (no unused `expanded`/`toggleExpand`/`relationListClass`/`relationTypeLabel`).

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/components/law/LawDocumentView.vue
git commit -m "feat(law-view): grouped always-visible section relation card"
```

---

### Task 3: Verify

**Files:** none (verification only)

- [ ] **Step 1: Manual check**

Open `/law/:id` for a document that has section-scoped relations (add some on `/relations` if none exist). Confirm:
- The section (e.g. มาตรา 3) shows an always-visible bordered "กฎหมายที่เกี่ยวข้อง" card (no toggle button needed).
- Relations are grouped under a colored type header (e.g. red "กฎหมายที่ถูกยกเลิก") with boxed rows, each showing the target title (+ section/note); rows with a valid URL open in a new tab.
- Sections with no relations show no card.
- The right "ข้อมูลกฎหมาย" panel still shows document-level relations + repealed laws (unchanged).

- [ ] **Step 2: Commit (only if manual check required tweaks)**

```bash
git add -A && git commit -m "fix(law-view): relation card polish after manual check"
```

---

## Self-Review

- **Spec coverage:** relations displayed per section with type + grouped card matching the screenshot (Tasks 1-2); document-level relations already shown via `LawInfoPanel` (unchanged); real data via existing `relationsForSection`. ✓
- **Feature already existed:** this is a restyle, not new functionality — no data/store/backend change. ✓
- **Placeholders:** none — concrete template/CSS/script edits with exact line references. ✓
- **Type consistency:** `groupedSectionRelations` returns `{ type: RelationType; label; items: LawRelation[] }[]`, consumed in the template; `RELATION_TYPE_ICONS` retained, `relationListClass`/`expanded`/`toggleExpand`/`relationTypeLabel` removed together with their template references. ✓
