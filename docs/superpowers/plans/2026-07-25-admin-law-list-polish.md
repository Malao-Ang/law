# Admin Law List (`/admin/laws`) — Screenshot Polish Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the already-working `AdminLawListPage.vue` visually in line with the LAWSPACE law-management screenshot — add the "เพิ่มกฎหมายใหม่" header button and apply cosmetic styling — without changing any of its real-data logic.

**Architecture:** The page already renders 4 type stat cards, filters, a table, and pagination from real `/api/reports/summary` data. This plan only: (1) adds an optional `#title-actions` slot to `AppShell` so a page can place a button on the title row, (2) restyles the law page (filled type badges, left-accent cards, range-style pagination text) and uses the new slot for the add button. No backend, no new data, nothing mocked.

**Tech Stack:** Vue 3 + Vuetify 3 + TypeScript, Vite.

**Deliberately omitted (no real data — would be mocking):** "แก้ไขแล้ว N ครั้ง" (revision count), "โดย Admin User" (editor identity), "มีผลบังคับใช้" (legal in-force status). The สถานะ column keeps the real workflow stage.

**Verification:** frontend has no JS test runner — verify with `npm run typecheck`, `npm run build`, and a manual screenshot check.

---

### Task 1: Add a `#title-actions` slot to `AppShell`

**Files:**
- Modify: `apps/app-laravel/resources/js/components/shared/AppShell.vue:94-97`

- [ ] **Step 1: Wrap the page-title block to host a right-aligned actions slot**

Replace lines 94-97:

```vue
    <div v-if="title" class="app-shell__page-title">
      <h1 class="text-h5 font-weight-black mb-0">{{ title }}</h1>
      <p v-if="subtitle" class="text-body-2 text-medium-emphasis mb-0">{{ subtitle }}</p>
    </div>
```

with:

```vue
    <div v-if="title" class="app-shell__page-title">
      <div class="min-width-0">
        <h1 class="text-h5 font-weight-black mb-0">{{ title }}</h1>
        <p v-if="subtitle" class="text-body-2 text-medium-emphasis mb-0">{{ subtitle }}</p>
      </div>
      <div v-if="$slots['title-actions']" class="flex-shrink-0">
        <slot name="title-actions" />
      </div>
    </div>
```

- [ ] **Step 2: Make the title block a space-between flex row**

In the `<style scoped>` block, replace the `.app-shell__page-title` rule (currently lines 245-249):

```css
.app-shell__page-title {
  background: #fff;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  padding: 14px 24px 16px;
}
```

with:

```css
.app-shell__page-title {
  align-items: center;
  background: #fff;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  display: flex;
  gap: 16px;
  justify-content: space-between;
  padding: 14px 24px 16px;
}
```

- [ ] **Step 3: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS. (Additive slot — no other page passes `#title-actions`, so nothing else changes.)

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/components/shared/AppShell.vue
git commit -m "feat(shell): add optional title-actions slot to AppShell"
```

---

### Task 2: Polish `AdminLawListPage.vue` to match the screenshot

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/admin/AdminLawListPage.vue`

- [ ] **Step 1: Add the header button via the new slot**

Immediately after the opening `<AppShell ...>` tag (after line 6, before the `<!-- Type stat tabs -->` row), insert:

```vue
    <template #title-actions>
      <v-btn color="admin-primary" prepend-icon="mdi-plus" class="text-none" rounded="lg" to="/admin/upload">
        เพิ่มกฎหมายใหม่
      </v-btn>
    </template>
```

- [ ] **Step 2: Make type badges solid-filled**

Replace the ประเภท chip (lines 124-126):

```vue
            <td>
              <v-chip v-if="law.lawType" size="x-small" :color="typeColor(law.lawType)" rounded="pill">{{ law.lawType }}</v-chip>
            </td>
```

with (add `variant="flat"` for a solid fill + white label):

```vue
            <td>
              <v-chip v-if="law.lawType" size="small" variant="flat" :color="typeColor(law.lawType)" rounded="pill" class="font-weight-bold text-white">{{ law.lawType }}</v-chip>
            </td>
```

- [ ] **Step 3: Switch stat cards to a left accent**

In the `<style scoped>` block, in the `.type-tab` rule replace `border-top: 3px solid rgb(var(--accent));` with `border-left: 4px solid rgb(var(--accent));`, and in `.type-tab--active` replace `border-top: 3px solid rgb(var(--accent));` with `border-left: 4px solid rgb(var(--accent));`.

Result:

```css
.type-tab {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-left: 4px solid rgb(var(--accent));
  cursor: pointer;
  transition: box-shadow 0.15s ease, background 0.15s ease;
}
```

```css
.type-tab--active {
  background: rgba(var(--accent), 0.06);
  border: 1px solid rgb(var(--accent));
  border-left: 4px solid rgb(var(--accent));
}
```

- [ ] **Step 4: Use range-style pagination text**

Replace the footer count span (lines 146-148):

```vue
        <span class="text-caption text-medium-emphasis">
          กำลังแสดงผล {{ filteredLaws.length.toLocaleString('th-TH') }} จากทั้งหมด {{ laws.length.toLocaleString('th-TH') }} รายการ
        </span>
```

with:

```vue
        <span class="text-caption text-medium-emphasis">
          กำลังแสดงผล {{ rangeStart.toLocaleString('th-TH') }} - {{ rangeEnd.toLocaleString('th-TH') }} จากทั้งหมด {{ filteredLaws.length.toLocaleString('th-TH') }} รายการ
        </span>
```

- [ ] **Step 5: Add the `rangeStart` / `rangeEnd` computeds**

In `<script setup>`, immediately after the `pagedLaws` computed (line 338), add:

```ts
const rangeStart = computed(() => (filteredLaws.value.length === 0 ? 0 : (page.value - 1) * PAGE_SIZE + 1));
const rangeEnd = computed(() => Math.min(page.value * PAGE_SIZE, filteredLaws.value.length));
```

- [ ] **Step 6: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 7: Manual check**

Open `http://localhost:8000/admin/laws`. Confirm: header shows the blue "เพิ่มกฎหมายใหม่" button (routes to upload), type badges are solid-filled, stat cards have a left colour accent, and the footer reads "กำลังแสดงผล 1 - N จากทั้งหมด M รายการ".

- [ ] **Step 8: Commit**

```bash
git add apps/app-laravel/resources/js/pages/admin/AdminLawListPage.vue
git commit -m "feat(admin): polish law list to match LAWSPACE screenshot"
```

---

## Self-Review

- **Spec coverage:** add button (Task 2 Step 1), filled badges (Step 2), left-accent cards (Step 3), range pagination (Steps 4-5). Unbacked fields intentionally omitted per scope. ✓
- **Placeholders:** none — all steps have concrete code. ✓
- **Type consistency:** `rangeStart`/`rangeEnd` defined in Task 2 Step 5, used in Step 4. `#title-actions` slot defined in Task 1, consumed in Task 2 Step 1. ✓
- **No-mock rule:** no new data introduced; all displayed values remain real. ✓
