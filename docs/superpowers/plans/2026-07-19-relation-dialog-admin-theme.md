# Relation Dialog — Admin Theme + Button Spacing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `AddRelationDialog` use the admin color scheme (`admin-primary`) and increase spacing between button option groups so the dialog matches the rest of the admin UI.

**Architecture:** Single-file change. `LawRelationsPage.vue` already uses `color="admin-primary"` throughout; the dialog uses `color="primary"` in five places and two button groups have 8px gaps (`ga-2`/`gap-2`) where 12px (`ga-3`) fits the admin layout better. No backend or store changes.

**Tech Stack:** Vue 3, Vuetify 3 (utility classes: `ga-*`, `pa-*`).

---

## File Map

| File | Change |
|---|---|
| `apps/app-laravel/resources/js/components/shared/AddRelationDialog.vue` | Replace 5× `color="primary"` with `color="admin-primary"`; increase button-group spacing; add padding to card actions |

No other files change. No new files.

---

## Task 1 — Admin theme + button spacing in `AddRelationDialog`

This is a pure template change — no logic, no tests. Verify visually.

**Files:**
- Modify: `apps/app-laravel/resources/js/components/shared/AddRelationDialog.vue`

- [ ] **Step 1: Replace `color="primary"` with `color="admin-primary"` in 5 places**

Open `apps/app-laravel/resources/js/components/shared/AddRelationDialog.vue`.

Make these replacements (all in the `<template>` block):

**Line ~19** — existing-relations section icon:
```html
<!-- before -->
<v-icon icon="mdi-link-variant" color="primary" size="18" />
<!-- after -->
<v-icon icon="mdi-link-variant" color="admin-primary" size="18" />
```

**Line ~21** — existing-relations count chip:
```html
<!-- before -->
<v-chip size="x-small" color="primary" variant="tonal">
<!-- after -->
<v-chip size="x-small" color="admin-primary" variant="tonal">
```

**Line ~80** — "เลือกจากคลังกฎหมาย" mode button:
```html
<!-- before -->
:color="mode === 'picker' ? 'primary' : ''"
<!-- after -->
:color="mode === 'picker' ? 'admin-primary' : ''"
```

**Line ~86** — "พิมพ์เอง" mode button:
```html
<!-- before -->
:color="mode === 'text' ? 'primary' : ''"
<!-- after -->
:color="mode === 'text' ? 'admin-primary' : ''"
```

**Line ~131** — save button in card actions:
```html
<!-- before -->
<v-btn color="primary" :disabled="!canSave" @click="save">เพิ่ม</v-btn>
<!-- after -->
<v-btn color="admin-primary" :disabled="!canSave" @click="save">เพิ่ม</v-btn>
```

- [ ] **Step 2: Increase type-selection button group spacing (`ga-2` → `ga-3`)**

Find the type-selection `div` around line 62 (it wraps the `v-for` of relation-type buttons):

```html
<!-- before -->
<div class="d-flex flex-wrap ga-2">
<!-- after -->
<div class="d-flex flex-wrap ga-3">
```

- [ ] **Step 3: Fix mode-selection button group (replace `gap-2` with `ga-3`)**

Find the mode-selection `div` around line 77 (it wraps "เลือกจากคลังกฎหมาย" and "พิมพ์เอง"):

```html
<!-- before -->
<div class="d-flex gap-2">
<!-- after -->
<div class="d-flex ga-3">
```

(`gap-2` is a raw CSS utility; `ga-3` is Vuetify's gap helper = 12px, consistent with the type-button row above.)

- [ ] **Step 4: Add padding and gap to `v-card-actions`**

Find `v-card-actions` near line 128:

```html
<!-- before -->
<v-card-actions>
  <v-spacer />
  <v-btn @click="$emit('close')">ยกเลิก</v-btn>
  <v-btn color="admin-primary" :disabled="!canSave" @click="save">เพิ่ม</v-btn>
</v-card-actions>
<!-- after -->
<v-card-actions class="pa-4 ga-2">
  <v-spacer />
  <v-btn @click="$emit('close')">ยกเลิก</v-btn>
  <v-btn color="admin-primary" :disabled="!canSave" @click="save">เพิ่ม</v-btn>
</v-card-actions>
```

(Note: Step 1 already changed `color="primary"` → `color="admin-primary"` on the save button; make sure that's in place before this step.)

- [ ] **Step 5: Run TypeScript type check**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors (template-only change, no type impact).

- [ ] **Step 6: Visual check in browser**

Open `http://localhost:8000` and navigate to any document's relations page (`/documents/{id}/relations`). Click "เพิ่ม" to open the dialog. Verify:

1. The link icon and count chip in the "existing relations" section are the admin blue/indigo color (matching the page).
2. The active mode button ("เลือกจากคลังกฎหมาย" or "พิมพ์เอง") turns admin blue when selected.
3. The "เพิ่ม" save button is admin blue (not default Material blue).
4. The type-selection buttons have a slightly larger gap between them.
5. The action area (bottom) has visible padding around ยกเลิก / เพิ่ม.

- [ ] **Step 7: Commit**

```bash
git add apps/app-laravel/resources/js/components/shared/AddRelationDialog.vue
git commit -m "style: use admin-primary theme and increase button spacing in AddRelationDialog"
```
