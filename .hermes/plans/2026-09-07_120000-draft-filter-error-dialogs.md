# Plan: 3 Fixes — Search draft filter, MongoBlobStore error dialog, e-Sign error dialog

## Problem 1: เอกสาร "ร่าง" แสดงใน public search

### Root cause
`LawSearchController.php::fileBasedSearch()` line 189-194 filter:
```php
if (($row['status'] ?? '') !== 'ingested') continue;     // pipeline status
if (($row['published_date'] ?? '') === '') continue;     // has publish date
```

`status = 'ingested'` คือ pipeline status (เอกสารถูก process แล้ว) ≠ law status (ร่าง/มีผลบังคับใช้)
ดังนั้นเอกสาร `law_meta.status = 'ร่าง'` แต่ `pipeline status = ingested` ผ่าน filter ได้

### Fix
เพิ่ม filter ตรวจ `law_meta.status !== 'ร่าง'` ใน `fileBasedSearch()`:
```php
if (($row['status'] ?? '') !== 'ingested') continue;
if (($row['published_date'] ?? '') === '') continue;
// เพิ่ม: ซ่อนร่างจาก public search
if (($row['law_status'] ?? $row['law_meta']['status'] ?? '') === 'ร่าง') continue;
```

ต้องตรวจว่า `listLawMeta()` return `law_status` หรือ `law_meta.status` ใน key ไหน — อ่าน `ReviewStore::listLawMeta()` ก่อน

### Also fix: ES search path
ใน ES search path (line 67-74) มี published allowlist แต่ไม่ filter ร่าง — เพิ่มตรวจ `law_meta.status !== 'ร่าง'` ตรงนั้นด้วย

---

## Problem 2: MongoBlobStore error แสดงเป็น inline v-alert ซ้อนหลัง UI

### Root cause  
`RagManageWorkspace.vue` แสดง error จาก `composeStore.error` เป็น inline element ใน layout
ทำให้ถูกซ้อนอยู่ข้างหลัง dialog/overlay อื่น

### Fix
เปลี่ยนจาก `v-else-if="composeStore.error"` inline block → ใช้ `Swal.fire({ icon: 'error', ... })` เมื่อ error เกิดขึ้น

Pattern:
```ts
watch(() => composeStore.error, (err) => {
  if (err) {
    void Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: err });
  }
});
```

---

## Problem 3: e-Sign citizen ID error ซ่อนอยู่หลัง v-alert

### Root cause
`ESignPreviewWorkspace.vue` และ `ESignStatusWorkspace.vue` ใช้ `v-alert` inline สำหรับ `errorFlash`
เมื่อ API ตอบ error (เช่น citizen_id ไม่ครบ 13 หลัก) alert render ใน DOM แต่ถูก dialog/overlay ซ้อนทับ

### Fix
เปลี่ยนจาก `v-alert` errorFlash → `Swal.fire({ icon: 'error' })` ทุกที่ที่ set `errorFlash`

Files:
- `components/esign/ESignPreviewWorkspace.vue` — เปลี่ยน errorFlash setter → Swal
- `components/esign/ESignStatusWorkspace.vue` — เปลี่ยน errorFlash v-alert → Swal
- `components/esign/ESignDocumentWorkspace.vue` — ตรวจสอบเพิ่มเติม

---

## Task list (ordered)

### Task 1 — Filter ร่าง ออกจาก public search
File: `app/Http/Controllers/Api/LawSearchController.php`
1. อ่าน `ReviewStore::listLawMeta()` ดูว่า law status อยู่ key ไหน
2. เพิ่ม filter ใน `fileBasedSearch()` หลัง published_date check
3. เพิ่ม filter ใน ES path's `$publishedIds` loop

### Task 2 — MongoBlobStore error → SweetAlert
File: `components/rag/RagManageWorkspace.vue`
- เพิ่ม `watch(composeStore.error, ...)` → `Swal.fire({ icon: 'error' })`
- ลบ/ซ่อน inline error block (เพราะ Swal จะแทนที่)

### Task 3 — e-Sign error → SweetAlert
Files:
- `components/esign/ESignPreviewWorkspace.vue`
- `components/esign/ESignStatusWorkspace.vue`
- ทุกที่ที่ `errorFlash = 'some error'` → เปลี่ยนเป็น `Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: errorMsg })`
- ลบ `<v-alert v-if="errorFlash" ...>` ออก (หรือ keep ไว้ก็ได้แต่ Swal จะ show ก่อน)

---

## Verification
```bash
cd apps/app-laravel && npm run typecheck  # exit 0
```

## Commits
```
fix(search): hide ร่าง documents from public search results
fix(rag): show MongoBlobStore commit error as SweetAlert dialog
fix(esign): show errorFlash as SweetAlert dialog instead of hidden v-alert
```
