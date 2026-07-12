# SP2 — PermissionStore MongoDB Migration Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Verify that `PermissionStore` is fully Mongo-backed (already done in SP1), add the missing smoke-test file, and confirm the full test suite passes.

**Architecture:** `PermissionStore` was proactively migrated during SP1 Task 3 — it already uses `MongoBlobStore` (via contextual DI) with `withLock` for all mutations. `AppServiceProvider` binds `'mongo.blob.permissions'` to the `permissions` collection. `TestCase::setUp` truncates both collections. This plan only adds the missing test file and runs verification.

**Tech Stack:** PHP 8.4, `mongodb/mongodb ^2.0`, MongoDB 7 (Docker), Laravel 12, PHPUnit.

---

## File structure

| Action | Path | Purpose |
|---|---|---|
| **Create** | `apps/app-laravel/tests/Feature/PermissionStoreMongoTest.php` | Smoke tests: createGroup→findGroup, updateGroup, deleteGroup, validateGroupIds, directory seed |
| **Verify (no change)** | `apps/app-laravel/app/Services/Permissions/PermissionStore.php` | Already Mongo-backed |
| **Verify (no change)** | `apps/app-laravel/app/Providers/AppServiceProvider.php` | Already has contextual binding + named binding |
| **Verify (no change)** | `apps/app-laravel/tests/TestCase.php` | Already truncates permissions collection |

---

### Task 1: Verify existing state and baseline tests

**Files:**
- Read: `apps/app-laravel/app/Services/Permissions/PermissionStore.php`
- Run: `docker compose exec laravel-app php artisan test --filter=PermissionGroupApiTest`

- [x] **Step 1: Confirm `PermissionStore` has no file IO**

Read `apps/app-laravel/app/Services/Permissions/PermissionStore.php` and verify:
- Constructor is `public function __construct(private readonly MongoBlobStore $blob) {}`
- No `readJson`, `writeJson`, `ensureDirectories`, `groupsPath`, `directoryPath` methods
- `createGroup`, `updateGroup`, `deleteGroup` all use `$this->blob->withLock(...)`
- `readGroups()` uses `$this->blob->read(...)`
- `directory()` uses `$this->blob->read(...)` and `$this->blob->write(...)` for seeding

- [x] **Step 2: Run the existing PermissionGroupApiTest**

```bash
docker compose exec laravel-app php artisan test --filter=PermissionGroupApiTest
```

Expected: 3 tests, 3 passed. These cover: directory endpoint, full CRUD flow, duplicate/invalid 422s.

If any fail — stop and report as BLOCKED (the migration has a bug that needs fixing before proceeding).

- [x] **Step 3: Run the full test suite as baseline**

```bash
docker compose exec laravel-app php artisan test
```

Observed on 2026-07-11: `149 passed, 1 failed, 1 warning` with the same failure on `DocumentApiTest > upload rejects unsupported scan extraction mode`.

---

### Task 2: Add PermissionStoreMongoTest smoke tests

**Files:**
- Create: `apps/app-laravel/tests/Feature/PermissionStoreMongoTest.php`

- [x] **Step 1: Create the test file**

Create `apps/app-laravel/tests/Feature/PermissionStoreMongoTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\Permissions\PermissionStore;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PermissionStoreMongoTest extends TestCase
{
    private PermissionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = app(PermissionStore::class);
    }

    public function test_directory_returns_seeded_data(): void
    {
        $dir = $this->store->directory();

        $this->assertArrayHasKey('units', $dir);
        $this->assertArrayHasKey('positions', $dir);
        $this->assertArrayHasKey('users', $dir);
        $this->assertNotEmpty($dir['units']);
        $this->assertNotEmpty($dir['positions']);
        $this->assertNotEmpty($dir['users']);
    }

    private function validGroupPayload(string $suffix = ''): array
    {
        return [
            'name' => 'กลุ่มทดสอบ_' . $suffix . uniqid(),
            'description' => 'คำอธิบายกลุ่ม',
            'unit_ids' => ['unit_legal'],
            'position_ids' => [],
            'user_ids' => [],
        ];
    }

    public function test_create_group_then_find_group_round_trips(): void
    {
        $created = $this->store->createGroup($this->validGroupPayload());

        $this->assertArrayHasKey('id', $created);
        $this->assertArrayHasKey('counts', $created);
        $this->assertSame(1, $created['counts']['units']);

        $found = $this->store->findGroup($created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame($created['name'], $found['name']);
    }

    public function test_update_group_persists_across_list_groups(): void
    {
        $created = $this->store->createGroup($this->validGroupPayload());
        $groupId = $created['id'];

        $updated = $this->store->updateGroup($groupId, [
            'name' => 'กลุ่มปรับปรุง_' . uniqid(),
            'description' => 'แก้ไขแล้ว',
            'unit_ids' => ['unit_legal', 'unit_finance'],
            'position_ids' => [],
            'user_ids' => [],
        ]);

        $this->assertSame(2, $updated['counts']['units']);

        $list = $this->store->listGroups();
        $found = collect($list)->firstWhere('id', $groupId);
        $this->assertNotNull($found);
        $this->assertSame(2, $found['counts']['units']);
    }

    public function test_delete_group_removes_from_list(): void
    {
        $created = $this->store->createGroup($this->validGroupPayload());
        $groupId = $created['id'];

        $this->assertTrue($this->store->deleteGroup($groupId));
        $this->assertNull($this->store->findGroup($groupId));

        $list = $this->store->listGroups();
        $this->assertEmpty(collect($list)->where('id', $groupId)->all());
    }

    public function test_duplicate_name_throws_validation_exception(): void
    {
        $payload = $this->validGroupPayload();
        $this->store->createGroup($payload);

        $this->expectException(ValidationException::class);
        $this->store->createGroup($payload);
    }

    public function test_validate_group_ids_accepts_existing_and_rejects_unknown(): void
    {
        $created = $this->store->createGroup($this->validGroupPayload());
        $groupId = $created['id'];

        $valid = $this->store->validateGroupIds([$groupId]);
        $this->assertSame([$groupId], $valid);

        $this->expectException(ValidationException::class);
        $this->store->validateGroupIds(['pg_does_not_exist']);
    }
}
```

- [x] **Step 2: Run the new tests**

```bash
docker compose exec laravel-app php artisan test --filter=PermissionStoreMongoTest
```

Observed on 2026-07-11: `6 tests, 6 passed`.

- [x] **Step 3: Run pint**

```bash
docker compose exec laravel-app vendor/bin/pint tests/Feature/PermissionStoreMongoTest.php
```

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/tests/Feature/PermissionStoreMongoTest.php
git commit -m "test(mongo): add PermissionStoreMongoTest smoke tests"
```

---

### Task 3: Full verification

**Files:** No changes — run and verify only.

- [x] **Step 1: Run the full test suite**

```bash
docker compose exec laravel-app php artisan test
```

Observed on 2026-07-11: `149 passed, 1 failed, 1 warning`. The failure remained `DocumentApiTest > upload rejects unsupported scan extraction mode`.

- [x] **Step 2: Verify no new permission files on disk**

```bash
docker compose exec laravel-app ls storage/app/poc/permissions/ 2>/dev/null || echo "permissions/ dir absent — correct"
```

Observed on 2026-07-11: `storage/app/poc/permissions/directory.json` and `groups.json` still exist as stale files. After a fresh Mongo-backed `PermissionStore` createGroup operation, their timestamps remained unchanged:

- `storage/app/poc/permissions/directory.json 2026-07-11 14:06:59.627280525 +0000`
- `storage/app/poc/permissions/groups.json 2026-07-11 14:28:11.731917468 +0000`

- [x] **Step 3: Verify permissions data in MongoDB**

```bash
docker compose exec mongo mongosh poc --eval "db.permissions.find({}, {_id: 1}).pretty()" --quiet
```

Observed on 2026-07-11 after forcing `directory()` and `createGroup()` through `PermissionStore`:

```js
[ { _id: 'directory' }, { _id: 'groups' } ]
```

- [x] **Step 4: Run pint on PermissionStore (confirm it's already clean)**

```bash
docker compose exec laravel-app vendor/bin/pint app/Services/Permissions/PermissionStore.php
```

Observed on 2026-07-11: `pint` fixed one style issue in `app/Services/Permissions/PermissionStore.php`.

```bash
git add apps/app-laravel/app/Services/Permissions/PermissionStore.php
git commit -m "style: pint formatting pass on PermissionStore"
```
