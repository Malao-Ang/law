# MongoDB Document Persistence — Design (Sub-project 1)

**Status:** Approved for planning
**Date:** 2026-07-11

## Context

Today all document lifecycle state is file-based JSON under `storage/app/poc`,
managed by `App\Services\ReviewStore`. There is no database (`DB_CONNECTION=sqlite`,
unused for documents). Search already runs on Elasticsearch (separate, real).

This sub-project swaps the document persistence backend from files to MongoDB
**without changing `ReviewStore`'s public API or any of its 17 consumers**.

This is the foundation for two later sub-projects (own specs):
- **SP2 — Permissions on Mongo** (`PermissionStore` → Mongo, seed directory).
- **SP3 — Real dashboard + admin list/reports** (replace `dashboardData.ts` mock
  + `ReportController` with Mongo aggregations).

## Goals

- New uploads persist document status + review JSON to MongoDB.
- Every existing page (review, RAG, law, admin list) reads from MongoDB,
  transparently, via the unchanged `ReviewStore` public methods.
- Binaries (uploaded source files, extracted images) stay on the shared
  `poc_storage` volume so the Python OCR service is untouched.
- Fresh start: no migration of existing `storage/app/poc` JSON.

## Non-goals

- No change to `PermissionStore` (SP2).
- No change to dashboard/reports data sources (SP3).
- No GridFS. Binaries remain on disk.
- No change to the Python service, the extraction pipeline, or Elasticsearch.
- No migration command for pre-existing file data.

## Architecture

### Principle: swap the backend, keep the API

`ReviewStore` keeps all ~30 public methods and all array-manipulation logic
(block patching, merge, split, reorder, normalization, summary recalculation)
byte-for-byte. Only its **storage seam** changes.

Today the seam is a set of private helpers that operate on filesystem paths:

| Helper | Role |
|---|---|
| `statusPath($id)` / `intermediatePath($id)` | build the on-disk path for a doc's status / review JSON |
| `readJson($path)` | read + decode a JSON file |
| `atomicWrite($path, $data)` | encode + write a JSON file |
| `withLockedFile($path, $cb)` | `LOCK_EX` read-modify-write |
| `is_file($path)` checks | existence tests in `getReviewDocument`, `listDocuments`, `listLawMeta` |
| `glob($dir/*.json)` | enumerate all status files in `listDocuments` / `listLawMeta` |

We replace the filesystem operations with a small Mongo-backed store,
`App\Services\Storage\MongoBlobStore`, addressed by `(kind, id)` where
`kind ∈ {status, review}`.

### MongoBlobStore

One class, one collection (`documents`). Each Mongo document is keyed by the
Laravel `document_id` (`_id`) and holds both slots as sub-fields:

```
{
  _id: "doc_20260711_120000_ab12cd",
  status: { document_id, status, source_file, updated_at, workflow_* , ... },
  review: { document_id, source_file, pages: [...], law_meta: {...}, document_review: {...}, summary: {...} },
  _version: 7,
  updated_at: ISODate
}
```

Interface:

```php
final class MongoBlobStore
{
    public function read(string $kind, string $id): ?array;         // returns the sub-field, or null
    public function write(string $kind, string $id, array $data): void; // upsert sub-field + bump _version
    public function exists(string $kind, string $id): bool;
    public function withLock(string $kind, string $id, callable $cb): void; // optimistic read-modify-write
    /** @return array<int, array<string,mixed>> the `status` sub-field of every doc */
    public function allStatuses(): array;
}
```

- `write` uses `updateOne(['_id'=>$id], ['$set'=>[$kind=>$data,...], '$inc'=>['_version'=>1]], upsert:true)`.
- `withLock` reads the sub-field + `_version`, runs `$cb` on a reference, then
  `updateOne` guarded by the read `_version`; on version mismatch (concurrent
  write) it retries the whole read-modify-write up to 3 times, then throws a
  `RuntimeException`. This replaces file `LOCK_EX` with equivalent
  last-writer-consistency semantics.
- `allStatuses` powers `listDocuments()` / `listLawMeta()` with one `find({}, {status:1})`
  instead of a directory glob + N file reads.

### ReviewStore changes

- Constructor takes `MongoBlobStore` (injected via the Laravel container).
  Keep the `$basePath` for **binary** helpers only (`storeUpload`,
  `absolutePath`, `absoluteImagesDir`, `absoluteUploadPath`) — those are unchanged.
- `statusPath`/`intermediatePath` are **removed** as path builders; call sites
  switch to `$this->blob->read('status'|'review', $id)` etc.
- `readJson`/`atomicWrite`/`withLockedFile` (path-based) are **replaced** by
  delegations to `MongoBlobStore` keyed by `(kind, id)`.
- `is_file($path)` existence checks → `$this->blob->exists($kind, $id)`.
- `listDocuments()` / `listLawMeta()` iterate `$this->blob->allStatuses()` and,
  where they need `law_meta`, `$this->blob->read('review', $id)` — same output
  shape as today.
- `getReviewDocument()` throws the same `RuntimeException('Review document not found.')`
  when `read('review',$id)` is null; its lazy `document_review` re-sync path is
  unchanged except the write goes through `blob->write('review', ...)`.

### Export / ingest artifacts

`writeExport()` / `writeIngest()` and their `*RelativePath()` getters stay
**file-based on the shared volume**. Rationale: these are regenerated output
artifacts (consumed by `ExportService` / `RagIngestService` / Elasticsearch),
not source-of-truth state, and keeping them on disk avoids widening SP1's blast
radius. They can move to Mongo later if a need appears (`ponytail:` deferred).

### Infrastructure

- **Dependency:** `mongodb/laravel-mongodb` (official) via Composer;
  `mongodb` PHP extension added to the Laravel Dockerfile (`pecl install mongodb`).
- **Container:** new `mongo` service in `docker-compose.yml` (image `mongo:7`),
  named volume `poc_mongo` for data, exposed on `27017`.
- **Config:** a `mongodb` connection in `config/database.php`; `.env.example`
  gains `MONGO_HOST=mongo`, `MONGO_PORT=27017`, `MONGO_DATABASE=poc`,
  `MONGO_USERNAME=`, `MONGO_PASSWORD=`. `queue-worker` and `laravel-app`
  reach Mongo over the compose network.

## Data flow (unchanged from caller's view)

1. **Upload** — `UploadController@store` → `ReviewStore::storeUpload` (binary → disk)
   + `setStatus` (→ Mongo `status`), dispatches `ExtractDocumentJob`.
2. **Extraction** — Fast path `writeReviewDocument` (→ Mongo `review`); Standard
   path Python writes its intermediate file, callback funnels it through
   `writeReviewDocument` (→ Mongo).
3. **Review/RAG/Law pages** — `getReviewDocument` / block-patch methods read &
   write Mongo `review`; `getStatus` reads Mongo `status`.
4. **Admin list** — `listDocuments` / `listLawMeta` `find()` over Mongo.
5. **Images** — `ImageController` still serves from the shared volume.

## Error handling

- Mongo unreachable → the driver throws; `ReviewStore` methods surface it as they
  do file errors today (500 to the API). No silent data loss: `withLock` only
  commits after `$cb` succeeds.
- Concurrent block edits → optimistic `_version` retry in `withLock`
  (bounded retries, then throw). Matches today's `LOCK_EX` intent.
- Missing document → same `RuntimeException`/null returns as today.

## Testing

- **Regression net (must stay green):** `FastExtractionTest`,
  `NormalizeDocumentJobTest` — they exercise `ReviewStore` end-to-end and now
  prove the Mongo backend. Point the test suite at a `poc_test` Mongo database.
- **New:** `MongoBlobStoreTest` — round-trip `write`/`read`/`exists`,
  `withLock` mutate-and-persist, `withLock` version-conflict retry, `allStatuses`
  returns every doc's status.
- **New:** `ReviewStoreMongoTest` — `writeReviewDocument` then
  `getReviewDocument` returns the same doc; `listDocuments` reflects a written
  status; `patchApprovedBlock` persists across a fresh `getReviewDocument`.

## Acceptance criteria

- `docker-compose up` starts a `mongo` service; the Laravel image has the
  `mongodb` extension.
- Uploading a document creates exactly one Mongo document in `documents`;
  no new `.review.json` / `.status.json` files are written under `storage/app/poc`
  (binaries + export/ingest artifacts still are).
- Review, RAG, law, and admin-list pages function against Mongo with no frontend
  or controller changes.
- Full PHP test suite passes against a Mongo test database.
