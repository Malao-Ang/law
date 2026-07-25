# Law-Info Page Validation + Focus — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix validation on the law-info page (`/documents/:id/law-info`) so error messages don't appear on fields the user already filled, invalid submits scroll to and focus the first offending field, and all date fields are validated (required effective date + logical date order).

**Architecture:** All changes are in `LawInfoPage.vue` using Vuetify's built-in per-field `:rules` and the `errors[]` array returned by `VForm.validate()`. The misleading static "required fields" banner is replaced by a generic message; per-field rules become the single source of truth for what's wrong. `ThaiDatePicker` already forwards `:rules`/`:required` to a real inner `v-text-field`, so it participates in `validate()` and exposes an `id` for focusing — no change needed there.

**Tech Stack:** Vue 3 + Vuetify 3 + TypeScript.

**Interpretation of "validate all dates" (flag for confirmation):** `effective_date` stays required; `promulgation_date` and `expiry_date` stay optional but gain **logical order** rules — effective must not precede promulgation, and expiry (when present and not "ไม่มีวันสิ้นสุด") must be after effective. If you only wanted required-ness and not cross-field order, drop the order rules in Task 3.

**Verification:** frontend has no JS test runner — verify with `npm run typecheck`, `npm run build`, and the precise manual checks in each task.

---

### Task 1: Focus + scroll to the first invalid field on failed submit

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue:399-406`

- [ ] **Step 1: Use the validate() errors array to focus the first bad field**

Replace `saveAndNext` (lines 399-415) up to the early return so it focuses the first errored input instead of scrolling to the banner:

```ts
async function saveAndNext(): Promise<void> {
  const result = await formRef.value?.validate();
  if (!result?.valid) {
    validationFailed.value = true;
    await nextTick();
    focusFirstError(result?.errors ?? []);
    return;
  }
  validationFailed.value = false;
  const payload = buildLawMetaPayload();
  const saved = await documentStore.saveLawMeta(payload);
  if (!saved) return;
  form.value = { ...form.value, ...payload };
  const progressed = await documentStore.completeWorkflowStep(4);
  if (!progressed) return;
  router.push(`/documents/${props.documentId}/relations`);
}
```

- [ ] **Step 2: Add the `focusFirstError` helper**

Immediately before `saveAndNext`, add:

```ts
// Vuetify's validate() returns errors in field-registration order, each with the
// input's DOM id. Scroll the first offender into view and focus it so the user
// lands exactly on the field to fix.
function focusFirstError(errors: { id: string | number; errorMessages: string[] }[]): void {
  const firstId = errors[0]?.id;
  if (firstId == null) return;
  const el = document.getElementById(String(firstId));
  if (!el) return;
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  // readonly date inputs still accept focus; wrap in rAF so scroll settles first.
  requestAnimationFrame(() => el.focus());
}
```

- [ ] **Step 3: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 4: Manual check**

Open a document's `/law-info` with empty ชื่อเอกสาร and click ถัดไป. The page scrolls to and focuses the ชื่อเอกสาร field (not just the top banner).

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue
git commit -m "feat(law-info): focus first invalid field on failed submit"
```

---

### Task 2: Replace the misleading static required-fields banner

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue:27-36`

The banner currently hard-codes every required field name, so it wrongly implies filled fields are still missing. Per-field Vuetify rules already show precise errors; make the banner a generic prompt.

- [ ] **Step 1: Make the warning banner generic**

Replace the `v-alert` block (lines 27-36):

```vue
        <v-alert
          v-if="validationFailed"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4"
          icon="mdi-alert-circle-outline"
        >
          กรุณากรอกข้อมูลที่จำเป็นให้ครบก่อนดำเนินการต่อ: ชื่อเอกสาร, ประเภทเอกสาร, กลุ่มกฎหมาย, หน่วยงานรับผิดชอบ, วันที่มีผลบังคับใช้
        </v-alert>
```

with:

```vue
        <v-alert
          v-if="validationFailed"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4"
          icon="mdi-alert-circle-outline"
        >
          กรุณาตรวจสอบและกรอกข้อมูลในช่องที่มีเครื่องหมายแจ้งเตือนให้ครบถ้วน
        </v-alert>
```

- [ ] **Step 2: Clear the banner once the form becomes valid again**

So the banner disappears as soon as the user fixes the fields (not only on the next submit), add a watch after the `saveAndNext` function (or near the other watch). Add:

```ts
// Once the user has triggered validation, hide the generic banner as soon as
// every field passes, without waiting for another submit.
watch(form, async () => {
  if (!validationFailed.value) return;
  const result = await formRef.value?.validate();
  if (result?.valid) validationFailed.value = false;
}, { deep: true });
```

Note: `validate()` here re-runs rules but, because `validate-on="submit lazy"`, it will surface messages too — acceptable since the banner only shows after the first failed submit. `nextTick` is already imported; `watch` is already imported.

- [ ] **Step 3: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 4: Manual check**

Fill only ชื่อเอกสาร, leave others empty, click ถัดไป: the generic banner appears and each empty required field shows its own red message; the filled ชื่อเอกสาร shows NO error. Fill the rest → banner clears without another click.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue
git commit -m "feat(law-info): generic validation banner; rely on per-field rules"
```

---

### Task 3: Validate all date fields (required effective + logical order)

**Files:**
- Modify: `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue:149-180` (date pickers) and `<script setup>` (rules + helper)

- [ ] **Step 1: Add a date-time helper and the three date rule sets**

In `<script setup>`, after `noExpiry` is declared (near line 272), add:

```ts
// Parse an ISO yyyy-mm-dd (what ThaiDatePicker emits) to epoch ms, or null.
function dateMs(value: unknown): number | null {
  if (typeof value !== 'string' || value === '') return null;
  const t = new Date(`${value}T00:00:00`).getTime();
  return Number.isNaN(t) ? null : t;
}

const promulgationDateRules = [
  (v: unknown) => {
    const prom = dateMs(v);
    const eff = dateMs(form.value.effective_date);
    if (prom == null || eff == null) return true;
    return prom <= eff || 'ต้องไม่หลังวันที่มีผลบังคับใช้';
  },
];

const effectiveDateRules = [
  (v: unknown) => !!v || 'จำเป็นต้องระบุ',
  (v: unknown) => {
    const eff = dateMs(v);
    const prom = dateMs(form.value.promulgation_date);
    if (eff == null || prom == null) return true;
    return eff >= prom || 'ต้องไม่ก่อนวันที่ประกาศ';
  },
];

const expiryDateRules = [
  (v: unknown) => {
    if (noExpiry.value) return true;
    const exp = dateMs(v);
    const eff = dateMs(form.value.effective_date);
    if (exp == null || eff == null) return true;
    return exp > eff || 'ต้องอยู่หลังวันที่มีผลบังคับใช้';
  },
];
```

- [ ] **Step 2: Wire the rules onto the three date pickers**

Replace the date `v-row` (lines 149-180) so every `ThaiDatePicker` carries its rules:

```vue
          <v-row dense>
            <v-col cols="12" sm="6">
              <ThaiDatePicker
                v-model="form.promulgation_date"
                label="วันที่ประกาศ"
                :rules="promulgationDateRules"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <ThaiDatePicker
                v-model="form.effective_date"
                label="วันที่มีผลบังคับใช้ *"
                required
                :rules="effectiveDateRules"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <ThaiDatePicker
                v-model="form.expiry_date"
                label="วันที่สิ้นสุดการใช้"
                :disabled="noExpiry"
                disabled-placeholder="ไม่มีวันสิ้นสุด"
                :rules="expiryDateRules"
              />
              <v-checkbox
                v-model="noExpiry"
                label="ไม่มีวันสิ้นสุด"
                density="compact"
                hide-details
                class="mt-1"
                @update:model-value="v => { if (v) form.expiry_date = null }"
              />
            </v-col>
          </v-row>
```

- [ ] **Step 3: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 4: Manual check**

- Leave วันที่มีผลบังคับใช้ empty → click ถัดไป → it errors "จำเป็นต้องระบุ" and receives focus.
- Set วันที่ประกาศ later than วันที่มีผลบังคับใช้ → submit → effective shows "ต้องไม่ก่อนวันที่ประกาศ".
- Set วันที่สิ้นสุด before/equal to วันที่มีผลบังคับใช้ (with "ไม่มีวันสิ้นสุด" unchecked) → submit → expiry shows "ต้องอยู่หลังวันที่มีผลบังคับใช้".
- Check "ไม่มีวันสิ้นสุด" → expiry disabled, no expiry error.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue
git commit -m "feat(law-info): validate all date fields with logical order rules"
```

---

## Self-Review

- **Spec coverage:** errors not shown on filled fields (Task 2 — generic banner + per-field rules), focus the empty field (Task 1 — `focusFirstError`), all dates validated (Task 3 — three rule sets). ✓
- **Placeholders:** none — every step has concrete code + a precise manual check. ✓
- **Type consistency:** `focusFirstError(errors)` shape matches Vuetify's `validate()` return (`{ id, errorMessages }[]`); `dateMs`, `promulgationDateRules`, `effectiveDateRules`, `expiryDateRules` defined in Task 3 Step 1 and used in Step 2. `nextTick`/`watch` already imported. ✓
- **No test runner:** TDD's failing-test-first is replaced by typecheck/build + explicit manual checks — the honest constraint for this frontend. ✓
- **Flagged decision:** cross-field date order is my interpretation of "validate all dates"; trivially removable if unwanted. ✓
