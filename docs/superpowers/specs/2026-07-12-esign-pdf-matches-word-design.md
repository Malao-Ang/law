# eSign PDF Matches Word — Design

**Date:** 2026-07-12
**Scope:** Make the eSign PDF export identical to the Word (.docx) export by rendering the PDF from the same document via LibreOffice.

## Problem

The Result page (eSign flow) offers Word export and PDF export. They are produced by two independent renderers, so they never look the same:

- **Word** (`DocumentExportService::toDocx`) — PhpWord builds a `.docx` (A4, margins 1440/1800 twips, per-block indent/tabs from `meta.layout`, per-run fonts).
- **PDF** (`DocumentExportService::toPdf` → `buildHtml` → `pdf-service` Puppeteer) — an independent HTML/CSS document rendered by Chromium (different margins, line-height, font metrics, tab handling).

The user wants the eSign PDF to be **the same as the Word document** created from the review.

## Goal

The PDF is the Word document rendered to PDF — identical margins, indentation, tabs, spacing, fonts, and tables — because both come from one `.docx` produced by `toDocx`.

## Approach (chosen)

Render the PDF **from the `.docx`** using the LibreOffice binary already present in the container (`/usr/bin/libreoffice`, used today for `.doc`→`.docx`).

`toPdf()` builds the same `.docx` as `toDocx()`, writes it to a temp file, runs `libreoffice --headless --convert-to pdf`, and returns the PDF bytes. Parity is by construction, not tuning.

## Non-goals (excluded)

- Tuning the Puppeteer HTML/CSS to approximate Word (rejected: never pixel-identical, ongoing drift).
- Changing the Word export's content/layout logic.
- Removing the `pdf-service` container from the repo (it simply stops being used by the eSign PDF path; removal is out of scope).

## Key findings (current state)

- LibreOffice is installed (`libreoffice-core`, `libreoffice-writer`) — confirmed at `/usr/bin/libreoffice`.
- **No Thai fonts are installed** (`fc-list` finds no Sarabun/Thai). LibreOffice would substitute a fallback → Thai renders wrong. This must be fixed.
- `toDocx` sets **no default font**; it only sets a font `name` per run when the run carries one. So the `.docx` does not declare TH Sarabun PSK. The review preview uses Sarabun/PSK at ~16pt.

## Architecture

### 1. DOCX declares the Thai font (parity + correct rendering)

In `toDocx` (and the shared builder), set the document default font so both Word and the LibreOffice-rendered PDF use it:

```php
$phpWord->setDefaultFontName('TH Sarabun PSK');
$phpWord->setDefaultFontSize(16);
```

Per-run `fontStyleForRun` overrides still apply. This also makes the standalone Word export render Thai with the intended font.

### 2. Extract a shared DOCX builder

Refactor `toDocx` so the PhpWord-building logic lives in a private `buildDocxFile(array $document): string` returning a temp `.docx` path. `toDocx()` reads that file to bytes; `toPdf()` feeds it to LibreOffice. No duplication of the block/paragraph/table logic.

### 3. `LibreOfficeConverter::convertToPdf`

Add a `convertToPdf(string $inputPath): string` mirroring `convertToDocx`, using `--convert-to pdf`. Factor the shared command/outdir logic into one private helper (`convert($input, $targetFormat, $targetExt)`) to keep it DRY. Returns the output `.pdf` path.

### 4. `toPdf` uses LibreOffice, not Puppeteer

```php
public function toPdf(array $document): string {
    $docxPath = $this->buildDocxFile($document);
    try {
        $pdfPath = $this->libreOffice->convertToPdf($docxPath);
        $bytes = file_get_contents($pdfPath);
        if ($bytes === false) { throw new RuntimeException('PDF conversion produced no output'); }
        return $bytes;
    } finally {
        // cleanup temp docx + pdf + outdir
    }
}
```

Inject `LibreOfficeConverter` into `DocumentExportService`'s constructor (currently only `DocumentHtmlService`), so the PDF path is testable with a fake command runner. Convert failure → `RuntimeException` (controller maps to 503, as today).

### 5. Fonts in the container

Add **TH Sarabun PSK** to the `laravel-app` image so LibreOffice can render it:

- Bundle the TH Sarabun PSK `.ttf` files in the repo (e.g. `apps/app-laravel/resources/fonts/`), `COPY` them to `/usr/share/fonts/truetype/th-sarabun-psk/`, and run `fc-cache -f` in the Dockerfile. (TH Sarabun PSK is a freely redistributable SIPA/government font.)
- Also `apt-get install fonts-thai-tlwg` as a Thai fallback so no Thai text ever tofus.
- Rebuild `laravel-app` (and `queue-worker`, same Dockerfile) so both have the font.

## Files touched

- Modify: `app/Services/DocumentExportService.php` — default font, `buildDocxFile`, `toPdf` via LibreOffice, constructor injection.
- Modify: `app/Services/Fast/LibreOfficeConverter.php` — `convertToPdf` + shared `convert` helper.
- Modify: `apps/app-laravel/Dockerfile` — install TH Sarabun PSK + `fonts-thai-tlwg` + `fc-cache`.
- Add: `apps/app-laravel/resources/fonts/THSarabunPSK*.ttf` (font files).
- Modify: `tests/Feature/PdfExportControllerTest.php` / add `tests/Unit/DocumentExportServiceTest.php` cases.
- (No change needed to `PdfExportController`; `services.pdf.base_url` becomes unused.)

## Testing

- **Unit:** `DocumentExportService::toPdf` with a fake `LibreOfficeConverter` (injected command runner) returns the converted bytes; converter failure throws `RuntimeException`.
- **Unit:** the built `.docx` declares default font `TH Sarabun PSK` (assert on the generated document / XML).
- **Feature:** `export-pdf` returns `application/pdf` and stamps `esign_exported_at` (existing test, updated for the LibreOffice path via a fake runner). Unavailable LibreOffice → 503.
- **Manual:** export Word and PDF for the same doc; open both — margins, indent, tabs, Thai font, and tables match.
