# Plan: Publish Confirmation Dialog with Condition Checklist

## Goal

เพิ่ม dialog confirm ก่อนเผยแพร่ทุกหน้า — แสดงเงื่อนไขที่ครบ/ไม่ครบ (RAG, relations, metadata, etc.) ให้ user ตัดสินใจ

## Current Problem

1. `togglePublished()` ใน EditHubWorkspace + ESignDocumentWorkspace เปลี่ยนสถานะเผยแพร่ทันทีไม่มี confirm
2. เอกสารใหม่สามารถ skip RAG + ความสัมพันธ์ได้ (ปุ่ม "ขั้นถัดไป") แต่ไม่มีการเตือนเมื่อจะเผยแพร่ว่ายังขาดอะไร
3. ไม่มี publish readiness checklist แสดงก่อน confirm

## Design

เมื่อ user กด toggle เผยแพร่ → แสดง **PublishConfirmDialog** ที่มี:

```
┌─────────────────────────────────────────┐
│ ⚠ ยืนยันการเผยแพร่เอกสาร               │
│                                          │
│ เงื่อนไขการเผยแพร่:                      │
│                                          │
│ ✅ ข้อมูล METADATA ครบถ้วน              │
│ ✅ โครงสร้างเนื้อหาเอกสาร               │
│ ⚠️ จัดลำดับ RAG (ข้าม)                  │
│ ⚠️ ความสัมพันธ์กฎหมาย (ยังไม่มี)        │
│ ✅ กำหนดสิทธิ์การเข้าถึง                │
│                                          │
│ ⚠ ท่านข้ามขั้นตอนบางรายการ              │
│ ต้องการเผยแพร่เอกสารนี้หรือไม่?         │
│                                          │
│          [ยกเลิก]  [ยืนยันเผยแพร่]      │
└─────────────────────────────────────────┘
```

เมื่อ toggle OFF (ยกเลิกเผยแพร่):
```
┌─────────────────────────────────────────┐
│ ยืนยันยกเลิกการเผยแพร่                  │
│                                          │
│ เอกสารนี้จะไม่แสดงในหน้าสาธารณะอีกต่อไป │
│                                          │
│          [ยกเลิก]  [ยืนยัน]              │
└─────────────────────────────────────────┘
```

## Checklist conditions

| # | เงื่อนไข | Check | ระดับ |
|---|----------|-------|-------|
| 1 | ข้อมูล METADATA ครบ (title + law_type + วันที่) | `meta.title && meta.law_type && (meta.promulgation_date \|\| meta.effective_date)` | required |
| 2 | โครงสร้างเนื้อหา (มี block) | `review.summary.block_count > 0` | required |
| 3 | จัดลำดับ RAG | `status === 'exported' \|\| status === 'ingested'` หรือ `workflow_completed_step >= 2` | optional (แสดง warning ถ้า skip) |
| 4 | ความสัมพันธ์กฎหมาย | `review.relations?.length > 0` | optional (แสดง warning ถ้าไม่มี) |
| 5 | กำหนดสิทธิ์ | `meta.access_scope` is set | optional |
| 6 | ผ่านการลงนาม e-Sign | `esign_confirmed_at` exists | optional |

**old doc**: skip check #2 (อาจไม่มี block), skip #3 (ไม่ผ่าน RAG), skip #6 (อาจไม่ผ่าน e-sign)

## Step-by-step Tasks

### Task 1: Create `PublishConfirmDialog.vue`

**File:** `apps/app-laravel/resources/js/components/shared/PublishConfirmDialog.vue`

Props:
- `modelValue: boolean` — v-model for open/close
- `publishing: boolean` — true = กำลังจะเผยแพร่, false = กำลังจะยกเลิก
- `documentId: string`
- `loading: boolean`

Component reads from `useDocumentStore()` to compute checklist items. Each item: `{ key, label, ok, status, level: 'required' | 'optional' }`.

Emits:
- `confirm` — user confirmed
- `cancel` — user cancelled

Template: `v-dialog` with `v-card`, checklist items with ✅/⚠️ icons, warning text if any optional skipped, confirm/cancel buttons.

### Task 2: Update `EditHubWorkspace.vue`

**File:** `apps/app-laravel/resources/js/components/edit/EditHubWorkspace.vue`

Replace direct `togglePublished()` → open `PublishConfirmDialog`. On confirm → save `published_date`. On cancel → revert toggle.

Changes:
1. Add `publishDialogOpen` ref + `publishDialogNext` ref (stores the toggle target)
2. `@update:model-value` on v-switch → set `publishDialogOpen = true, publishDialogNext = next`
3. On dialog confirm → call existing `documentStore.saveLawMeta({ published_date: ... })`
4. On dialog cancel → do nothing (switch stays at current state)
5. Import + render `PublishConfirmDialog`

### Task 3: Update `ESignDocumentWorkspace.vue`

**File:** `apps/app-laravel/resources/js/components/esign/ESignDocumentWorkspace.vue`

Same pattern as Task 2 — add dialog before publish toggle in edit mode.

### Task 4: Run typecheck

```bash
cd apps/app-laravel && npm run typecheck
```

Commit: `feat(publish): add confirmation dialog with readiness checklist before publish/unpublish`

## Risks

- Old docs ที่ไม่มี blocks/relations ยังต้อง publish ได้ → checklist ต้อง mark เงื่อนไขเหล่านั้นเป็น optional สำหรับ old doc
- Dialog ไม่ block publish — แค่แสดง warning ถ้า skip ขั้นตอน, user ยังกด confirm ได้
