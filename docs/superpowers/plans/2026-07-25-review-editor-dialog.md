# Review Editor as Modal Dialog — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Present the document review editor (`DocumentEditorShell`) as a full-screen modal dialog matching the screenshot — header (title + subtitle + X), an always-caution banner, the existing 3-row toolbar + A4 editor, and a footer (char count · autosave note, ยกเลิก, บันทึกข้อมูล) — with the workflow stepper visible behind it. Locked (published/e-signed) documents render read-only exactly as pictured; fresh-OCR (editable) documents are editable and hide the caution banner.

**Architecture:** The stepper (`WorkflowStepper`) and `WorkflowFooterBar` move OUT of `DocumentEditorShell` up to `ReviewPage`, which becomes the "behind" workflow page. `ReviewPage` then hosts a `<v-dialog fullscreen>` containing `DocumentEditorShell`, which is refactored to render dialog chrome (header + footer) around its existing toolbar + editor. No backend changes; all existing editing/save logic is preserved.

**Tech Stack:** Vue 3 + Vuetify 3 + TypeScript, TipTap.

**Behavior rules (from user's note — literal):**
- `props.locked === true` (published/exported): editor is **read-only** (`editable=false`), toolbar disabled, caution banner **shown** (matches screenshot).
- `props.locked === false` (fresh OCR, editable): editor **editable**, caution banner **hidden**.
- ⚠️ If you actually want the banner on the *editable* view instead, flip the single `showCautionBanner` condition in Task 2 Step 3.

**Verification:** frontend has no JS test runner — verify with `npm run typecheck`, `npm run build`, and a manual check of both a locked and an editable document.

---

### Task 1: Move stepper + footer-bar to `ReviewPage` and host the editor in a fullscreen dialog

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/review/ReviewPage.vue`

- [ ] **Step 1: Replace the `<DocumentEditorShell>` render with a workflow-background + dialog**

Replace the third top-level block (lines 13-17):

```vue
  <DocumentEditorShell
    v-else-if="documentStore.review"
    :document-id="documentId"
    :locked="locked"
  />
```

with:

```vue
  <template v-else-if="documentStore.review">
    <!-- Workflow page behind the dialog (stepper stays visible, dimmed by scrim) -->
    <div class="review-workflow-bg">
      <WorkflowStepper :step="2" description="อ่านทวน แก้ไข และจัดรูปแบบก่อนยืนยันนำเข้าระบบ" />
    </div>

    <v-dialog :model-value="true" fullscreen persistent scrim transition="dialog-bottom-transition">
      <DocumentEditorShell
        :document-id="documentId"
        :locked="locked"
        @close="goBack"
      />
    </v-dialog>
  </template>
```

- [ ] **Step 2: Import `WorkflowStepper` and add `goBack`**

In `<script setup>`, add the import (next to the `DocumentEditorShell` import):

```ts
import WorkflowStepper from '../../components/shared/WorkflowStepper.vue';
import { useRouter } from 'vue-router';
```

and inside the setup body (after `const reviewUiStore = ...`):

```ts
const router = useRouter();

function goBack(): void {
  router.push('/admin/upload');
}
```

- [ ] **Step 3: Add background style**

In `<style scoped>` add:

```css
.review-workflow-bg {
  min-height: 100vh;
  padding: 16px 24px;
  background: #f8fafc;
}
```

- [ ] **Step 4: Typecheck**

Run (on host): `cd apps/app-laravel && npm run typecheck`
Expected: PASS. (`DocumentEditorShell` gains a `close` emit in Task 2; if typecheck runs before Task 2, it still passes because `@close` on a component without the emit is allowed by Vue's typing — but run Task 2 before build.)

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/pages/review/ReviewPage.vue
git commit -m "feat(review): host editor in fullscreen dialog, stepper behind"
```

---

### Task 2: Refactor `DocumentEditorShell` into dialog chrome (header + banner + footer)

**Files:**
- Modify: `apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue`

- [ ] **Step 1: Remove the internal stepper + footer bar**

Delete these lines (currently 3-10):

```vue
    <WorkflowFooterBar
      :step="2"
      next-label="บันทึก"
      :next-loading="documentStore.saving"
      @back="router.push('/admin/upload')"
      @next="saveAndContinue"
    />
    <WorkflowStepper :step="2" description="อ่านทวน แก้ไข และจัดรูปแบบก่อนยืนยันนำเข้าระบบ" />
```

Then remove their now-unused imports from `<script setup>` (`WorkflowFooterBar`, `WorkflowStepper`). Keep `router` if still referenced elsewhere; otherwise remove it too (typecheck will flag unused).

- [ ] **Step 2: Add the dialog header as the first child of the root wrapper**

Immediately inside the root `<div class="d-flex flex-column" ...>` (before the `v-alert`), insert:

```vue
    <div class="review-dialog__header">
      <div class="min-width-0">
        <h2 class="text-h6 font-weight-bold mb-0">ตรวจทานเนื้อหาเอกสาร</h2>
        <p class="text-body-2 text-medium-emphasis mb-0">อ่านทวน แก้ไข และจัดรูปแบบก่อนยืนยันนำเข้าระบบ</p>
      </div>
      <v-btn icon="mdi-close" variant="text" @click="emit('close')" />
    </div>
```

- [ ] **Step 3: Replace the lock alert with the locked-only caution banner**

Replace the existing `v-alert` block (currently lines 12-22, the `v-if="props.locked"` lock message) with:

```vue
    <v-alert
      v-if="showCautionBanner"
      type="warning"
      variant="tonal"
      density="compact"
      class="mx-0 my-2"
      style="flex-shrink:0"
      prepend-icon="mdi-alert-outline"
    >
      กรุณาตรวจทานเนื้อหาให้ครบถ้วนก่อนยืนยัน เนื่องจากการแปลงไฟล์อัตโนมัติอาจมีความคลาดเคลื่อนในบางจุด
    </v-alert>
```

- [ ] **Step 4: Add the footer bar as the last child of the root wrapper**

Immediately before the closing `</div>` of the root wrapper (after the save-error alert block near line 186), insert:

```vue
    <div class="review-dialog__footer">
      <span class="text-caption text-medium-emphasis">
        {{ charCount.toLocaleString('th-TH') }} ตัวอักษร · บันทึกอัตโนมัติเมื่อปิด
      </span>
      <v-spacer />
      <v-btn variant="outlined" class="text-none" @click="emit('close')">ยกเลิก</v-btn>
      <v-btn color="admin-primary" class="text-none ml-2" :loading="documentStore.saving" @click="saveAndContinue">
        บันทึกข้อมูล
      </v-btn>
    </div>
```

- [ ] **Step 5: Declare the `close` emit, `charCount`, `showCautionBanner`, and read-only editor**

In `<script setup>`:

Add the emit declaration (after the existing `defineProps`):

```ts
const emit = defineEmits<{ (e: 'close'): void }>();
```

Add computeds (near the other `computed(...)` declarations):

```ts
const showCautionBanner = computed(() => props.locked);
const charCount = computed(() => editor.value?.getText().length ?? 0);
```

Make the editor read-only when locked. Locate the `useEditor({ ... })` call and add an `editable` option; also keep it reactive by watching `props.locked`:

```ts
// inside useEditor({ ... })
  editable: !props.locked,
```

Then, after the editor is created, add:

```ts
watchEffect(() => {
  editor.value?.setEditable(!props.locked);
});
```

(`watchEffect` is already imported in this file.)

- [ ] **Step 6: Add header/footer styles**

In `<style scoped>` add:

```css
.review-dialog__header {
  align-items: center;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  gap: 16px;
  justify-content: space-between;
  padding: 12px 24px;
  flex-shrink: 0;
}
.review-dialog__footer {
  align-items: center;
  background: #fff;
  border-top: 1px solid #e2e8f0;
  display: flex;
  padding: 10px 24px;
  flex-shrink: 0;
}
```

Note: the root wrapper already has `height:100dvh; overflow:hidden` and the `editor-shell-scroll` grows — the header and footer are flex-shrink:0 so the editor scroll area fills the middle. Remove the root wrapper's bottom `padding` (`padding:16px 24px 60px`) → `padding:0` so the footer sits flush; adjust the scroll area padding if needed during the manual check.

- [ ] **Step 7: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS. Fix any unused-import errors from Step 1.

- [ ] **Step 8: Commit**

```bash
git add apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue
git commit -m "feat(review): render editor as dialog chrome with header/footer"
```

---

### Task 3: Verify both states

**Files:** none (verification only)

- [ ] **Step 1: Editable document (fresh OCR)**

Open `http://localhost:8000/documents/{id}/review` for a non-exported document. Confirm: fullscreen dialog with header + X, **no caution banner**, toolbar active, editor editable, footer shows char count + ยกเลิก + บันทึกข้อมูล, stepper visible behind the scrim. บันทึกข้อมูล saves and returns; X/ยกเลิก returns to `/admin/upload`.

- [ ] **Step 2: Locked document (published / e-signed)**

Open a document whose `esign_exported_at` is set. Confirm: same dialog as pictured, **caution banner shown**, toolbar disabled + editor read-only (typing does nothing), footer buttons present.

- [ ] **Step 3: Commit (only if manual check required tweaks)**

```bash
git add -A && git commit -m "fix(review): dialog polish after manual check"
```

---

## Self-Review

- **Spec coverage:** dialog presentation (Task 1), header/banner/footer chrome (Task 2), stepper-behind (Task 1 Step 1), locked=read-only + banner / editable=no-banner (Task 2 Steps 3,5), char count + save + close (Task 2 Steps 4,5). ✓
- **Placeholders:** none — concrete code per step; the one judgement call (root padding) is flagged for the manual check. ✓
- **Type consistency:** `emit('close')` declared in Task 2 Step 5, used in Task 2 Steps 2,4 and consumed by `@close` in Task 1 Step 1. `charCount`/`showCautionBanner` defined Step 5, used Steps 3,4. ✓
- **No new backend / no mocked data.** ✓
