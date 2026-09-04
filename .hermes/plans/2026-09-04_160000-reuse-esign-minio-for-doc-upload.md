# Plan: Reuse e-sign MinIO API สำหรับ upload เอกสารต้นฉบับ

## Goal

ใช้ BuuMinioService (Kong minio.PutFile) ที่ merge มาจาก feature/storage (e-sign PR#4) แทน MinioUploadService wrapper เดิมบน feature/minio-old-doc เพื่อ upload ไฟล์เอกสารต้นฉบับ (DOCX/DOC/PDF) ไปยัง MinIO หลัง extraction เสร็จ

## Current Context

### สิ่งที่มีอยู่แล้วบน main (มาจาก PR#4 e-sign)

1. **`BuuKongClient`** (`app/Services/Buu/BuuKongClient.php`) — HTTP client ที่จัดการ OAuth2 token, retry 401, JSON/multipart POST ผ่าน Kong Gateway
2. **`BuuMinioService`** (`app/Services/Buu/BuuMinioService.php`) — wrapper สำหรับ MinIO APIs:
   - `putFile()` — upload file ผ่าน `minio.PutFile` endpoint, return stored object name
   - `getPublicLinks()` — สร้าง presigned URL (view/download)
   - `listFiles()` — list objects ใน bucket
   - `deleteFile()` — ลบ object
3. **`BuuOAuthService`** (`app/Services/Buu/BuuOAuthService.php`) — password-grant OAuth2 token cache
4. **`config/buu.php`** — endpoint registry พร้อม provision_key สำหรับ `minio.put`, `minio.public`, `minio.list`, `minio.delete` + `BUU_MINIO_ENABLED` toggle + `BUU_MINIO_BUCKET`
5. **`DocumentFileController`** — อ่าน file จาก local ก่อน, fallback ไป MinIO `getPublicLinks()` เมื่อ `buu.minio_enabled=true`
6. **`EsignSubmitService`** — ใช้ `BuuEsignService::uploadAndSend()` ซึ่งเรียก `BuuMinioService::putFile()` → `sendDocumentSign()` ครบ flow แล้ว

### สิ่งที่มีอยู่บน feature/minio-old-doc (ยังไม่ merge)

1. **`MinioUploadService`** (`app/Services/Buu/MinioUploadService.php`) — thin wrapper เรียก `BuuMinioService::putFile()` + ตรวจ `minio_enabled` + catch error + optional cleanup local file
2. Integration ใน `ExtractDocumentJob`, `PipelineCallbackController`, `UploadController` (old-doc)
3. `MigrateDocsToMinioCommand` — backfill command
4. Tests: `MinioUploadServiceTest`, `DocumentFileMinioTest`, `OldDocumentMinioUploadTest`, etc.

### ข้อสรุป: ใช้ได้เลย

`BuuMinioService::putFile()` บน main ทำ MinIO upload ได้สมบูรณ์อยู่แล้ว — สิ่งที่ขาดคือ:
- **`MinioUploadService` wrapper** (env toggle + error handling + local cleanup) — ยังอยู่บน feature/minio-old-doc
- **Integration points** ที่เรียก wrapper ตอน extraction เสร็จ

## Architecture

เอา `MinioUploadService` จาก feature/minio-old-doc มา cherry-pick/merge เข้า main แล้วใช้เป็น safe wrapper เรียก `BuuMinioService::putFile()` — มันตรวจ `BUU_MINIO_ENABLED` แล้ว catch error ทั้งหมด (never throws) เพื่อให้ extraction flow ไม่พังถ้า MinIO down ฝั่ง e-sign ใช้ `BuuEsignService::uploadAndSend()` ตรงๆ ไม่ผ่าน wrapper นี้ (ต้อง throw ถ้า upload fail เพราะ e-sign ต้องมี file)

## Step-by-step Tasks

### Task 1: Cherry-pick MinioUploadService class

ไฟล์: `apps/app-laravel/app/Services/Buu/MinioUploadService.php`

สร้างไฟล์ใหม่ (copy จาก feature/minio-old-doc — content อยู่ข้างล่าง):

```php
<?php

namespace App\Services\Buu;

use Illuminate\Support\Facades\Log;

class MinioUploadService
{
    public function __construct(private readonly BuuMinioService $minio) {}

    /**
     * Upload file to MinIO if enabled. Returns stored filename or null.
     * Never throws; all errors are caught and logged.
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

    /**
     * Upload to MinIO and delete the local file on success.
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

    public function isEnabled(): bool
    {
        return (bool) config('buu.minio_enabled');
    }
}
```

**Verify:** `docker compose exec laravel-app php -l app/Services/Buu/MinioUploadService.php` → no syntax errors

### Task 2: Unit test for MinioUploadService

ไฟล์: `apps/app-laravel/tests/Unit/MinioUploadServiceTest.php`

Cherry-pick จาก feature/minio-old-doc — test 3 cases:
1. `minio_enabled=false` → returns null, ไม่เรียก putFile
2. `minio_enabled=true` → calls putFile, returns stored name
3. putFile throws → catches, returns null, logs warning

**Verify:** `docker compose exec laravel-app php artisan test --filter=MinioUploadServiceTest` → 3 passing

### Task 3: Add .env vars to .env.example

ไฟล์: `apps/app-laravel/.env.example`

เพิ่ม (ถ้ายังไม่มี):
```
BUU_MINIO_ENABLED=false
BUU_MINIO_BUCKET=buu-contract
BUU_PROVISION_MINIO_PUT=
BUU_PROVISION_MINIO_LIST=
BUU_PROVISION_MINIO_PUBLIC=
BUU_PROVISION_MINIO_DELETE=
```

**Verify:** grep ไฟล์ confirm ค่ามีครบ

### Task 4: Integrate into ExtractDocumentJob (fast path)

ไฟล์: `apps/app-laravel/app/Jobs/ExtractDocumentJob.php`

หลังจากที่ fast path เขียน review JSON เสร็จ + mark status `done`:
1. Inject `MinioUploadService` (resolve จาก container)
2. เรียก `uploadIfEnabled()` ด้วย source file path
3. ถ้า return filename → update status ด้วย `minio_filename`

ตำแหน่ง: หลัง `$this->reviewStore->setStatus(...)` ที่ mark done, ก่อน dispatch `NormalizeDocumentJob`

```php
// Upload source document to MinIO (non-fatal)
$minioUpload = app(MinioUploadService::class);
$sourcePath = $this->reviewStore->absolutePath($status['source_path'] ?? '');
if (is_file($sourcePath)) {
    $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);
    $minioFilename = $minioUpload->uploadIfEnabled(
        absolutePath: $sourcePath,
        originalExtension: $ext,
        documentId: $this->documentId,
        folderPath: '/' . $this->documentId,
    );
    if ($minioFilename !== null) {
        $this->reviewStore->setStatus($this->documentId, [
            'minio_source_filename' => $minioFilename,
        ]);
    }
}
```

**Verify:** `docker compose exec laravel-app php artisan test --filter=ExtractDocumentJobFastPathTest`

### Task 5: Integrate into PipelineCallbackController (standard path)

ไฟล์: `apps/app-laravel/app/Http/Controllers/Api/PipelineCallbackController.php`

หลัง Python callback บอก status=done → upload source file เหมือน Task 4

**Verify:** `docker compose exec laravel-app php artisan test --filter=PipelineCallback`

### Task 6: Integrate into UploadController for old-document uploads

ไฟล์: `apps/app-laravel/app/Http/Controllers/Api/UploadController.php`

ตอน store old document (upload ที่ไม่ผ่าน extraction) → เรียก `uploadIfEnabled()` ทันที

**Verify:** `docker compose exec laravel-app php artisan test --filter=OldDocument`

### Task 7: Update DocumentFileController to read minio_source_filename

ไฟล์: `apps/app-laravel/app/Http/Controllers/Api/DocumentFileController.php`

MinIO fallback (line 70-83) ปัจจุบันใช้ `source_path` เป็น key — ต้องเช็ค `minio_source_filename` ก่อน ถ้ามีให้ใช้เป็น filePath ตรงๆ (เป็น MinIO object name จริง) แทนที่จะส่ง relative path

```php
// 2. MinIO fallback
if (config('buu.minio_enabled')) {
    $minioKey = (string) ($status['minio_source_filename'] ?? $relative);
    try {
        $links = $this->minioService->getPublicLinks(
            ['file' => $minioKey],
            ['file' => basename((string) ($status['source_file'] ?? $relative))],
        );
        // ...existing redirect logic...
```

**Verify:** `docker compose exec laravel-app php artisan test --filter=DocumentFile`

### Task 8: Add MigrateDocsToMinioCommand (optional, backfill)

ไฟล์: `apps/app-laravel/app/Console/Commands/MigrateDocsToMinioCommand.php`

Cherry-pick จาก feature/minio-old-doc — scans all documents in storage, uploads missing ones to MinIO

**Verify:** `docker compose exec laravel-app php artisan minio:migrate --dry-run`

### Task 9: Integration test — full upload→MinIO flow

ไฟล์: `apps/app-laravel/tests/Feature/DocumentFileMinioTest.php`

Test: upload doc → extraction done → file served via MinIO presigned URL when local file missing + minio_enabled=true

**Verify:** `docker compose exec laravel-app php artisan test --filter=DocumentFileMinioTest`

### Task 10: Run full test suite

```bash
docker compose exec laravel-app php artisan test
```

Confirm no regressions.

## Risks & Tradeoffs

| Risk | Mitigation |
|------|-----------|
| Kong OAuth credentials ยังไม่ได้ตั้งค่า dev | ใช้ `BUU_MINIO_ENABLED=false` (default) — dev ไม่ต้อง config อะไร local file ทำงานปกติ |
| MinIO upload fail ทำ extraction พัง | `MinioUploadService` catch all errors, returns null, log warning — non-fatal by design |
| Cherry-pick จาก feature/minio-old-doc อาจ conflict | diff เทียบแล้ว MinioUploadService.php ไม่มี conflict (file ใหม่), integration points ต้อง manual merge |
| `minio_source_filename` vs `source_path` — 2 key ใน status | ใช้ `minio_source_filename` เป็น MinIO object name แยกจาก `source_path` (local relative) เพื่อไม่ break existing logic |

## Open Questions

1. **ลบ local file หลัง MinIO upload สำเร็จไหม?** — feature/minio-old-doc มี `uploadAndCleanup()` แต่ production อาจอยากเก็บ local ไว้ backup ช่วงแรก → แนะนำ: ใช้ `uploadIfEnabled()` (เก็บ local) ก่อน, ค่อย switch เป็น `uploadAndCleanup()` เมื่อมั่นใจ
2. **Merge ทั้ง branch feature/minio-old-doc หรือ cherry-pick เฉพาะ?** — branch นั้นมี 10 commits + revert e-sign code → cherry-pick เฉพาะ MinioUploadService + tests + integration ปลอดภัยกว่า
