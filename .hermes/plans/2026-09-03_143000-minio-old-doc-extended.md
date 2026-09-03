# MinIO Old-Doc: Extended Plan (dev/prod mode + all file types + cleanup)

## Goal

Extend the MinIO old-doc feature with: (1) dev/production mode switch so MinIO upload only runs in production, (2) upload ALL file types (pdf/doc/docx) for both old and new docs, (3) delete local file after successful MinIO upload, (4) backfill command for existing old docs.

## Current Context

### What's already done (commit `7ee5263` on `feature/minio-old-doc`):
- `UploadController` uploads old-doc PDFs to MinIO (best-effort, try/catch)
- `DocumentFileController` serves via MinIO presigned URL redirect (fallback to local)
- `.env.example` has BUU Kong dev credentials
- 5 new tests passing

### What's NOT done yet:
- MinIO is always attempted — no dev/prod toggle
- Only old-doc PDF path uploads to MinIO; new-doc (doc/docx/pdf) doesn't
- Local files are never deleted after MinIO upload
- `DocumentFileController` only serves PDFs (guard: `extension !== 'pdf'` → 404)
- No backfill command for docs uploaded before this feature
- Old-doc validation only accepts PDF (`mimes:pdf`); doc/docx are rejected

### Key constraint — new docs need local files during extraction
`ExtractDocumentJob` reads the uploaded file from local disk (`ReviewStore::absolutePath(relativeFilePath)`). MinIO upload for new docs MUST happen AFTER extraction completes (in `ExtractDocumentJob::runFast` after status=done, or in `PipelineCallbackController` for standard path).

## Architecture / Proposed Approach

Add env var `BUU_MINIO_ENABLED=true|false` (default `false`). When disabled, all MinIO calls are skipped — upload goes to local disk only, serve goes from local disk only. In production set `BUU_MINIO_ENABLED=true`.

For new docs, hook MinIO upload at the two completion points: (1) end of `ExtractDocumentJob::runFast()` after status=done, (2) `PipelineCallbackController::receive()` after writing review doc. Both are best-effort try/catch like the old-doc path.

Expand `DocumentFileController` to serve any file type (pdf/doc/docx) — for doc/docx from MinIO, return redirect; from local, stream with appropriate Content-Type.

## Step-by-step Tasks

### Task 1: Add `BUU_MINIO_ENABLED` config

**File**: `apps/app-laravel/config/buu.php`

Add at line 14 (after `'domain'`):

```php
'minio_enabled' => (bool) env('BUU_MINIO_ENABLED', false),
```

**File**: `apps/app-laravel/.env.example`

Add after `BUU_MINIO_BUCKET=law-space`:

```
# Set to true in production to upload files to MinIO; false keeps local-only (dev)
BUU_MINIO_ENABLED=false
```

**Verify**: `docker compose exec laravel-app php artisan tinker --execute="echo config('buu.minio_enabled') ? 'true' : 'false';"` → `false`

---

### Task 2: Create `MinioUploadService` — centralized upload-if-enabled helper

**File**: `apps/app-laravel/app/Services/Buu/MinioUploadService.php`

This thin wrapper checks `buu.minio_enabled` before calling `BuuMinioService::putFile()`. Returns `?string` (minio filename or null). All callers use this instead of calling `BuuMinioService` directly.

```php
<?php

namespace App\Services\Buu;

use Illuminate\Support\Facades\Log;

class MinioUploadService
{
    public function __construct(private readonly BuuMinioService $minio) {}

    /**
     * Upload file to MinIO if enabled. Returns stored filename or null.
     * Never throws — all errors are caught and logged.
     */
    public function uploadIfEnabled(
        string $absolutePath,
        string $originalExtension,
        string $documentId,
        string $folderPath = '/',
    ): ?string {
        if (! config('buu.minio_enabled')) {
            return null;
        }

        try {
            return $this->minio->putFile(
                absolutePath: $absolutePath,
                originalExtension: $originalExtension,
                folderPath: $folderPath,
            );
        } catch (\Throwable $e) {
            Log::warning("MinIO upload failed for {$documentId}, keeping local file", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function isEnabled(): bool
    {
        return (bool) config('buu.minio_enabled');
    }
}
```

---

### Task 3: Write test for `MinioUploadService`

**File**: `apps/app-laravel/tests/Unit/MinioUploadServiceTest.php`

```php
<?php

namespace Tests\Unit;

use App\Services\Buu\BuuApiException;
use App\Services\Buu\BuuMinioService;
use App\Services\Buu\MinioUploadService;
use Mockery;
use Tests\TestCase;

class MinioUploadServiceTest extends TestCase
{
    public function test_returns_null_when_disabled(): void
    {
        config(['buu.minio_enabled' => false]);
        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldNotReceive('putFile');
        $svc = new MinioUploadService($mock);

        $this->assertNull($svc->uploadIfEnabled('/tmp/f.pdf', 'pdf', 'doc_1'));
    }

    public function test_uploads_when_enabled(): void
    {
        config(['buu.minio_enabled' => true]);
        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('putFile')->once()->andReturn('stored.pdf');
        $svc = new MinioUploadService($mock);

        $this->assertSame('stored.pdf', $svc->uploadIfEnabled('/tmp/f.pdf', 'pdf', 'doc_1'));
    }

    public function test_returns_null_on_error(): void
    {
        config(['buu.minio_enabled' => true]);
        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('putFile')->once()->andThrow(new BuuApiException('down'));
        $svc = new MinioUploadService($mock);

        $this->assertNull($svc->uploadIfEnabled('/tmp/f.pdf', 'pdf', 'doc_1'));
    }
}
```

**Run**: `docker compose exec laravel-app php artisan test --filter=MinioUploadServiceTest`

---

### Task 4: Refactor `UploadController` to use `MinioUploadService`

**File**: `apps/app-laravel/app/Http/Controllers/Api/UploadController.php`

Replace `BuuMinioService` injection with `MinioUploadService`. The old-doc branch becomes:

```php
// Best-effort upload to MinIO (only in production)
$minioFilename = $this->minioUpload->uploadIfEnabled(
    absolutePath: $storedFile['absolute_path'],
    originalExtension: $extension,
    documentId: $documentId,
    folderPath: '/old-documents',
);
if ($minioFilename !== null) {
    $statusData['minio_filename'] = $minioFilename;
}
```

**Update existing tests**: `OldDocumentMinioUploadTest` — set `config(['buu.minio_enabled' => true])` in the minio-success test, `false` in the failure test should not call MinIO at all.

---

### Task 5: Hook MinIO upload for new docs — fast path

**File**: `apps/app-laravel/app/Jobs/ExtractDocumentJob.php`

In `runFast()`, after `$store->setStatus(... 'status' => 'done' ...)` and before `NormalizeDocumentJob::dispatch()`:

```php
// Upload source file to MinIO now that extraction is done
$minioUpload = app(MinioUploadService::class);
$ext = strtolower(pathinfo($this->relativeFilePath, PATHINFO_EXTENSION));
$minioFilename = $minioUpload->uploadIfEnabled(
    absolutePath: $store->absolutePath($this->relativeFilePath),
    originalExtension: $ext,
    documentId: $this->documentId,
    folderPath: '/documents',
);
if ($minioFilename !== null) {
    $store->setStatus($this->documentId, ['minio_filename' => $minioFilename]);
}
```

---

### Task 6: Hook MinIO upload for new docs — standard path (callback)

**File**: `apps/app-laravel/app/Http/Controllers/Api/PipelineCallbackController.php`

After the main success path writes review doc and sets status=done (line 83), add:

```php
// Upload source file to MinIO
$minioUpload = app(MinioUploadService::class);
$prevStatus = $reviewStore->getStatus($documentId);
$sourcePath = (string) ($prevStatus['source_path'] ?? '');
if ($sourcePath !== '') {
    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $minioFilename = $minioUpload->uploadIfEnabled(
        absolutePath: $reviewStore->absolutePath($sourcePath),
        originalExtension: $ext,
        documentId: $documentId,
        folderPath: '/documents',
    );
    if ($minioFilename !== null) {
        $reviewStore->setStatus($documentId, ['minio_filename' => $minioFilename]);
    }
}
```

---

### Task 7: Expand `DocumentFileController` to serve doc/docx (not just PDF)

**File**: `apps/app-laravel/app/Http/Controllers/Api/DocumentFileController.php`

Change the extension guard from:
```php
if ($relative === '' || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'pdf') {
    abort(404, 'Original file not available.');
}
```
To:
```php
$ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
if ($relative === '' || ! in_array($ext, ['pdf', 'doc', 'docx'], true)) {
    abort(404, 'Original file not available.');
}
```

And change the local-disk fallback Content-Type from hardcoded `application/pdf` to:
```php
$mimeMap = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$contentType = $mimeMap[$ext] ?? 'application/octet-stream';
```

---

### Task 8: Delete local file after successful MinIO upload

**File**: `apps/app-laravel/app/Services/Buu/MinioUploadService.php`

Add a method `uploadAndCleanup()` that calls `uploadIfEnabled()` and then deletes the local file if successful:

```php
/**
 * Upload to MinIO and delete local file on success.
 * Returns stored filename or null.
 */
public function uploadAndCleanup(
    string $absolutePath,
    string $originalExtension,
    string $documentId,
    string $folderPath = '/',
): ?string {
    $filename = $this->uploadIfEnabled($absolutePath, $originalExtension, $documentId, $folderPath);

    if ($filename !== null && is_file($absolutePath)) {
        unlink($absolutePath);
    }

    return $filename;
}
```

Then change callers to use `uploadAndCleanup()` instead of `uploadIfEnabled()` where appropriate:
- `UploadController` (old-doc): use `uploadAndCleanup()` — old docs don't need local file after upload
- `ExtractDocumentJob::runFast()`: use `uploadAndCleanup()` — extraction is done
- `PipelineCallbackController`: use `uploadAndCleanup()` — extraction is done

---

### Task 9: Backfill command for existing old docs

**File**: `apps/app-laravel/app/Console/Commands/MigrateDocsToMinioCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Services\Buu\MinioUploadService;
use App\Services\ReviewStore;
use Illuminate\Console\Command;

class MigrateDocsToMinioCommand extends Command
{
    protected $signature = 'minio:migrate {--dry-run : Show what would be uploaded without doing it}';
    protected $description = 'Upload existing local documents to MinIO that don\'t have minio_filename yet';

    public function handle(ReviewStore $store, MinioUploadService $minio): int
    {
        if (! $minio->isEnabled()) {
            $this->error('BUU_MINIO_ENABLED is false. Set it to true first.');
            return 1;
        }

        $docs = $store->listDocuments();
        $candidates = [];

        foreach ($docs as $doc) {
            $id = $doc['document_id'] ?? '';
            $status = $store->getStatus($id);
            if ($status === null) continue;
            if (($status['minio_filename'] ?? '') !== '') continue;

            $sourcePath = (string) ($status['source_path'] ?? '');
            if ($sourcePath === '') continue;

            $absPath = $store->absolutePath($sourcePath);
            if (! is_file($absPath)) continue;

            $candidates[] = ['id' => $id, 'path' => $absPath, 'source_path' => $sourcePath];
        }

        $this->info(count($candidates) . " documents to migrate.");

        if ($this->option('dry-run')) {
            foreach ($candidates as $c) {
                $this->line("  {$c['id']} → {$c['source_path']}");
            }
            return 0;
        }

        $bar = $this->output->createProgressBar(count($candidates));
        $ok = 0;
        $fail = 0;

        foreach ($candidates as $c) {
            $ext = strtolower(pathinfo($c['source_path'], PATHINFO_EXTENSION));
            $filename = $minio->uploadIfEnabled($c['path'], $ext, $c['id'], '/migrated');

            if ($filename !== null) {
                $store->setStatus($c['id'], ['minio_filename' => $filename]);
                $ok++;
            } else {
                $fail++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Uploaded: {$ok}, Failed: {$fail}");

        return $fail > 0 ? 1 : 0;
    }
}
```

**Run**: `docker compose exec laravel-app php artisan minio:migrate --dry-run`

---

### Task 10: Update tests for dev/prod mode

**File**: `apps/app-laravel/tests/Feature/OldDocumentMinioUploadTest.php`

- Test 1 (minio success): add `config(['buu.minio_enabled' => true])` at top
- Test 2 (minio fails): add `config(['buu.minio_enabled' => true])` at top
- **New test 3**: `test_old_document_upload_skips_minio_when_disabled` — set `config(['buu.minio_enabled' => false])`, assert `minio_filename` is NOT in status

**File**: `apps/app-laravel/tests/Feature/DocumentFileMinioTest.php`

- Add `config(['buu.minio_enabled' => true])` where MinIO is expected to be called

---

### Task 11: Expand old-doc to accept doc/docx

**File**: `apps/app-laravel/app/Http/Requests/StoreDocumentRequest.php`

Change line 24 from:
```php
$isOld ? 'mimes:pdf' : 'mimes:pdf,doc,docx',
```
To:
```php
'mimes:pdf,doc,docx',
```

Old docs can now be pdf, doc, or docx.

---

## Test matrix

| Scenario | `BUU_MINIO_ENABLED` | Expected behavior |
|---|---|---|
| Old doc upload (dev) | `false` | Local disk only, no `minio_filename` in status |
| Old doc upload (prod) | `true` | Upload to MinIO, `minio_filename` in status, local file deleted |
| Old doc upload (prod, MinIO down) | `true` | Local disk fallback, no `minio_filename`, local file kept |
| New doc upload fast path (dev) | `false` | Local disk, extract, no MinIO |
| New doc upload fast path (prod) | `true` | Local disk, extract, then MinIO upload + local delete |
| Serve file with minio_filename (prod) | `true` | 302 redirect to presigned URL |
| Serve file without minio_filename (dev) | `false` | Stream from local disk |
| Serve file when MinIO presigned fails | `true` | Fallback to local disk stream |

## Risks, Tradeoffs, and Open Questions

### Risks
1. **Race condition on file delete**: If `NormalizeDocumentJob` reads the file after `ExtractDocumentJob` deletes it → NOT an issue: `NormalizeDocumentJob` reads review JSON from Mongo, not the source file.
2. **Standard path callback timing**: `PipelineCallbackController` runs after Python finishes. The source file should still exist on local disk at this point (Python reads from shared volume, not the upload path).

### Tradeoffs
- **`uploadAndCleanup` deletes immediately**: No grace period. If something unexpected needs the file, it's gone. Mitigation: only delete when MinIO upload confirmed (returned filename is non-empty).
- **No retry on MinIO upload**: Single attempt, then give up. Acceptable — the file stays local as fallback.

### Open Questions
1. **MinIO folder structure**: Old docs → `/old-documents`, new docs → `/documents`, backfill → `/migrated`. Confirm this is OK or should they all go to one folder?
2. **Should backfill also delete local files?** Current plan: no (safer). Can add `--delete-local` flag later.
3. **Content-Disposition for doc/docx**: Should the controller force download (`attachment`) or try inline? Current: `inline` for PDF, should be `attachment` for doc/docx since browsers can't render them inline.
