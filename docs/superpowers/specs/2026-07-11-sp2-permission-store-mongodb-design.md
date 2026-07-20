# SP2 — PermissionStore MongoDB Migration Design

**Status:** Approved for planning
**Date:** 2026-07-11

## Context

SP1 migrated document status and review JSON from files to MongoDB. SP2 applies the same pattern to the permissions subsystem. Currently `App\Services\Permissions\PermissionStore` stores two files under `storage/app/poc/permissions/`:

- `groups.json` — array of permission group objects (CRUD).
- `directory.json` — org chart (units, positions, users); seeded from hardcoded defaults on first access.

Both files are read and written with no locking. Moving to MongoDB provides the same atomicity and durability guarantees established in SP1.

## Goals

- All permission group CRUD (`createGroup`, `updateGroup`, `deleteGroup`, `listGroups`, `findGroup`, `validateGroupIds`) persists to and reads from MongoDB.
- The org directory (`directory()`) is seeded to MongoDB on first access, replacing the current file-seed path.
- `PermissionStore`'s public API is **unchanged** — no controller or frontend changes needed.
- Fresh start: existing `permissions/*.json` files are not migrated.

## Non-goals

- No change to the org directory content (units, positions, users remain hardcoded seed data).
- No UI for editing the org directory.
- No change to SP1 (ReviewStore / MongoBlobStore).
- No GridFS or file storage changes.

## Architecture

### Storage layout

Reuse `MongoBlobStore` (from SP1) with a dedicated `permissions` MongoDB collection. Two blob slots:

| Slot | `kind` | `id` | Content |
|---|---|---|---|
| Permission groups | `'data'` | `'groups'` | Numeric array of group objects |
| Org directory | `'data'` | `'directory'` | `{units, positions, users}` object |

Each slot maps to one Mongo document in the `permissions` collection:

```
{ _id: "groups",    data: [...],    _version: N, updated_at: ISODate }
{ _id: "directory", data: {...},    _version: N, updated_at: ISODate }
```

### PermissionStore changes

Constructor changes:
- Add `MongoBlobStore $blob` as the first parameter.
- Remove `?string $basePath` entirely — no disk paths remain.

Private helper changes:
- `readGroups()` → `$this->blob->read('data', 'groups') ?? []`
- `writeGroups(array $groups)` → replaced by `withLock` in mutating methods (see below)
- Remove: `readJson`, `writeJson`, `ensureDirectories`, `groupsPath`, `directoryPath`

Public method changes:

| Method | Before | After |
|---|---|---|
| `directory()` | `is_file` + `readJson` + `writeJson` | `blob->read` + `blob->write` on seed |
| `listGroups()` | `readGroups()` via file | `readGroups()` via Mongo |
| `findGroup($id)` | iterates `readGroups()` | unchanged logic, Mongo-backed |
| `createGroup($payload)` | `readGroups` → add → `writeGroups` | `blob->withLock('data','groups', cb)` |
| `updateGroup($id, $payload)` | same pattern | `blob->withLock('data','groups', cb)` |
| `deleteGroup($id)` | same pattern | `blob->withLock('data','groups', cb)` |
| `validateGroupIds($ids)` | `readGroups()` | unchanged logic, Mongo-backed |

Using `withLock` for mutating operations prevents the race condition where two concurrent `createGroup` calls both pass the unique-name check.

### DI wiring

`ReviewStore` and `PermissionStore` both need `MongoBlobStore` but targeting different collections (`documents` vs `permissions`). The current singleton must be split.

Changes to `AppServiceProvider::register()`:

1. Extract `MongoDB\Client` construction into its own singleton so the connection is shared.
2. Keep `MongoBlobStore::class` singleton pointing at the `documents` collection (no change for ReviewStore callers).
3. Add a **contextual binding**: when `PermissionStore` needs `MongoBlobStore`, give it one pointing at `permissions`.
4. Add a **named binding** `'mongo.blob.permissions'` so `TestCase::setUp` can truncate the permissions collection.

```php
// 1. Shared client
$this->app->singleton(\MongoDB\Client::class, function (): \MongoDB\Client {
    // DSN + typeMap construction (extracted from current MongoBlobStore singleton)
});

// 2. Documents blob (unchanged consumers: ReviewStore, MongoBlobStoreTest)
$this->app->singleton(MongoBlobStore::class, function (): MongoBlobStore {
    $client = $this->app->make(\MongoDB\Client::class);
    $db = config('database.connections.mongodb.database', 'poc');
    return new MongoBlobStore($client->$db->selectCollection('documents'));
});

// 3. Permissions blob — contextual for PermissionStore
$this->app->when(PermissionStore::class)
    ->needs(MongoBlobStore::class)
    ->give(fn () => $this->app->make('mongo.blob.permissions'));

// 4. Named binding for TestCase truncation
$this->app->bind('mongo.blob.permissions', function (): MongoBlobStore {
    $client = $this->app->make(\MongoDB\Client::class);
    $db = config('database.connections.mongodb.database', 'poc');
    return new MongoBlobStore($client->$db->selectCollection('permissions'));
});
```

### TestCase

`TestCase::setUp` gains a second truncate call:

```php
app(MongoBlobStore::class)->truncate();               // documents collection
app('mongo.blob.permissions')->truncate();            // permissions collection
```

Both wrapped in the existing try/catch.

## Data flow

1. **`directory()`** — reads `blob->read('data', 'directory')`. If null (first boot), builds default array and writes it via `blob->write('data', 'directory', $defaults)`. Returns the directory array.
2. **`listGroups()`** — reads `blob->read('data', 'groups') ?? []`, sorts, hydrates.
3. **`createGroup($payload)`** — `blob->withLock('data', 'groups', function(&$groups) { ... append new group ... })`.
4. **`updateGroup($id, $payload)`** — `blob->withLock('data', 'groups', function(&$groups) { ... find by id, replace ... })`.
5. **`deleteGroup($id)`** — `blob->withLock('data', 'groups', function(&$groups) { ... splice ... })`.

## Error handling

- Mongo unreachable → driver throws; `PermissionStore` surfaces it as a 500 (same as today with file IO errors).
- Concurrent group creation → `withLock` retries up to 3 times; both unique-name and ID collision checks happen inside the lock so the winner commits and the loser retries cleanly.
- Missing group → same `null` returns as today from `findGroup` / `updateGroup`.

## Testing

New file: `tests/Feature/PermissionStoreMongoTest.php`

Covers:
- `directory()` returns seeded data with all three keys (`units`, `positions`, `users`).
- `createGroup` then `findGroup` round-trips (name, unit_ids, etc.).
- Duplicate name throws `ValidationException`.
- `updateGroup` persists across `listGroups`.
- `deleteGroup` removes from `listGroups`.
- `validateGroupIds` accepts valid IDs and rejects unknown ones.

Existing tests (`PermissionGroupTest`, if present) verified or updated to work against the Mongo-backed store.

## Acceptance criteria

- `listGroups`, `createGroup`, `updateGroup`, `deleteGroup`, `findGroup`, `validateGroupIds`, `directory` all read/write MongoDB.
- No new files appear under `storage/app/poc/permissions/` after group operations.
- Full PHP test suite passes.
- `PermissionStore` constructor signature change is the only change callers observe (Laravel container resolves it automatically).
