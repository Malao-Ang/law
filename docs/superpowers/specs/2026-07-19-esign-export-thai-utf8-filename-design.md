# eSign Export — Thai UTF-8 Content + Original Filename

**Date:** 2026-07-19
**Status:** Approved (design)

## Problem

The eSign export (PDF and Word) has two defects:

1. **Thai content is garbled in the exported PDF.** The DOCX built by `DocumentExportService` sets its default font to `TH Sarabun PSK`, but that font is not installed in the `laravel-app`/`queue-worker` container. `fc-list` in the running container returns **zero** Thai fonts. The Dockerfile (commit 45cc564) creates an *empty* `/usr/share/fonts/truetype/th-sarabun-psk/` directory and installs `fonts-thai-tlwg`, but never installs the actual TH Sarabun PSK font the DOCX requests. LibreOffice therefore cannot find the font during `docx → pdf` conversion and substitutes/renders Thai incorrectly. (The running image also predates the commit and has no Thai fonts at all.)

2. **Downloaded filename does not match the original.** `DocumentExportService::safeFilenameBase()` runs `preg_replace('/[^a-zA-Z0-9_\-\.]/u', '_', $baseName)`, which replaces every Thai character with `_` (e.g. `ประกาศ (1)` → `_______`). Additionally, the `Content-Disposition` header uses a bare `filename="..."`, which is not valid for UTF-8 names (RFC 6266 requires `filename*=UTF-8''…`), and the frontend parser only reads `filename="..."`. The original name is already available server-side as `source_file` (`$file->getClientOriginalName()`); it is simply being destroyed.

## Goals

- Exported PDF renders Thai text correctly, using **TH Sarabun PSK** so it matches Word.
- Exported PDF **and** Word files download with the original document's Thai filename preserved.
- No regression to the existing docx→pdf pipeline, table rendering, or block layout.

## Non-goals

- Changing the extraction pipeline, block model, or how `source_file` is captured.
- Adding new export formats or a font picker.
- OCR/normalization behavior.

## Design

### A. Install TH Sarabun PSK in the container

- Vendor the genuine TH Sarabun PSK `.ttf` files (regular, bold, italic, bold-italic) into `apps/app-laravel/docker/fonts/`. **The user supplies these files** (corporate/approved copies).
- In `apps/app-laravel/Dockerfile`, replace the empty-directory step with a real install:
  ```dockerfile
  COPY docker/fonts/*.ttf /usr/share/fonts/truetype/th-sarabun-psk/
  RUN fc-cache -f
  ```
  (COPY the whole `*.ttf` glob so exact filenames don't matter, as long as they register the family name `TH Sarabun PSK`.)
- Keep `fonts-thai-tlwg` as a secondary fallback for any non-Sarabun Thai glyphs.
- Rebuild the `laravel-app` and `queue-worker` images (they share the Dockerfile) so the font is present at runtime.

**Verification:** after rebuild, `docker compose exec laravel-app fc-list | grep -i sarabun` must list the 4 faces. An exported PDF opened in a viewer must show Thai text in TH Sarabun (not tofu/boxes, not a substitute serif).

### B. Thai-safe filename (RFC 6266)

1. **`DocumentExportService::safeFilenameBase()`** — rewrite the sanitizer to preserve Thai:
   - Prefer `source_file` (true original upload name) over `law_meta.title` as the base. Strip only the extension via `pathinfo(..., PATHINFO_FILENAME)`.
   - Remove only filesystem-illegal characters: `/ \ : * ? " < > |` and ASCII control chars (0x00–0x1F). **Keep** Thai characters, spaces, parentheses, and other printable characters. Collapse runs of whitespace to a single space and trim leading/trailing space.
   - Fall back to `document` if the result is empty.
   - Keep returning the base name **without** extension (callers append `.pdf` / `.docx`).

2. **`PdfExportController` and `WordExportController`** — build the header with Symfony's helper instead of hand-building the string:
   ```php
   use Symfony\Component\HttpFoundation\HeaderUtils;

   $disposition = HeaderUtils::makeDisposition(
       HeaderUtils::DISPOSITION_ATTACHMENT,
       $filename.'.pdf',          // UTF-8 name → emitted as filename*
       // ASCII fallback auto-derived, or pass an explicit transliterated fallback
   );
   ```
   `makeDisposition()` emits both a safe ASCII `filename="…"` fallback and `filename*=UTF-8''…` for the Thai name. This replaces the manual `'attachment; filename="'.$filename.'.pdf"'`.

3. **Frontend `client.ts::downloadBinaryExport`** — parse the RFC 6266 form first:
   - Try `filename\*=UTF-8''([^;\n]+)` → `decodeURIComponent(...)`.
   - Else fall back to the existing `filename="?([^";\n]+)"?` match.
   - Else the hardcoded `fallbackName`.

### Flow (unchanged except where noted)

```
POST /api/documents/{id}/export-pdf
  → PdfExportController::store
      → ReviewStore::getReviewDocument
      → DocumentExportService::toPdf
          → buildDocxFile (PhpWord, default font "TH Sarabun PSK")
          → LibreOfficeConverter::convertToPdf   ← now finds the real font
      → safeFilenameBase (Thai preserved)         ← changed
      → Content-Disposition via HeaderUtils        ← changed
  → frontend downloadBinaryExport parses filename* ← changed
```

## Testing

- **Unit — `DocumentExportServiceTest`:** add cases for `safeFilenameBase()`:
  - Thai `source_file` (`ประกาศ (1).pdf`) → returns `ประกาศ (1)` (Thai, space, and parens kept; extension stripped).
  - Filesystem-illegal chars (`a/b:c*.pdf`) → stripped.
  - Empty/whitespace title → `document`.
  - `source_file` preferred over `law_meta.title`.
- **Feature — `PdfExportControllerTest` / `WordExportControllerTest`:** assert the response `Content-Disposition` contains `filename*=UTF-8''` with the percent-encoded Thai name and an ASCII `filename=` fallback.
- **Manual — content:** rebuild images, export a Thai document to PDF, open it, confirm Thai renders in TH Sarabun. (`fc-list | grep -i sarabun` as the pre-check.)
- Existing table/layout export tests must still pass.

## Rollout

1. User drops TH Sarabun PSK `.ttf` files into `apps/app-laravel/docker/fonts/`.
2. Apply code changes (service, controllers, client, Dockerfile).
3. Rebuild `laravel-app` + `queue-worker` images; recreate containers.
4. Verify `fc-list` and an end-to-end Thai export (correct glyphs + correct filename).

## Risks

- **Font licensing:** TH Sarabun PSK is a Thai National Font (freely distributable, embeddable). Vendoring is acceptable; user provides the approved copies.
- **Font family name mismatch:** if the supplied TTFs register as `TH Sarabun New` rather than `TH Sarabun PSK`, LibreOffice still won't match. Mitigation: the existing DOCX font stack already lists `TH Sarabun New` as a fallback; if needed, add a fontconfig alias mapping `TH Sarabun PSK` → the installed family. Confirm registered name with `fc-scan` after install.
- **ASCII fallback quality:** `makeDisposition()`'s auto fallback may reduce an all-Thai name to a generic token for legacy clients; modern browsers use `filename*`. Acceptable.
