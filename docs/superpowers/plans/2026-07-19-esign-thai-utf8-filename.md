# eSign Export — Thai UTF-8 Content + Original Filename — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix two eSign export defects: (A) Thai text garbled in exported PDF because TH Sarabun PSK font is missing from the container; (B) downloaded file has underscores instead of the original Thai filename.

**Architecture:** Three-layer fix — PHP service + controllers emit RFC 6266 Content-Disposition with `filename*=UTF-8''…`; TypeScript fetch helper parses `filename*=` first; Dockerfile vendors TH Sarabun PSK TTF files so LibreOffice renders Thai correctly during docx→pdf conversion. No new abstractions.

**Tech Stack:** Laravel 12 / PHP 8.4, Symfony\Component\HttpFoundation\HeaderUtils (already installed via v7.4.7), TypeScript, Docker multi-stage build.

---

## Prerequisites

**Before Task 4 (Dockerfile / font install):**
Drop the TH Sarabun PSK `.ttf` files (regular, bold, italic, bold-italic) into `apps/app-laravel/docker/fonts/`. The directory already exists. Without these files the Docker build step will succeed but LibreOffice will still not find the font. Get the files from your corporate-approved copies.

After adding them, confirm 4 files are present:
```bash
ls apps/app-laravel/docker/fonts/*.ttf | wc -l
# expected: 4
```

---

## File Map

| File | Change |
|---|---|
| `apps/app-laravel/app/Services/DocumentExportService.php` | Rewrite `safeFilenameBase()` — keep Thai, strip only illegal chars |
| `apps/app-laravel/app/Http/Controllers/Api/PdfExportController.php` | Use `HeaderUtils::makeDisposition()` for RFC 6266 header |
| `apps/app-laravel/app/Http/Controllers/Api/WordExportController.php` | Same |
| `apps/app-laravel/resources/js/api/client.ts` | Parse `filename*=UTF-8''` before `filename=` |
| `apps/app-laravel/Dockerfile` | `COPY docker/fonts/` → `/usr/share/fonts/truetype/th-sarabun-psk/` |
| `apps/app-laravel/tests/Unit/DocumentExportServiceTest.php` | Add `safeFilenameBase` unit tests |
| `apps/app-laravel/tests/Feature/PdfExportControllerTest.php` | Add `filename*=` assertion |
| `apps/app-laravel/tests/Feature/WordExportControllerTest.php` | Add `filename*=` assertion |

---

## Task 1 — Unit tests for `safeFilenameBase()` (TDD — write tests first)

**Files:**
- Modify: `apps/app-laravel/tests/Unit/DocumentExportServiceTest.php`

- [ ] **Step 1: Add failing tests to `DocumentExportServiceTest`**

Open `apps/app-laravel/tests/Unit/DocumentExportServiceTest.php` and add these test methods after the existing ones (before the closing `}`):

```php
public function test_safe_filename_base_preserves_thai(): void
{
    $document = [
        'source_file' => 'ประกาศ (1).pdf',
        'law_meta' => ['title' => 'something else'],
    ];

    $result = $this->makeService()->safeFilenameBase($document);

    $this->assertSame('ประกาศ (1)', $result);
}

public function test_safe_filename_base_prefers_source_file_over_law_meta_title(): void
{
    $document = [
        'source_file' => 'original.pdf',
        'law_meta' => ['title' => 'other title'],
    ];

    $result = $this->makeService()->safeFilenameBase($document);

    $this->assertSame('original', $result);
}

public function test_safe_filename_base_falls_back_to_law_meta_title_when_source_file_missing(): void
{
    $document = [
        'law_meta' => ['title' => 'กฎหมาย.pdf'],
    ];

    $result = $this->makeService()->safeFilenameBase($document);

    $this->assertSame('กฎหมาย', $result);
}

public function test_safe_filename_base_strips_filesystem_illegal_chars(): void
{
    $document = [
        'source_file' => 'a/b\\c:d*e?f"g<h>i|j.pdf',
    ];

    $result = $this->makeService()->safeFilenameBase($document);

    $this->assertSame('abcdefghij', $result);
}

public function test_safe_filename_base_collapses_whitespace(): void
{
    $document = [
        'source_file' => "กฎหมาย  ฉบับ   ที่.pdf",
    ];

    $result = $this->makeService()->safeFilenameBase($document);

    $this->assertSame('กฎหมาย ฉบับ ที่', $result);
}

public function test_safe_filename_base_returns_document_when_empty(): void
{
    $result = $this->makeService()->safeFilenameBase([]);

    $this->assertSame('document', $result);
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
docker compose exec laravel-app php artisan test --filter=DocumentExportServiceTest
```

Expected: 6 new tests fail. The first three will fail because the current code strips Thai. The others may also fail due to different stripping behavior.

- [ ] **Step 3: Rewrite `safeFilenameBase()` in `DocumentExportService.php`**

Replace lines 177–185 (the entire `safeFilenameBase` method) in `apps/app-laravel/app/Services/DocumentExportService.php`:

```php
public function safeFilenameBase(array $document): string
{
    $sourceFile = trim((string) ($document['source_file'] ?? ''));
    $lawMeta = is_array($document['law_meta'] ?? null) ? $document['law_meta'] : [];
    $lawTitle = trim((string) ($lawMeta['title'] ?? ''));

    $rawTitle = $sourceFile !== '' ? $sourceFile : ($lawTitle !== '' ? $lawTitle : 'document');
    $baseName = pathinfo($rawTitle, PATHINFO_FILENAME) ?: 'document';

    // Keep Thai and all printable Unicode; strip only filesystem-illegal chars and control chars
    $safeName = (string) preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/u', '', $baseName);
    // Collapse runs of whitespace to one space
    $safeName = (string) preg_replace('/\s+/u', ' ', $safeName);
    $safeName = trim($safeName);

    return $safeName !== '' ? $safeName : 'document';
}
```

- [ ] **Step 4: Run tests — expect PASS**

```bash
docker compose exec laravel-app php artisan test --filter=DocumentExportServiceTest
```

Expected: all tests pass including the 4 existing ones.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/app/Services/DocumentExportService.php \
        apps/app-laravel/tests/Unit/DocumentExportServiceTest.php
git commit -m "fix: safeFilenameBase preserves Thai chars, prefers source_file"
```

---

## Task 2 — RFC 6266 Content-Disposition in controllers (TDD — tests first)

**Files:**
- Modify: `apps/app-laravel/tests/Feature/PdfExportControllerTest.php`
- Modify: `apps/app-laravel/tests/Feature/WordExportControllerTest.php`
- Modify: `apps/app-laravel/app/Http/Controllers/Api/PdfExportController.php`
- Modify: `apps/app-laravel/app/Http/Controllers/Api/WordExportController.php`

- [ ] **Step 1: Add filename* assertion to PDF test**

In `apps/app-laravel/tests/Feature/PdfExportControllerTest.php`, update the document used in `test_export_pdf_returns_pdf_and_stamps_esign_exported_at` — change `source_file` from `'law.pdf'` to `'ประกาศ (1).pdf'`:

```php
$store->storeUpload(
    UploadedFile::fake()->create('ประกาศ (1).pdf', 64, 'application/pdf'),
    $documentId,
);
```

Also change `source_file` in `writeReviewDocument`:
```php
'source_file' => 'ประกาศ (1).pdf',
```

Then add these two assertions after the existing ones (after `$response->assertStatus(200)`):

```php
$disposition = (string) $response->headers->get('Content-Disposition');
$this->assertStringContainsString("filename*=utf-8''", strtolower($disposition));
$this->assertStringContainsString('%E0%B8%9B%E0%B8%A3%E0%B8%B0%E0%B8%81%E0%B8%B2%E0%B8%A8', $disposition);
```

(`%E0%B8%9B%E0%B8%A3%E0%B8%B0%E0%B8%81%E0%B8%B2%E0%B8%A8` is `ประกาศ` percent-encoded in UTF-8.)

- [ ] **Step 2: Add filename* assertion to Word test**

In `apps/app-laravel/tests/Feature/WordExportControllerTest.php`, change `source_file` in `storeUpload` and `writeReviewDocument` from `'law.pdf'` to `'ประกาศ (2).pdf'`:

```php
$store->storeUpload(
    UploadedFile::fake()->create('ประกาศ (2).pdf', 64, 'application/pdf'),
    $documentId,
);
```
```php
'source_file' => 'ประกาศ (2).pdf',
```

Then add after the existing `Content-Disposition` assertion:

```php
$disposition = (string) $response->headers->get('Content-Disposition');
$this->assertStringContainsString("filename*=utf-8''", strtolower($disposition));
$this->assertStringContainsString('%E0%B8%9B%E0%B8%A3%E0%B8%B0%E0%B8%81%E0%B8%B2%E0%B8%A8', $disposition);
```

- [ ] **Step 3: Run tests — expect FAIL**

```bash
docker compose exec laravel-app php artisan test --filter="PdfExportControllerTest|WordExportControllerTest"
```

Expected: the two `filename*=` assertions fail (current code emits bare `filename="..."` only).

- [ ] **Step 4: Update `PdfExportController`**

Replace `apps/app-laravel/app/Http/Controllers/Api/PdfExportController.php` fully:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentExportService;
use App\Services\ReviewStore;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PdfExportController extends Controller
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly DocumentExportService $exportService,
    ) {}

    public function store(string $documentId): Response
    {
        try {
            $document = $this->reviewStore->getReviewDocument($documentId);
        } catch (RuntimeException) {
            abort(404, 'Document not found.');
        }

        try {
            $pdfBytes = $this->exportService->toPdf($document);
        } catch (RuntimeException $exception) {
            $status = $exception->getMessage() === 'PDF service unavailable' ? 503 : 500;

            return response($exception->getMessage(), $status);
        }

        $this->reviewStore->setStatus($documentId, [
            'esign_exported_at' => now()->toIso8601String(),
        ]);

        $filenameWithExt = $this->exportService->safeFilenameBase($document).'.pdf';
        $asciiFallback = trim((string) preg_replace('/[^\x20-\x7e]/', '', $filenameWithExt)) ?: 'document.pdf';
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filenameWithExt,
            $asciiFallback,
        );

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]);
    }
}
```

- [ ] **Step 5: Update `WordExportController`**

Replace `apps/app-laravel/app/Http/Controllers/Api/WordExportController.php` fully:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentExportService;
use App\Services\ReviewStore;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

class WordExportController extends Controller
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly DocumentExportService $exportService,
    ) {}

    public function store(string $documentId): Response
    {
        try {
            $document = $this->reviewStore->getReviewDocument($documentId);
        } catch (RuntimeException) {
            abort(404, 'Document not found.');
        }

        $content = $this->exportService->toDocx($document);
        $this->reviewStore->setStatus($documentId, [
            'esign_exported_at' => now()->toIso8601String(),
        ]);

        $filenameWithExt = $this->exportService->safeFilenameBase($document).'.docx';
        $asciiFallback = trim((string) preg_replace('/[^\x20-\x7e]/', '', $filenameWithExt)) ?: 'document.docx';
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filenameWithExt,
            $asciiFallback,
        );

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => $disposition,
        ]);
    }
}
```

- [ ] **Step 6: Run all export tests — expect PASS**

```bash
docker compose exec laravel-app php artisan test --filter="PdfExportControllerTest|WordExportControllerTest|DocumentExportServiceTest"
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add apps/app-laravel/app/Http/Controllers/Api/PdfExportController.php \
        apps/app-laravel/app/Http/Controllers/Api/WordExportController.php \
        apps/app-laravel/tests/Feature/PdfExportControllerTest.php \
        apps/app-laravel/tests/Feature/WordExportControllerTest.php
git commit -m "fix: RFC 6266 Content-Disposition with filename*=UTF-8'' for Thai filenames"
```

---

## Task 3 — Frontend: parse `filename*=UTF-8''` first

**Files:**
- Modify: `apps/app-laravel/resources/js/api/client.ts` (lines ~246–248)

No automated TypeScript tests exist for this helper. Verify manually after Task 4's image rebuild.

- [ ] **Step 1: Update `downloadBinaryExport` in `client.ts`**

Find the block at around line 246 in `apps/app-laravel/resources/js/api/client.ts`:

```typescript
  const disposition = response.headers.get('Content-Disposition') ?? '';
  const match = /filename="?([^";\n]+)"?/.exec(disposition);
  anchor.download = match?.[1] ?? fallbackName;
```

Replace it with:

```typescript
  const disposition = response.headers.get('Content-Disposition') ?? '';
  const starMatch = /filename\*=utf-8''([^;\n]+)/i.exec(disposition);
  if (starMatch) {
    anchor.download = decodeURIComponent(starMatch[1]);
  } else {
    const match = /filename="?([^";\n]+)"?/.exec(disposition);
    anchor.download = match?.[1] ?? fallbackName;
  }
```

- [ ] **Step 2: Run TypeScript type check**

```bash
cd apps/app-laravel && npm run typecheck
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add apps/app-laravel/resources/js/api/client.ts
git commit -m "fix: parse RFC 6266 filename*=UTF-8'' in download helper"
```

---

## Task 4 — Dockerfile: install TH Sarabun PSK font

**Files:**
- Modify: `apps/app-laravel/Dockerfile`

> **Prerequisite:** `apps/app-laravel/docker/fonts/*.ttf` must contain at least the regular-weight TH Sarabun PSK TTF before building. See Prerequisites section above.

- [ ] **Step 1: Create `docker/fonts/` directory with placeholder**

```bash
mkdir -p apps/app-laravel/docker/fonts
touch apps/app-laravel/docker/fonts/.gitkeep
```

- [ ] **Step 2: Update Dockerfile — replace empty font step**

In `apps/app-laravel/Dockerfile`, find and replace:

```dockerfile
RUN mkdir -p /usr/share/fonts/truetype/th-sarabun-psk && fc-cache -f
```

Replace with:

```dockerfile
COPY docker/fonts/ /usr/share/fonts/truetype/th-sarabun-psk/
RUN fc-cache -f
```

- [ ] **Step 3: Drop TTF files into `docker/fonts/`**

Place TH Sarabun PSK `.ttf` files (from your corporate copies) into `apps/app-laravel/docker/fonts/`. For example:
- `THSarabunPSK.ttf`
- `THSarabunPSK Bold.ttf`
- `THSarabunPSK Italic.ttf`
- `THSarabunPSK BoldItalic.ttf`

Confirm:
```bash
ls apps/app-laravel/docker/fonts/*.ttf
```

- [ ] **Step 4: Rebuild the laravel-app image**

```bash
docker compose build laravel-app
docker compose up -d laravel-app queue-worker
```

The build should succeed and print "Step: COPY docker/fonts/ …".

- [ ] **Step 5: Verify font is registered inside the container**

```bash
docker compose exec laravel-app fc-list | grep -i sarabun
```

Expected output (at least one line):
```
/usr/share/fonts/truetype/th-sarabun-psk/THSarabunPSK.ttf: TH Sarabun PSK:style=Regular
```

If output is empty, run `docker compose exec laravel-app fc-cache -f` and retry.

- [ ] **Step 6: Commit (include the TTF files if your policy allows, or gitignore them and document)**

If TTF files can be committed:
```bash
git add apps/app-laravel/docker/fonts/ apps/app-laravel/Dockerfile
git commit -m "fix: install TH Sarabun PSK font in container for LibreOffice docx->pdf"
```

If not committable (font files should not go in the repo):
- Add `apps/app-laravel/docker/fonts/*.ttf` to `.gitignore`
- Document in your deployment runbook that the TTF files must be present before building

---

## Task 5 — Manual end-to-end verification

No automated test can cover the actual PDF rendering. Do this after Task 4's image rebuild.

- [ ] **Step 1: Upload a Thai-named document**

Open `http://localhost:8000/admin/upload`, upload any PDF or DOCX. Use a Thai filename when uploading from the OS file picker (rename the file first on your desktop if needed), e.g. `ประกาศ ฉบับที่ 1.pdf`.

- [ ] **Step 2: Run through review to eSign export**

After extraction completes, open the review page, then navigate to the RAG page and click "บันทึกและเผยแพร่" / the eSign export button.

- [ ] **Step 3: Check the downloaded file**

Verify:
1. The file that downloads is named `ประกาศ ฉบับที่ 1.pdf` (not `_______________.pdf`).
2. Open the PDF in a viewer — Thai text inside the document renders as readable Thai characters in TH Sarabun PSK style, not as boxes or question marks.

- [ ] **Step 4: Check Word export too**

Download the Word export. Filename should also preserve Thai. Open in Word/LibreOffice Writer — Thai text readable.

- [ ] **Step 5: Run full PHP test suite**

```bash
docker compose exec laravel-app php artisan test
```

Expected: no regressions.
