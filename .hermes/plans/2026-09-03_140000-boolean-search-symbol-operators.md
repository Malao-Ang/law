# Plan: Boolean Search — Add Symbol Operators `&` `|` `~`

## Goal

ให้ระบบค้นหา Boolean รองรับ `&` (AND), `|` (OR), `~` (NOT) เป็น alias ของ `AND`/`OR`/`NOT` ที่มีอยู่แล้ว

## Current Context

**ไฟล์หลัก:** `apps/app-laravel/app/Services/Search/LawSearchQuery.php`

`parseTerms()` (L153-211) tokenize query ด้วย regex แล้ววน loop ตรวจ keyword:
- `AND` / `และ` → operator AND ✓
- `OR` / `หรือ` → operator OR ✓
- `NOT` / `ไม่` → negateNext ✓
- `-term` (prefix) → negation ✓
- `~term` (prefix) → negation ✓

**จุดบกพร่องที่พบ (3 จุด):**

1. **`&` ไม่ถูก recognize เป็น AND** — ถูก tokenize เป็น search term ธรรมดา ทำให้ค้นหาคำว่า `&` แทนที่จะเป็น operator
2. **`|` ไม่ถูก recognize เป็น OR** — เช่นเดียวกัน ถูกใช้เป็น search term
3. **`~` standalone (มี space ก่อน term) ไม่ถูก recognize เป็น NOT** — เช่น `ภาษี ~ ค่าเดินทาง` → `~` ถูกเพิ่มเป็น search term (length == 1 ไม่ผ่าน check `mb_strlen($token) > 1` ที่ L186) แทนที่จะ negate term ถัดไป

**ส่วนที่ทำงานถูกต้องแล้ว:**
- `ภาษี AND ค่าเดินทาง` ✓
- `ภาษี OR ค่าเดินทาง` ✓
- `ภาษี NOT ค่าเดินทาง` ✓
- `-ค่าเดินทาง` ✓
- `~ค่าเดินทาง` ✓
- `"exact phrase"` ✓
- Boolean query → Elasticsearch expanded DSL (`buildExpandedMust`) ✓
- Boolean query → file-based search (`matchesText` → `groupMatchesText`) ✓

## Approach

แก้ไข `parseTerms()` ใน `LawSearchQuery.php` เพิ่ม `&` / `|` / `~` เข้า operator check list (3 บรรทัด) ไม่กระทบ logic อื่นเพราะใช้โครงสร้างเดียวกับ AND/OR/NOT ที่มีอยู่

## Step-by-step Tasks

### Task 1: เขียน unit test ที่ fail ก่อน (RED)

**ไฟล์:** สร้าง `apps/app-laravel/tests/Unit/LawSearchQueryTest.php`

```php
<?php

namespace Tests\Unit;

use App\Services\Search\LawSearchQuery;
use PHPUnit\Framework\TestCase;

class LawSearchQueryTest extends TestCase
{
    public function test_ampersand_is_and_operator(): void
    {
        $query = LawSearchQuery::parse('ภาษี & ค่าเดินทาง');
        $this->assertTrue($query->isBoolean());
        $terms = $query->terms();
        $this->assertCount(2, $terms);
        $this->assertSame('AND', $terms[0]['operator']);
        $this->assertSame('ภาษี', $terms[0]['value']);
        $this->assertSame('AND', $terms[1]['operator']);
        $this->assertSame('ค่าเดินทาง', $terms[1]['value']);
    }

    public function test_pipe_is_or_operator(): void
    {
        $query = LawSearchQuery::parse('ภาษี | ค่าเดินทาง');
        $this->assertTrue($query->isBoolean());
        $groups = $query->orGroups();
        $this->assertCount(2, $groups);
        $this->assertSame('ภาษี', $groups[0][0]['value']);
        $this->assertSame('ค่าเดินทาง', $groups[1][0]['value']);
    }

    public function test_tilde_standalone_is_not_operator(): void
    {
        $query = LawSearchQuery::parse('ภาษี ~ ค่าเดินทาง');
        $this->assertTrue($query->isBoolean());
        $terms = $query->terms();
        $this->assertCount(2, $terms);
        $this->assertFalse($terms[0]['negated']);
        $this->assertTrue($terms[1]['negated']);
        $this->assertSame('ค่าเดินทาง', $terms[1]['value']);
    }

    public function test_tilde_prefix_still_works(): void
    {
        $query = LawSearchQuery::parse('~ค่าเดินทาง');
        $this->assertTrue($query->isBoolean());
        $terms = $query->terms();
        $this->assertCount(1, $terms);
        $this->assertTrue($terms[0]['negated']);
        $this->assertSame('ค่าเดินทาง', $terms[0]['value']);
    }

    public function test_matches_text_with_symbol_operators(): void
    {
        // & = AND: both must match
        $query = LawSearchQuery::parse('ภาษี & ที่ดิน');
        $this->assertTrue($query->matchesText('ภาษีที่ดินและสิ่งปลูกสร้าง'));
        $this->assertFalse($query->matchesText('ภาษีอากร'));

        // | = OR: either matches
        $query = LawSearchQuery::parse('ภาษี | ค่าเดินทาง');
        $this->assertTrue($query->matchesText('ค่าเดินทางราชการ'));

        // ~ = NOT: exclude
        $query = LawSearchQuery::parse('ภาษี ~ อากร');
        $this->assertTrue($query->matchesText('ภาษีที่ดิน'));
        $this->assertFalse($query->matchesText('ภาษีอากร'));
    }
}
```

**Verify (expect FAIL):**
```bash
docker compose exec laravel-app php artisan test --filter=LawSearchQueryTest
```
Expected: 3 of 5 tests fail (`test_ampersand_is_and_operator`, `test_pipe_is_or_operator`, `test_tilde_standalone_is_not_operator`). The other 2 should pass (existing behavior).

### Task 2: แก้ไข `parseTerms()` (GREEN)

**ไฟล์:** `apps/app-laravel/app/Services/Search/LawSearchQuery.php`

**แก้ไข 1** — เพิ่ม `&` เข้า AND check (L168):
```php
// BEFORE:
if (! $quoted && in_array($upper, ['AND', 'และ'], true)) {

// AFTER:
if (! $quoted && in_array($upper, ['AND', 'และ', '&'], true)) {
```

**แก้ไข 2** — เพิ่ม `|` เข้า OR check (L173):
```php
// BEFORE:
if (! $quoted && in_array($upper, ['OR', 'หรือ'], true)) {

// AFTER:
if (! $quoted && in_array($upper, ['OR', 'หรือ', '|'], true)) {
```

**แก้ไข 3** — เพิ่ม `~` เข้า NOT check (L178):
```php
// BEFORE:
if (! $quoted && in_array($upper, ['NOT', 'ไม่'], true)) {

// AFTER:
if (! $quoted && in_array($upper, ['NOT', 'ไม่', '~'], true)) {
```

**Verify (expect PASS):**
```bash
docker compose exec laravel-app php artisan test --filter=LawSearchQueryTest
```
Expected: 5 tests pass.

### Task 3: Regression — run existing tests

```bash
docker compose exec laravel-app php artisan test --filter=LawSearchServiceTest
docker compose exec laravel-app php artisan test --filter=LawSearchTest
docker compose exec laravel-app php artisan test --filter=LawSearchFuzzyTest
```

Expected: all existing tests still pass.

### Task 4: Commit

```bash
git add -A
git commit -m "feat(search): support & | ~ as boolean operator aliases"
```

## Risks & Tradeoffs

- **`&` / `|` ในชื่อเอกสาร:** หากมีเอกสารที่มี `&` หรือ `|` ในชื่อ (เช่น "AT&T") การค้นหาจะถือเป็น operator แทนที่จะเป็นส่วนหนึ่งของชื่อ → ใช้ `"AT&T"` (quoted) เพื่อ escape ได้ ซึ่งเป็น pattern เดียวกับ AND/OR/NOT อยู่แล้ว ในบริบทกฎหมายไทยไม่น่ามีกรณีนี้
- **Elasticsearch path:** symbol operators ถูก convert เป็น expanded DSL เหมือน AND/OR/NOT — ใช้ code path เดียวกันทุกประการ (`buildExpandedMust`) ไม่ต้องแก้ ES query builder
- **File-based path:** `matchesText()` → `groupMatchesText()` ใช้ `orGroups()` + negation logic ที่มีอยู่ — ไม่ต้องแก้
- **No breaking change:** ผู้ใช้ที่ไม่ใช้ symbol operators ไม่ได้รับผลกระทบ
