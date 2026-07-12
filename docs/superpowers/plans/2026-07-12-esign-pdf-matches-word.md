# eSign PDF Matches Word — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The eSign PDF export is the Word document rendered to PDF via LibreOffice, so the two are identical.

**Architecture:** Extract a shared `.docx` builder; `toPdf()` builds that `.docx` and runs `libreoffice --headless --convert-to pdf`. The DOCX declares TH Sarabun PSK as its default font, and that font is installed in the container so LibreOffice renders Thai correctly.

**Tech Stack:** Laravel (PHP 8.3), PhpWord, LibreOffice (already in the image), PHPUnit.

---

### Task 1: `LibreOfficeConverter::convertToPdf`

**Files:**
- Modify: `apps/app-laravel/app/Services/Fast/LibreOfficeConverter.php`
- Test: `apps/app-laravel/tests/Unit/LibreOfficeConverterTest.php`

- [ ] **Step 1: Write the failing test**

Add to `LibreOfficeConverterTest.php`:

```php
public function test_builds_correct_command_for_pdf(): void
{
    $tmpDir = sys_get_temp_dir().'/libreoffice-pdf-test-'.uniqid('', true);
    mkdir($tmpDir);
    $docxPath = $tmpDir.'/test.docx';
    file_put_contents($docxPath, 'fake docx');

    $captured = '';
    $converter = new LibreOfficeConverter(
        binary: 'libreoffice',
        commandRunner: function (array $cmd) use (&$captured): int {
            $captured = implode(' ', $cmd);
            $base = pathinfo($cmd[count($cmd) - 1], PATHINFO_FILENAME);
            $outDir = $cmd[array_search('--outdir', $cmd, true) + 1];
            file_put_contents("{$outDir}/{$base}.pdf", '%PDF-1.7 fake');

            return 0;
        },
    );

    $result = $converter->convertToPdf($docxPath);

    $this->assertStringContainsString('--convert-to pdf', $captured);
    $this->assertFileExists($result);
    $this->assertStringEndsWith('.pdf', $result);

    @unlink($result);
    @unlink($docxPath);
    @rmdir(dirname($result));
    @rmdir($tmpDir);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel-app php artisan test --filter=test_builds_correct_command_for_pdf`
Expected: FAIL — `Call to undefined method ...::convertToPdf()`.

- [ ] **Step 3: Implement `convertToPdf` + shared helper**

In `LibreOfficeConverter.php`, replace the body of `convertToDocx` to delegate to a shared `convert`, and add `convertToPdf`:

```php
public function convertToDocx(string $inputPath): string
{
    if (strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)) === 'docx') {
        return $inputPath;
    }

    return $this->convert($inputPath, 'docx', 'docx');
}

public function convertToPdf(string $inputPath): string
{
    return $this->convert($inputPath, 'pdf', 'pdf');
}

private function convert(string $inputPath, string $targetFormat, string $targetExt): string
{
    if (! file_exists($inputPath)) {
        throw new RuntimeException("Input file does not exist: {$inputPath}");
    }

    $outDir = sys_get_temp_dir().'/libreoffice-'.bin2hex(random_bytes(8));
    if (! is_dir($outDir) && ! mkdir($outDir, 0700, true) && ! is_dir($outDir)) {
        throw new RuntimeException("Unable to create conversion directory: {$outDir}");
    }

    $cmd = [$this->binary, '--headless', '--convert-to', $targetFormat, '--outdir', $outDir, $inputPath];

    $exit = $this->runCommand($cmd);
    if ($exit !== 0) {
        throw new RuntimeException("LibreOffice conversion failed (exit {$exit}) for {$inputPath}");
    }

    $base = pathinfo($inputPath, PATHINFO_FILENAME);
    $outPath = "{$outDir}/{$base}.{$targetExt}";

    if (! file_exists($outPath)) {
        throw new RuntimeException("Converted file not found at {$outPath}");
    }

    return $outPath;
}
```

Keep the existing `runCommand` method unchanged. Remove the now-duplicated body that lived in the old `convertToDocx` (the `--convert-to docx` inline block).

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel-app php artisan test --filter=LibreOfficeConverterTest`
Expected: PASS (all 4 tests — existing docx ones + the new pdf one).

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/app/Services/Fast/LibreOfficeConverter.php apps/app-laravel/tests/Unit/LibreOfficeConverterTest.php
git commit -m "feat(export): LibreOfficeConverter::convertToPdf"
```

---

### Task 2: DOCX default font + shared builder + `toPdf` via LibreOffice

**Files:**
- Modify: `apps/app-laravel/app/Services/DocumentExportService.php`
- Test: `apps/app-laravel/tests/Unit/DocumentExportServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/DocumentExportServiceTest.php`:

```php
public function test_docx_declares_th_sarabun_psk_default_font(): void
{
    $service = app(\App\Services\DocumentExportService::class);
    $bytes = $service->toDocx($this->sampleDocument());

    // Word2007 docx is a zip; settings/styles carry the default font name.
    $tmp = tempnam(sys_get_temp_dir(), 'docx_font_').'.docx';
    file_put_contents($tmp, $bytes);
    $zip = new \ZipArchive;
    $this->assertTrue($zip->open($tmp) === true);
    $stylesXml = (string) $zip->getFromName('word/styles.xml');
    $zip->close();
    @unlink($tmp);

    $this->assertStringContainsString('TH Sarabun PSK', $stylesXml);
}

public function test_to_pdf_renders_docx_via_libreoffice(): void
{
    $converter = new \App\Services\Fast\LibreOfficeConverter(
        binary: 'libreoffice',
        commandRunner: function (array $cmd): int {
            $base = pathinfo($cmd[count($cmd) - 1], PATHINFO_FILENAME);
            $outDir = $cmd[array_search('--outdir', $cmd, true) + 1];
            file_put_contents("{$outDir}/{$base}.pdf", '%PDF-1.7 from-docx');

            return 0;
        },
    );
    $this->app->instance(\App\Services\Fast\LibreOfficeConverter::class, $converter);

    $service = app(\App\Services\DocumentExportService::class);
    $bytes = $service->toPdf($this->sampleDocument());

    $this->assertStringStartsWith('%PDF', $bytes);
}
```

Add a `sampleDocument()` helper to the test class if one does not already exist:

```php
private function sampleDocument(): array
{
    return [
        'document_id' => 'doc_test',
        'pages' => [[
            'page_no' => 1,
            'blocks' => [[
                'block_id' => 'b1', 'type' => 'paragraph', 'reading_order' => 1,
                'approved_text' => 'ข้อความ', 'normalized_text' => 'ข้อความ', 'raw_text' => 'ข้อความ',
                'meta' => ['reviewed_html' => '<p>ข้อความ</p>', 'layout' => ['indent_left' => 720, 'tabs' => []]],
            ]],
        ]],
    ];
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel-app php artisan test --filter=DocumentExportServiceTest`
Expected: FAIL — default font not present; `toPdf` still calls the HTTP pdf-service (no `%PDF` from the fake converter).

- [ ] **Step 3: Implement**

In `DocumentExportService.php`:

a) Inject the converter — change the constructor:

```php
public function __construct(
    private readonly DocumentHtmlService $documentHtmlService,
    private readonly \App\Services\Fast\LibreOfficeConverter $libreOffice,
) {}
```

b) Extract the DOCX builder. Replace the tail of `toDocx` (from `$phpWord = new PhpWord;` through the temp-file read) so the PhpWord assembly lives in `buildDocxFile`, and set the default font:

```php
public function toDocx(array $document): string
{
    $docxPath = $this->buildDocxFile($document);
    $content = (string) file_get_contents($docxPath);
    @unlink($docxPath);

    return $content;
}

private function buildDocxFile(array $document): string
{
    $phpWord = new PhpWord;
    $phpWord->setDefaultFontName('TH Sarabun PSK');
    $phpWord->setDefaultFontSize(16);

    $section = $phpWord->addSection([
        'paperSize' => 'A4',
        'marginTop' => 1440,
        'marginBottom' => 1440,
        'marginLeft' => 1800,
        'marginRight' => 1800,
    ]);

    foreach ($this->orderedBlocks($document) as $block) {
        $table = $this->normalizeTable($block);
        $isTable = (string) ($block['type'] ?? '') === 'table' && $table !== null && ($table['cells'] ?? []) !== [];

        if ($isTable) {
            $this->appendTable($section, $table);
            continue;
        }

        $html = $this->blockHtmlOrFallback($block);
        $runs = $this->parseHtmlRuns($html);
        if ($runs === []) {
            continue;
        }

        $textRun = $section->addTextRun($this->paragraphStyleForBlock($block));
        foreach ($runs as $run) {
            $parts = explode("\n", (string) ($run['text'] ?? ''));
            foreach ($parts as $index => $part) {
                if ($part !== '') {
                    $textRun->addText($part, $this->fontStyleForRun($run));
                }
                if ($index < count($parts) - 1) {
                    $textRun->addTextBreak();
                }
            }
        }
    }

    $tempPath = tempnam(sys_get_temp_dir(), 'esign_docx_');
    if ($tempPath === false) {
        throw new RuntimeException('Unable to create temporary DOCX file.');
    }
    $docxPath = $tempPath.'.docx';
    @unlink($tempPath);
    IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);

    return $docxPath;
}
```

c) Replace `toPdf` to convert the docx (drop the Puppeteer HTTP call). Keep the `'PDF service unavailable'` message so the controller still maps failures to 503:

```php
public function toPdf(array $document): string
{
    $docxPath = $this->buildDocxFile($document);
    $pdfPath = null;
    try {
        $pdfPath = $this->libreOffice->convertToPdf($docxPath);
        $bytes = file_get_contents($pdfPath);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('PDF service unavailable');
        }

        return $bytes;
    } catch (\Throwable $e) {
        throw new RuntimeException('PDF service unavailable', 0, $e);
    } finally {
        @unlink($docxPath);
        if ($pdfPath !== null) {
            @unlink($pdfPath);
            @rmdir(dirname($pdfPath));
        }
    }
}
```

Leave `buildHtml` in place (unused by eSign now; harmless). Remove the `use Illuminate\Support\Facades\Http;` and `ConnectionException` imports only if no longer referenced.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel-app php artisan test --filter=DocumentExportServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/app/Services/DocumentExportService.php apps/app-laravel/tests/Unit/DocumentExportServiceTest.php
git commit -m "feat(export): PDF rendered from DOCX via LibreOffice; DOCX declares TH Sarabun PSK"
```

---

### Task 3: Update the PDF controller feature tests for the LibreOffice path

**Files:**
- Modify: `apps/app-laravel/tests/Feature/PdfExportControllerTest.php`

- [ ] **Step 1: Rewrite the two tests to use a fake converter instead of `Http::fake`**

Replace the body of `test_export_pdf_returns_pdf_and_stamps_esign_exported_at`'s HTTP setup with a bound fake converter, and its final `Http::assertSent(...)` block with nothing (drop it). At the top of the test (before the request), add:

```php
$this->app->instance(\App\Services\Fast\LibreOfficeConverter::class, new \App\Services\Fast\LibreOfficeConverter(
    binary: 'libreoffice',
    commandRunner: function (array $cmd): int {
        $base = pathinfo($cmd[count($cmd) - 1], PATHINFO_FILENAME);
        $outDir = $cmd[array_search('--outdir', $cmd, true) + 1];
        file_put_contents("{$outDir}/{$base}.pdf", '%PDF-1.7 test');

        return 0;
    },
));
```

Remove the `config()->set('services.pdf.base_url', ...)` and `Http::fake([...])` lines. Change the content assertion to:

```php
$this->assertStringStartsWith('%PDF', $response->getContent());
```

For `test_export_pdf_returns_503_when_service_is_unavailable`, replace the `Http::fake(throw ...)` with a converter whose runner returns a non-zero exit (forcing `toPdf` to throw `'PDF service unavailable'` → 503):

```php
$this->app->instance(\App\Services\Fast\LibreOfficeConverter::class, new \App\Services\Fast\LibreOfficeConverter(
    binary: 'libreoffice',
    commandRunner: fn (array $cmd): int => 1,
));
```

Keep `->assertStatus(503)->assertSeeText('PDF service unavailable')`.

- [ ] **Step 2: Run tests to verify they pass**

Run: `docker compose exec laravel-app php artisan test --filter=PdfExportControllerTest`
Expected: PASS (both tests).

- [ ] **Step 3: Run the export suite to confirm no regressions**

Run: `docker compose exec laravel-app php artisan test tests/Unit/DocumentExportServiceTest.php tests/Feature/WordExportControllerTest.php tests/Feature/PdfExportControllerTest.php tests/Unit/LibreOfficeConverterTest.php`
Expected: all PASS.

- [ ] **Step 4: Commit**

```bash
git add apps/app-laravel/tests/Feature/PdfExportControllerTest.php
git commit -m "test(export): PDF export via fake LibreOffice converter"
```

---

### Task 4: Install TH Sarabun PSK in the container

**Files:**
- Add: `apps/app-laravel/resources/fonts/THSarabunPSK.ttf` (+ Bold/Italic/BoldItalic if available)
- Modify: `apps/app-laravel/Dockerfile`

- [ ] **Step 1: Add the font files**

Obtain the freely redistributable **TH Sarabun PSK** TrueType files (regular + bold + italic + bold-italic) and place them under `apps/app-laravel/resources/fonts/`. (TH Sarabun PSK is published by SIPA/Thai government for free use.)

- [ ] **Step 2: Install fonts in the Dockerfile**

In `apps/app-laravel/Dockerfile`, in the same `apt-get install` layer that installs `libreoffice-*`, add a Thai fallback and fontconfig:

```dockerfile
    fonts-thai-tlwg \
    fontconfig \
```

Then, after the app files are copied (or in a dedicated layer), copy the bundled font and refresh the cache:

```dockerfile
COPY apps/app-laravel/resources/fonts/*.ttf /usr/share/fonts/truetype/th-sarabun-psk/
RUN fc-cache -f
```

(Match the existing Dockerfile's COPY context/paths.)

- [ ] **Step 3: Rebuild and verify the font is present**

Run:
```bash
docker compose build laravel-app queue-worker
docker compose up -d laravel-app queue-worker
docker compose exec -T laravel-app fc-list | grep -i sarabun
```
Expected: at least one `TH Sarabun PSK` entry.

- [ ] **Step 4: Manual parity check**

Export Word and PDF for the same document from the Result page. Open both and confirm margins, indentation, tabs, Thai font, and tables match.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/fonts apps/app-laravel/Dockerfile
git commit -m "build: install TH Sarabun PSK for LibreOffice PDF rendering"
```

---

## Self-Review

- **Spec coverage:** LibreOffice PDF path (Task 1–2), DOCX default font (Task 2), test updates (Task 2–3), container font install (Task 4). All spec sections mapped.
- **Placeholder scan:** none — concrete code and commands throughout. The only non-code asset is the TH Sarabun PSK `.ttf` (Task 4 Step 1), which is an unavoidable external file, explicitly called out.
- **Type consistency:** `convertToPdf`/`convert` signatures match usage; `LibreOfficeConverter` injected into `DocumentExportService`; `toPdf` throws `'PDF service unavailable'` matching `PdfExportController`'s existing 503 mapping.
- **Ambiguity:** the 503 contract is preserved by reusing the exact message string; tests assert it.
