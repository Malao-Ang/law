# RAG Page Container Restyle (clean card) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the `/documents/:id/rag` page containers to match the law-info screenshot's clean card look — a white rounded card with a titled header row (icon + bold navy title), subtle border, and generous padding — wrapping the existing block list. All existing functions are preserved; no new functions or buttons.

**Architecture:** Presentation-only change to `RagManageWorkspace.vue`. The block list is wrapped in one titled "document card", and each section (`.rag-sec`) is restyled to the softer card aesthetic. The selection bar, split-line buttons, chunk-type menu, history FAB/drawer, workflow stepper, and footer bar are untouched. Supersedes the earlier 3-column law-detail plan (`2026-07-25-rag-law-detail-restyle.md`).

**Tech Stack:** Vue 3 + Vuetify 3 + TypeScript.

**Verification:** frontend has no JS test runner — verify with `npm run typecheck`, `npm run build`, and a manual check.

---

### Task 1: Wrap the block list in a titled document card

**Files:**
- Modify: `apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue`

- [ ] **Step 1: Wrap `.rag-block-list` in a card with a header**

In the template, the block list is currently:

```vue
        <!-- Section list (e-Law style) -->
        <div ref="blockListEl" class="rag-block-list">
```

Wrap it so the header sits above a scrolling list, both inside one card. Replace that opening `<div ref="blockListEl" class="rag-block-list">` line with:

```vue
        <!-- Document content card -->
        <section class="rag-doc-card">
          <header class="rag-doc-card__head">
            <span class="rag-doc-card__icon"><v-icon icon="mdi-file-document-edit-outline" size="18" /></span>
            <span class="rag-doc-card__title">เนื้อหาเอกสารกฎหมาย</span>
          </header>
          <div ref="blockListEl" class="rag-block-list">
```

Then add a matching closing `</section>` immediately AFTER the existing closing `</div>` of `.rag-block-list` (the one right before the `</div>` that closes `.rag-content-area`). The `.rag-block-list` `v-for` markup inside stays exactly as-is.

- [ ] **Step 2: Add the card styles + restyle `.rag-sec`**

In `<style scoped>`, add the document-card styles and soften the section cards. Insert after the `.rag-block-list` rule:

```css
.rag-doc-card {
  display: flex;
  flex-direction: column;
  flex: 1 1 0;
  min-height: 0;
  background: #fff;
  border: 1px solid #e6e8ef;
  border-radius: 16px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  overflow: hidden;
}
.rag-doc-card__head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 18px 24px 14px;
  border-bottom: 1px solid #eef1f6;
  flex: 0 0 auto;
}
.rag-doc-card__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  background: rgba(26, 54, 115, 0.08);
  color: #1a3673;
}
.rag-doc-card__title {
  font-size: 1.05rem;
  font-weight: 800;
  color: #1a3673;
}
```

Then update the existing `.rag-block-list` rule so it scrolls inside the card (change its padding to sit within the card body):

```css
.rag-block-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  overflow-y: auto;
  overflow-x: hidden;
  flex: 1;
  min-height: 0;
  padding: 20px 24px 60px;
  overscroll-behavior: contain;
}
```

And soften the section cards — replace the existing `.rag-sec` rule:

```css
.rag-sec {
  background: #fff;
  border: 1px solid #eef1f6;
  border-radius: 14px;
  padding: 18px 20px;
  min-width: 0;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
}
```

- [ ] **Step 3: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/resources/js/components/rag/RagManageWorkspace.vue
git commit -m "feat(rag): clean card container restyle to match law-info design"
```

---

### Task 2: Verify

**Files:** none (verification only)

- [ ] **Step 1: Manual check**

Open `/documents/{id}/rag`. Confirm:
- The block list sits inside one white rounded card with a header row (document icon + "เนื้อหาเอกสารกฎหมาย"), matching the screenshot's container.
- Each section renders as a soft, rounded card.
- The list still scrolls inside the card; the header stays fixed.
- **All existing functions work unchanged:** select → merge/delete/split bar, per-row split-line, chunk-type chip menu, history FAB/drawer, stepper "บันทึก" → law-info. No new buttons.

- [ ] **Step 2: Commit (only if manual check required tweaks)**

```bash
git add -A && git commit -m "fix(rag): container restyle polish after manual check"
```

---

## Self-Review

- **Spec coverage:** clean card container with titled header (Task 1 Steps 1-2), softer section cards (Step 2), functions preserved (Task 2 verify). ✓
- **Placeholders:** none — concrete markup + CSS; the one "keep the v-for markup as-is" note references existing code to preserve. ✓
- **Scope:** presentation only, single file; supersedes the 3-column plan; no new data or functions. ✓
