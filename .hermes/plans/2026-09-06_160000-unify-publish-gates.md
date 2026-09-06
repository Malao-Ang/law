# Plan: Unify publish validation gates — EditHub + ESignStatus + PublishConfirmDialog

## Goal
Consolidate all publish validation logic into one shared composable so EditHub, ESignStatusWorkspace, and PublishConfirmDialog use the same gates and conditions.

## Current state — 3 places validate differently

### 1. EditHubWorkspace.vue `togglePublished()` (line 458-540)
Sequential SweetAlert gates **before** opening dialog:
- Gate 1: e-Sign → `esign_confirmed_at` + `esign_sign_status !== 'rejected'` + `esign_send_response.status !== 'fail'`
- Gate 2: RAG → `(exported|ingested|step≥3)` AND `!rag_skipped` — **blocks if rag_skipped=true**
- Gate 3: Access scope → `meta.access_scope` exists
- Gate 4: Status ≠ ร่าง (3-button: auto-change / go edit / cancel)
- Old docs skip gates 1-2

### 2. PublishConfirmDialog.vue checklist (line 113-184)
Checklist shown in dialog **after** gates pass:
- e-Sign: checks only `esign_confirmed_at` — **missing send_response.status check**
- RAG: `(exported|ingested|step≥3) && !rag_skipped` — same logic ✅
- Access scope ✅
- Relations (optional) ✅
- Status ≠ ร่าง ✅
- Metadata ✅
- `hasRequiredFail` disables confirm button

### 3. ESignStatusWorkspace.vue `publish()` (line 669-683)
**NO validation at all** — directly saves `มีผลบังคับใช้` + publishes:
- No RAG check ❌
- No access_scope check ❌
- No status check ❌
- No e-Sign send_response check ❌
- Uses `PublishLawDialog` which has no checklist, no gates

## Problems identified

| Issue | Where |
|-------|-------|
| **RAG skipped = can still publish** | EditHub Gate 2 blocks correctly, but ESignStatus has no check at all |
| **e-Sign send_response.status=fail still shows "ลงนามสำเร็จ"** | PublishConfirmDialog line 127 only checks `esign_confirmed_at`, not `send_response` |
| **ESign page publish has zero validation** | `PublishLawDialog` is cosmetic only — no gates, no checklist |
| **Duplicate logic** | Same conditions coded 3 times with slight differences |

## Proposed solution

### Create shared composable: `usePublishGates`

File: `apps/app-laravel/resources/js/composables/usePublishGates.ts`

```ts
export interface PublishGate {
  key: string;
  label: string;
  ok: boolean;
  status: string;
  level: 'required' | 'optional';
}

export interface PublishGateResult {
  gates: PublishGate[];
  canPublish: boolean;         // all required gates pass
  hasRequiredFail: boolean;    // any required gate fails
  hasOptionalWarning: boolean; // any optional gate fails
}

export function evaluatePublishGates(
  meta: LawMeta | null,
  docStatus: DocumentStatus | null,
  relations: LawRelation[],
  isOldDoc: boolean,
): PublishGateResult
```

**Gate logic (single source of truth):**

1. **e-Sign** (required, skip for old docs):
   - `ok` = `esign_confirmed_at` exists AND `esign_sign_status !== 'rejected'` AND `esign_send_response?.status !== 'fail'`

2. **RAG** (required, skip for old docs):
   - `ok` = `(status in [exported, ingested] OR workflow_completed_step >= 3)` AND `rag_skipped !== true`

3. **Access scope** (required):
   - `ok` = `meta.access_scope` exists and not empty

4. **Relations** (optional):
   - `ok` = `relations.length > 0`

5. **Status** (required):
   - `ok` = `meta.status` exists AND `meta.status !== 'ร่าง'`

6. **Metadata** (required):
   - `ok` = `meta.title` AND `meta.law_type` AND (`meta.promulgation_date` OR `meta.effective_date`)

### Update consumers

#### EditHubWorkspace.vue
- Import `evaluatePublishGates`
- Replace inline gates 1-4 with: call `evaluatePublishGates()`, if `hasRequiredFail`, show first failing gate's SweetAlert, return
- Keep the special "ร่าง" 3-button SweetAlert behavior (auto-change status option) as a special case after gates pass

#### PublishConfirmDialog.vue
- Import `evaluatePublishGates`
- Replace inline checklist computed with: `evaluatePublishGates()` return value
- `hasRequiredFail` comes from composable

#### ESignStatusWorkspace.vue
- Replace `PublishLawDialog` with `PublishConfirmDialog` (same component as EditHub)
- Or: add gate check before `publish()` — call `evaluatePublishGates()`, if `hasRequiredFail`, show SweetAlert blocking publish
- Remove `PublishLawDialog.vue` if no longer used

### Delete if unused
- `apps/app-laravel/resources/js/components/esign/PublishLawDialog.vue` — replaced by shared `PublishConfirmDialog`

## Step-by-step tasks

### Task 1 — Create `usePublishGates.ts`
File: `apps/app-laravel/resources/js/composables/usePublishGates.ts`
- Export `evaluatePublishGates()` function
- Export types `PublishGate`, `PublishGateResult`
- Import `LawMeta`, `DocumentStatus`, `LawRelation` from types

### Task 2 — Update `PublishConfirmDialog.vue`
- Import `evaluatePublishGates`
- Replace inline checklist with composable call
- Pass `esign_send_response` in status check (currently missing)

### Task 3 — Update `EditHubWorkspace.vue`
- Import `evaluatePublishGates`
- Replace inline gate checks (lines 466-540) with composable
- Keep ร่าง special 3-button dialog as post-gate logic

### Task 4 — Update `ESignStatusWorkspace.vue`
- Replace `PublishLawDialog` with `PublishConfirmDialog`
- Add gate check before publish
- Remove import of `PublishLawDialog`

### Task 5 — Delete `PublishLawDialog.vue` if unused
Search for remaining references; delete if none.

## Verification
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: exit 0.

Manual test:
1. Skip RAG → try publish → should be blocked with "ยังไม่ได้จัดลำดับ RAG"
2. e-Sign send_response.status=fail → should NOT show "ลงนามสำเร็จ"
3. ESign page เผยแพร่ → should show checklist dialog, not cosmetic-only dialog

## Commits
```
feat(publish): create usePublishGates composable for unified validation
refactor(publish): use shared gates in EditHub, PublishConfirmDialog, ESignStatus
chore: remove unused PublishLawDialog
```
