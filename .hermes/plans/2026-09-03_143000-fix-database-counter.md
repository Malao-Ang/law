# Plan: Fix Database Page Document Count Mismatch

## Goal

แก้ไขตัวเลข "พบผลการค้นหา X รายการ" ในหน้า `/database` ที่แสดงจำนวนไม่ตรงกับผลลัพธ์จริง

## Current Context / Assumptions

พบ 2 จุดบกพร่องที่ทำให้ counter ผิด:

### Bug 1 — Backend: ES path ใช้จำนวนแค่หน้าปัจจุบันแทน total ข้ามทุกหน้า

**ไฟล์:** `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php` line 54

```php
'total' => count($esRows) + count($supplement),
```

- `$esRows` = ผลลัพธ์ ES **เฉพาะหน้าปัจจุบัน** (max 20 รายการ) ที่ผ่าน publishedIds filter
- `$supplement` = ผลจาก file-based ที่ ES ไม่มี **เฉพาะหน้าปัจจุบัน**
- ผลลัพธ์: ค้นหาได้ 100 รายการ แต่แสดง "พบ 20 รายการ" เพราะนับแค่หน้าแรก

ในขณะที่ file-based path (line 152 ของ method `fileBasedSearch`) นับถูก:
```php
$total = count($rows);          // นับทั้งหมดก่อน pagination
$paged = array_slice($rows, ($page - 1) * $perPage, $perPage);
```

ES service ก็คืน total ที่ถูก (`$esResult['total']` = cardinality aggregation ของ law_id) แต่ controller ไม่ใช้มัน

### Bug 2 — Frontend: `sortedResults` filter ซ่อน restricted docs แต่ counter ไม่ปรับ

**ไฟล์:** `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`

- Line 290: แสดง `searchStore.total` (จำนวนจาก API)
- Line 642: `sortedResults` filter ด้วย `canDisplayLawResult()` ซึ่งซ่อน restricted+requires_permission docs สำหรับ user ที่ไม่ login

ทำให้ counter บอก 15 แต่แสดงจริง 12 cards เพราะ 3 รายการถูกซ่อน

แต่ template มี restricted card layout อยู่แล้ว (lines 411-418) ที่แสดง lock icon + "เข้าสู่ระบบ" button → ไม่จำเป็นต้องซ่อนทั้ง card

## Architecture / Proposed Approach

- Bug 1: ใช้ `max($fileBased['total'], count($results))` แทน `count($esRows) + count($supplement)` — file-based total เป็นตัวเลขที่ถูกต้องที่สุดเพราะนับ ALL matching published docs ไม่ใช่แค่หน้าปัจจุบัน
- Bug 2: เอา `canDisplayLawResult` filter ออกจาก `sortedResults` — restricted docs จะแสดงพร้อม lock icon ตาม template ที่มีอยู่แล้ว (ดีกว่าซ่อนแล้ว counter ไม่ตรง)

## Step-by-step Tasks

### Task 1: Fix backend total (RED → GREEN)

**ไฟล์:** `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php`

**แก้ไข line 54 — เปลี่ยน total computation:**

```php
// BEFORE (line 54):
'total' => count($esRows) + count($supplement),

// AFTER:
'total' => max((int) ($fileBased['total'] ?? 0), count($results)),
```

**เหตุผล:**
- `$fileBased['total']` นับ ALL matching published docs (ไม่ใช่แค่ current page)
- `max(..., count($results))` ป้องกันกรณี total น้อยกว่าผลลัพธ์จริงบนหน้า (เช่น ES หา tokenized match ที่ file-based substring matching พลาด)

**Verify:**
```bash
docker compose exec laravel-app php artisan test --filter=LawSearchTest
```
Expected: existing tests pass (test ปัจจุบัน mock ES service ทั้งหมด total ไม่ถูก assert ตรง ๆ ยกเว้น `test_search_endpoint_returns_results_and_facets` ที่ mock ทั้ง response)

### Task 2: Fix frontend — stop hiding restricted docs (sortedResults filter)

**ไฟล์:** `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue`

**แก้ไข line 642:**
```typescript
// BEFORE:
const sortedResults = computed(() => {
  const items = searchStore.results.filter((law) => canDisplayLawResult(law, auth.isAuthenticated));

// AFTER:
const sortedResults = computed(() => {
  const items = [...searchStore.results];
```

**เหตุผล:**
- Template มี restricted card layout (lines 411-418) พร้อมแสดง lock icon อยู่แล้ว
- ซ่อนทั้ง card ทำให้ counter ไม่ตรงกับผลที่แสดง
- ผู้ใช้ควรเห็นว่ามีเอกสารอยู่ แต่ต้อง login ถึงจะเข้าถึงได้

**Cleanup:** ลบ unused import `canDisplayLawResult` (line 454):
```typescript
// BEFORE (line 454):
import { canDisplayLawResult } from '../../utils/lawAccess';

// AFTER: ลบบรรทัดนี้ออก
```

**Verify:**
```bash
cd apps/app-laravel && npm run typecheck
```
Expected: no type errors

### Task 3: Run full test suite

```bash
docker compose exec laravel-app php artisan test --filter=LawSearchTest
docker compose exec laravel-app php artisan test --filter=LawSearchFuzzyTest
docker compose exec laravel-app php artisan test --filter=LawSearchRestrictedTest
```

Expected: all pass

### Task 4: Commit

```bash
git add -A
git commit -m "fix(search): correct document count on database page

- Backend: use file-based total (all matching published docs) instead of
  current-page count for ES path
- Frontend: stop hiding restricted docs from results — they already render
  with lock icon and login prompt, hiding them caused counter mismatch"
```

## Risks & Tradeoffs

- **`$fileBased['total']` อาจน้อยกว่า ES total จริง** — เพราะ file-based ใช้ substring matching ส่วน ES ใช้ Thai tokenizer ที่ฉลาดกว่า แต่ `max(fileBased, count(results))` ป้องกัน total ต่ำกว่าจำนวนบนหน้า ในทางปฏิบัติ file-based total มักจะถูกต้องเพราะ search query ภาษาไทยส่วนใหญ่ match ทั้ง substring และ tokenized
- **PublicHomePage.vue ก็ใช้ `canDisplayLawResult`** (line 234) — แต่หน้านั้นไม่มี counter ที่ต้องตรง ไม่จำเป็นต้องแก้ในรอบนี้ (แก้ได้ภายหลังถ้าต้องการ consistency)
- **`lawAccess.ts` ยังถูกใช้ใน `PublicHomePage.vue`** — ไม่ลบไฟล์ ลบแค่ import ในหน้า database
