# Main Nav: Fix Active State + Symmetric Vuetify Styling — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the public header nav (`MainNav.vue`) so exactly one item is active at a time, and restyle it to match the landing screenshot with symmetric spacing built on a Vuetify component (`v-btn`).

**Architecture:** `MainNav` renders four items (หน้าแรก, เกี่ยวกับระบบ → `/#about`, ฐานข้อมูลกฎหมาย → `/database`, ความรู้ → `/#knowledge`). Today each item is a raw `<RouterLink>`, and `หน้าแรก` is marked active whenever `route.path === '/'` **without checking the hash** — so on `/#about` or `/#knowledge` both `หน้าแรก` and the anchor item highlight. This plan corrects the active predicate (home is active only with an empty hash) and rebuilds each item as a `v-btn variant="text"` for symmetric padding/height, driving the gold underline purely through a computed active class.

**Tech Stack:** Vue 3 + Vuetify 3 + TypeScript, vue-router.

**Verification:** frontend has no JS test runner — verify with `npm run typecheck`, `npm run build`, and manual route checks.

**Flagged alternative:** keeping the raw `<RouterLink>` and only fixing the active logic + spacing is viable; this plan uses `v-btn` because the user asked to "follow vuetify component". `v-btn`'s own router-active can't distinguish hash routes (all `/`-based items would auto-activate on `/`), so we set `:active="false"` and control the visual active state ourselves.

---

### Task 1: Correct the active predicate

**Files:**
- Modify: `apps/app-laravel/resources/js/components/shared/MainNav.vue:24-34`

- [ ] **Step 1: Make home active only when no anchor hash is present**

Replace `isActive` (lines 24-34):

```ts
function isActive(item: NavItem): boolean {
  const path = activeRoutePath.value;

  // Database tab also covers individual law pages.
  if (item.activePath === '/database') {
    return path === '/database' || path.startsWith('/law/');
  }

  // Anchor items (เกี่ยวกับระบบ / ความรู้) require both the path and their hash.
  if (item.hash) {
    return path === item.activePath && route.hash === item.hash;
  }

  // Home (no hash): active only on '/' with no anchor hash, so it does not
  // stay lit alongside #about / #knowledge.
  return path === item.activePath && route.hash === '';
}
```

- [ ] **Step 2: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/components/shared/MainNav.vue
git commit -m "fix(nav): home no longer active alongside anchor items"
```

---

### Task 2: Rebuild items as v-btn with symmetric spacing + screenshot underline

**Files:**
- Modify: `apps/app-laravel/resources/js/components/shared/MainNav.vue` (template + `<style scoped>`)

- [ ] **Step 1: Replace the RouterLink loop with v-btn**

Replace the `<template>` block (lines 37-49):

```vue
<template>
  <nav class="main-nav">
    <v-btn
      v-for="item in items"
      :key="item.label"
      :to="item.to"
      :active="false"
      variant="text"
      rounded="0"
      height="48"
      :ripple="false"
      class="main-nav__item text-none"
      :class="{ 'main-nav__item--active': isActive(item) }"
    >
      {{ item.label }}
    </v-btn>
  </nav>
</template>
```

(`RouterLink` is no longer used in the template; leave the `import` of `useRoute`/`RouteLocationRaw` intact and drop `RouterLink` from the import in Step 3 to avoid an unused-symbol warning.)

- [ ] **Step 2: Replace the styles with symmetric, Vuetify-aligned spacing**

Replace the `<style scoped>` block (lines 51-80):

```vue
<style scoped>
.main-nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  flex-wrap: wrap;
}

/* v-btn supplies symmetric padding/height; we only set typography + color. */
.main-nav__item {
  padding-inline: 16px;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-weight: 700;
  font-size: 22px;
  letter-spacing: 0;
  color: #4e4538;
  position: relative;
}

.main-nav__item--active {
  color: #7b580d;
}

/* Short centered underline matching the screenshot's active indicator. */
.main-nav__item--active::after {
  content: '';
  position: absolute;
  left: 16px;
  right: 16px;
  bottom: 8px;
  height: 2px;
  border-radius: 2px;
  background: #7b580d;
}
</style>
```

- [ ] **Step 3: Drop the now-unused RouterLink import**

In `<script setup>`, change the vue-router import (line 4) from:

```ts
import { RouterLink, useRoute, type RouteLocationRaw } from 'vue-router';
```

to:

```ts
import { useRoute, type RouteLocationRaw } from 'vue-router';
```

- [ ] **Step 4: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS (no unused `RouterLink`).

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/components/shared/MainNav.vue
git commit -m "feat(nav): symmetric v-btn nav with screenshot active underline"
```

---

### Task 3: Verify active behavior across routes

**Files:** none (verification only)

- [ ] **Step 1: Manual route checks**

Open the public site and confirm exactly one active item per location:
- `/` → only **หน้าแรก** underlined.
- `/#about` → only **เกี่ยวกับระบบ** (หน้าแรก NOT lit).
- `/#knowledge` → only **ความรู้**.
- `/database` and any `/law/:id` → only **ฐานข้อมูลกฎหมาย**.
- Spacing between items is even; the active underline sits centered under the label (not full-width); items share one height with no vertical jitter when switching active.

- [ ] **Step 2: Commit (only if manual check required tweaks)**

```bash
git add -A && git commit -m "fix(nav): active/style polish after manual check"
```

---

## Self-Review

- **Spec coverage:** fix active function (Task 1 — home vs anchor items; Task 3 verifies one-active-at-a-time), symmetric padding/margin/spacing via a Vuetify component (Task 2 — `v-btn` + even `gap`/`padding-inline`), apply screenshot style (Task 2 — gold text + short centered underline). ✓
- **Placeholders:** none — complete template/CSS/script edits with exact commands. ✓
- **Type consistency:** `isActive(item: NavItem)` signature unchanged; `RouterLink` removed from both template and import together (Task 2 Steps 1 & 3) so no dangling reference. ✓
- **Vuetify caveat handled:** `:active="false"` disables `v-btn`'s route-based auto-active (which can't tell hash routes apart), so our computed `isActive` is the single source of truth. ✓
