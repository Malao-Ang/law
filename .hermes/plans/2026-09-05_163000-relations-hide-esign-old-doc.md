# Plan: ซ่อนปุ่ม "ส่งไป E-Sign" ในหน้า relations สำหรับเอกสารเก่า

## Goal
เอกสารเก่า (`document_type === 'old'`) ต้องไม่แสดงปุ่ม "ส่งไป E-Sign" ในหน้า `/documents/:id/relations`

## Current context / assumptions
- File: `apps/app-laravel/resources/js/pages/law-relations/LawRelationsPage.vue`
- `WorkflowFooterBar` รับ `extra-label` prop — ถ้าเป็น string จะแสดงปุ่ม, ถ้าเป็น `undefined` จะซ่อน
- `isOld` computed มีอยู่แล้ว (line 234): `computed(() => documentStore.review?.law_meta?.document_type === 'old')`
- ปัจจุบัน template (line 11-16) ส่ง `extra-label="ส่งไป E-Sign"` โดยไม่เช็ค `isOld`

## Architecture / proposed approach
เปลี่ยน `extra-label`, `extra-icon`, `extra-loading`, `extra-disabled` ให้เป็น conditional — ส่งค่าจริงเฉพาะเอกสารใหม่, ส่ง `undefined` สำหรับเอกสารเก่า ทำให้ `WorkflowFooterBar` ซ่อนปุ่มอัตโนมัติ

## Step-by-step tasks

### Task 1 — Conditionally hide extra button for old docs
File: `apps/app-laravel/resources/js/pages/law-relations/LawRelationsPage.vue`

Replace lines 11-16 (inside `<WorkflowFooterBar>` attributes):

**Current:**
```vue
      extra-label="ส่งไป E-Sign"
      extra-icon="mdi-draw-pen"
      :next-loading="documentStore.saving && !esignLeaving"
      :extra-loading="documentStore.saving && esignLeaving"
      :next-disabled="documentStore.saving"
      :extra-disabled="documentStore.saving"
```

**New:**
```vue
      :extra-label="isOld ? undefined : 'ส่งไป E-Sign'"
      :extra-icon="isOld ? undefined : 'mdi-draw-pen'"
      :next-loading="documentStore.saving && !esignLeaving"
      :extra-loading="!isOld && documentStore.saving && esignLeaving"
      :next-disabled="documentStore.saving"
      :extra-disabled="isOld || documentStore.saving"
```

### Verify
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: exit 0

### Commit
```bash
git add apps/app-laravel/resources/js/pages/law-relations/LawRelationsPage.vue && git commit -m "fix(relations): hide e-Sign button for old/external documents"
```

## Tests / validation
- Manual: open `/documents/<old-doc-id>/relations` → ปุ่ม "ส่งไป E-Sign" ต้องไม่แสดง
- Manual: open `/documents/<new-doc-id>/relations` → ปุ่ม "ส่งไป E-Sign" ยังแสดงปกติ
- Typecheck ผ่าน

## Risks, tradeoffs, and open questions
- ไม่มี — แก้แค่ 1 ไฟล์, 1 จุด, ใช้ `isOld` ที่มีอยู่แล้ว
