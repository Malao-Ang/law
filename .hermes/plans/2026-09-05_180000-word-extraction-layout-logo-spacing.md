# Plan: Preserve Word extraction layout — line height, 16pt font, first-logo sizing, image spacing

## Goal
Improve Word/DOCX extraction and PDF export so imported legal Word documents preserve readable line spacing, default 16pt font, and logo/image sizing rules: first top logo is centered at 2.99 cm wide, image-to-text spacing is 1.5 line height, normal text line height is 1.0.

## Current context / assumptions
- Project root: `D:/workspace/outside/docling-thai-poc`
- Fast Word extraction path:
  - `apps/app-laravel/app/Services/Fast/FastDocxExtractor.php`
  - `apps/app-laravel/app/Services/Fast/Docx/ParagraphParser.php`
  - `apps/app-laravel/app/Services/Fast/Docx/ImageExtractor.php`
- Preview/export path:
  - `apps/app-laravel/app/Services/DocumentHtmlService.php`
  - `apps/app-laravel/app/Services/DocumentExportService.php`
- `ParagraphParser::extractLayout()` already reads Word paragraph spacing:
  - `spacing_before`
  - `spacing_after`
  - `line_spacing`
- `ParagraphParser::extractFormatting()` already reads `font_size_pt` from Word `w:sz`.
- `DocumentHtmlService::buildLayoutStyleAttribute()` already converts Word spacing to CSS and Word `line_spacing / 240` to CSS `line-height`.
- `DocumentExportService` currently uses default body/docx font size `16` but default line height is `1.85`; user wants normal text line-height `1.0` and image-to-text spacing `1.5`.
- `ImageExtractor` currently extracts images but does not extract DOCX drawing size (`a:ext cx/cy`) or mark the first/top logo. It returns `width => null`, `height => null`.
- User requirement interpreted:
  1. Normal text line height should be 1.0 unless original Word has explicit usable line spacing.
  2. Imported text font should be 16pt by default; preserve original font size when extracted, otherwise default 16pt.
  3. Only the first image at the top of the document is treated as logo: centered, width exactly 2.99 cm. Images later in the document must not get logo sizing.
  4. Spacing between logo/image and following text should be 1.5 line height / visibly separated.
  5. Prefer extracting original Word dimensions/spacing where present; apply fallbacks only when metadata is missing or clearly broken.

## Architecture / proposed approach
Use the DOCX XML as the source of truth first: parse image extents (`a:ext cx/cy` EMU values), paragraph alignment, Word paragraph spacing, and run font size. Normalize only unsafe/missing values at block creation and export boundaries. Add explicit metadata flags for top-logo behavior (`meta.image.is_logo`, `meta.image.display_width_cm = 2.99`) so preview and PDF export can apply the same rule without guessing later.

## Step-by-step tasks

### Task 1 — Add tests for DOCX layout extraction metadata (RED)

File: create `apps/app-laravel/tests/Unit/FastDocxLayoutMetadataTest.php`

Test behaviors:
1. A paragraph with no explicit line spacing gets fallback `line_spacing = 240` (single line / 1.0).
2. A paragraph with Word `w:spacing w:line="360"` keeps line spacing 360 (1.5) instead of being overwritten.
3. A run with `w:sz w:val="32"` extracts `font_size_pt = 16.0`.
4. A first paragraph containing an image is marked as logo only when it appears before any non-empty text block.
5. Logo image metadata has:
   - `is_logo = true`
   - `display_width_cm = 2.99`
   - `alignment = center`
6. A later image is not logo:
   - `is_logo = false` or missing
   - no forced `display_width_cm = 2.99`

Use tiny in-memory DOCX fixtures in the test. If existing tests have helpers for writing DOCX zip files, reuse them. Otherwise create a helper in this test:

```php
private function makeDocx(string $documentXml, array $media = [], string $relsXml = ''): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx_layout_').'.docx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdDoc" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/_rels/document.xml.rels', $relsXml ?: '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    foreach ($media as $name => $bytes) {
        $zip->addFromString('word/media/'.$name, $bytes);
    }
    $zip->close();
    return $path;
}
```

Run RED:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
docker compose exec laravel-app php artisan test --filter=FastDocxLayoutMetadataTest
```
Expected before implementation: tests fail because image width/logo metadata/fallback line spacing is missing.

If Docker is not running, run:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
php artisan test --filter=FastDocxLayoutMetadataTest
```

### Task 2 — Normalize paragraph layout defaults minimally (GREEN)

File: `apps/app-laravel/app/Services/Fast/Docx/ParagraphParser.php`

Modify `extractLayout()`:
- Keep explicitly parsed Word values.
- If `line_spacing` is missing/null, set to `240` (single line, CSS/PDF line-height 1.0).
- Do not overwrite `spacing_before` / `spacing_after` when Word supplies them.
- Do not invent large margins; keep null unless Word supplies them.

Implementation detail near the return at line ~135:

```php
if (! is_numeric($layout['line_spacing'] ?? null) || (float) $layout['line_spacing'] <= 0) {
    $layout['line_spacing'] = 240;
}

return $layout;
```

Re-run:
```bash
docker compose exec laravel-app php artisan test --filter=FastDocxLayoutMetadataTest
```
Expected: paragraph spacing/font-size extraction tests pass; image/logo tests may still fail.

### Task 3 — Extract DOCX image dimensions and paragraph alignment (RED/GREEN)

File: `apps/app-laravel/app/Services/Fast/Docx/ImageExtractor.php`

Add dimension parsing from the same paragraph where the image appears:
- DrawingML path: find the nearest `wp:extent` or `a:ext` associated with the `a:blip`.
- VML path: parse `style="width:...;height:..."` when present if simple.
- Convert EMU to cm and px/pt for downstream use.

Constants:
```php
private const WORDPROCESSING_DRAWING_NS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
private const EMU_PER_CM = 360000;
private const CM_TO_PT = 28.3464567;
private const LOGO_WIDTH_CM = 2.99;
```

Extend `fromParagraph()` to include metadata for each image:
```php
'docx_width_cm' => $widthCm,
'docx_height_cm' => $heightCm,
'alignment' => $this->paragraphAlignment($paragraph),
```

Add helper:
```php
private function paragraphAlignment(DOMElement $paragraph): ?string
{
    $xpath = WordXml::createXPath($paragraph->ownerDocument);
    $jc = $xpath->query('./w:pPr/w:jc', $paragraph)?->item(0);
    return $jc instanceof DOMElement ? WordXml::wordAttr($jc, 'val') : null;
}
```

Do not force logo here yet; only extract raw source metadata.

Run:
```bash
docker compose exec laravel-app php artisan test --filter=FastDocxLayoutMetadataTest
```
Expected: raw dimension/alignment tests pass.

### Task 4 — Mark only the first top image as logo and force 2.99 cm width

File: `apps/app-laravel/app/Services/Fast/FastDocxExtractor.php`

Add state in `extract()`:
```php
$hasSeenTextBlock = false;
$logoAssigned = false;
```

When extracting image blocks before paragraph text:
- If `!$hasSeenTextBlock && !$logoAssigned`, mark first image as logo.
- Set:
```php
$imageMeta['is_logo'] = true;
$imageMeta['display_width_cm'] = 2.99;
$imageMeta['alignment'] = 'center';
$imageMeta['spacing_after_line_height'] = 1.5;
```
- Set `$logoAssigned = true`.
- For all other images, keep original extracted dimensions and do not set `is_logo=true`.

When a non-empty paragraph block is emitted, set `$hasSeenTextBlock = true`.

Important: If a document has a later image after text, it must not become logo even if centered.

Run:
```bash
docker compose exec laravel-app php artisan test --filter=FastDocxLayoutMetadataTest
```
Expected: all extraction metadata tests pass.

### Task 5 — Apply image width + spacing consistently in HTML preview/export

File: `apps/app-laravel/app/Services/DocumentHtmlService.php`

Find where image block HTML is rendered. Add style generation rules:
- If `meta.image.display_width_cm` exists, render image width as `width: 2.99cm`.
- Else if `meta.image.docx_width_cm` exists, render `width: {docx_width_cm}cm` with safe clamp.
- If `meta.image.alignment === 'center'` or `is_logo === true`, center the image block.
- If `spacing_after_line_height = 1.5`, add `margin-bottom: 1.5em` to the image wrapper.

Do not apply logo rules to later images that do not have `is_logo=true`.

File: `apps/app-laravel/app/Services/DocumentExportService.php`

Modify `appendImage()`:
- Prefer `display_width_cm` -> convert cm to points using `cm * 28.3464567`.
- Else use existing `imageWidthPt()` fallback from `display_width_px` / `getimagesize`.
- If `is_logo === true`, force alignment center.
- After adding image, if `spacing_after_line_height` exists, add a small blank paragraph or spacing after equivalent.

Recommended helper:
```php
private function imageWidthPt(array $imgMeta, string $path): ?float
{
    $displayCm = $imgMeta['display_width_cm'] ?? null;
    if (is_numeric($displayCm) && (float) $displayCm > 0) {
        return min((float) $displayCm * 28.3464567, 6.25 * 72);
    }

    $docxCm = $imgMeta['docx_width_cm'] ?? null;
    if (is_numeric($docxCm) && (float) $docxCm > 0) {
        return min((float) $docxCm * 28.3464567, 6.25 * 72);
    }

    // existing fallback follows...
}
```

Run focused export tests after adding them in Task 6.

### Task 6 — Add PDF/DOCX export behavior tests

File: create `apps/app-laravel/tests/Unit/DocumentExportImageLayoutTest.php`

Test the PHPWord export layer without needing full LibreOffice PDF conversion:
1. Document with logo image metadata `display_width_cm=2.99` should create DOCX with image extent width close to `2.99cm`.
2. Paragraph style for normal text should use line height 1.0 when source layout has `line_spacing=240`.
3. Paragraph style should preserve 1.5 only where layout says `line_spacing=360` or image spacing demands it.

If inspecting generated DOCX is too heavy, make `imageWidthPt()` and paragraph style helpers package-private/public only if acceptable; otherwise test `toDocx()` output by unzipping `word/document.xml` and checking `wp:extent/@cx` near `2.99 * 360000` EMU.

Run RED before implementation if possible, then GREEN after Task 5:
```bash
docker compose exec laravel-app php artisan test --filter=DocumentExportImageLayoutTest
```
Expected: pass after export changes.

### Task 7 — Clamp broken Word line spacing to avoid overlapping text

File: `apps/app-laravel/app/Services/DocumentHtmlService.php`
File: `apps/app-laravel/app/Services/DocumentExportService.php`

Some Word files can contain broken or too-small line spacing that causes overlap. Add a shared normalization rule:
- Word `line_spacing` converts to `lineHeight = line_spacing / 240`.
- Clamp text line-height to minimum `1.0`.
- Clamp maximum to maybe `2.0` unless Word explicitly needs more; avoid exploding layout.

In `DocumentHtmlService::buildLayoutStyleAttribute()` change:
```php
$lineHeight = (float) $lineSpacing / 240.0;
```
to:
```php
$lineHeight = max(1.0, min((float) $lineSpacing / 240.0, 2.0));
```

In `DocumentExportService::lineHeightFromHtml()` / paragraph style conversion, apply same clamp.

Add/extend tests:
- `line_spacing=120` should render/export as `line-height:1.00`, not `0.50`.
- `line_spacing=360` should render/export as `1.50`.

### Task 8 — Preserve font size 16pt fallback and extracted sizes

Files:
- `apps/app-laravel/app/Services/Fast/Docx/ParagraphParser.php`
- `apps/app-laravel/app/Services/DocumentHtmlService.php`
- `apps/app-laravel/app/Services/DocumentExportService.php`

Rules:
- If Word run has `w:sz`, keep extracted `font_size_pt`.
- If missing, default display/export to 16pt.
- Do not multiply Thai font size by CSS px conversion; keep units as pt through backend HTML and PHPWord.
- In export `buildDocxFile()`, default is already `setDefaultFontSize(16)` and `addText(... size => 16)` for blank. Keep this.
- If `parseHtmlRuns()` sees `font-size: 16pt`, export size 16.

Add tests around existing `ParagraphParser::extractFormatting()` and export `parseHtmlRuns()` if not already covered.

## Tests / validation

### Strict TDD cycle
For each behavior slice:
1. Write/extend the specific test.
2. Run the specific test and confirm it fails for the expected reason.
3. Implement minimal production code.
4. Run the same test and confirm it passes.
5. Run broader relevant tests.

### Commands
Preferred Docker commands:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
docker compose exec laravel-app php artisan test --filter=FastDocxLayoutMetadataTest
docker compose exec laravel-app php artisan test --filter=DocumentExportImageLayoutTest
docker compose exec laravel-app php artisan test --filter=ReviewStore
docker compose exec laravel-app vendor/bin/pint --dirty
npm run typecheck
```

If Docker is unavailable, fallback:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
php artisan test --filter=FastDocxLayoutMetadataTest
php artisan test --filter=DocumentExportImageLayoutTest
vendor/bin/pint --dirty
npm run typecheck
```

Expected:
- Focused tests pass.
- `npm run typecheck` exits 0.
- `pint --dirty` formats only touched PHP files.

## Manual validation checklist
Use a DOCX with:
1. A logo image as the first body content.
2. Normal Thai paragraphs after the logo.
3. A later inline/section image after text.
4. Explicit Word line spacing 1.5 on at least one paragraph.
5. A 16pt run.

Expected after upload/extract/export:
- First top logo is centered and width ~2.99 cm.
- Later image keeps original size and is not forced to 2.99 cm.
- Text paragraphs do not overlap.
- Normal paragraph line-height is 1.0.
- Image/logo gap before following text is visibly larger, equivalent to 1.5 line height.
- Exported PDF visually matches the reviewed document more closely.

## Commit plan
Commit in small logical groups:

1. Tests first:
```bash
git add apps/app-laravel/tests/Unit/FastDocxLayoutMetadataTest.php apps/app-laravel/tests/Unit/DocumentExportImageLayoutTest.php
git commit -m "test(word): cover imported spacing and logo image layout"
```
Only commit tests after RED has been observed and implementation follows; if repository policy dislikes failing-test commits, keep this as a local staged checkpoint and commit with implementation.

2. Extraction metadata:
```bash
git add apps/app-laravel/app/Services/Fast/Docx/ParagraphParser.php apps/app-laravel/app/Services/Fast/Docx/ImageExtractor.php apps/app-laravel/app/Services/Fast/FastDocxExtractor.php
git commit -m "fix(word): preserve spacing font size and first logo metadata"
```

3. Preview/export layout:
```bash
git add apps/app-laravel/app/Services/DocumentHtmlService.php apps/app-laravel/app/Services/DocumentExportService.php
git commit -m "fix(export): apply word logo size and safe line heights"
```

## Risks, tradeoffs, and open questions
- The phrase “ขนาดของ fromt ที่ 16” is interpreted as default font size 16pt. If the user meant a different exact font family/size rule, adjust before implementation.
- For DOCX images, a paragraph may contain multiple images. Only the first image before text should get logo treatment; subsequent images in the same opening paragraph should keep original dimensions unless user wants all top images treated as logos.
- PDF correctness depends on LibreOffice/PHPWord conversion. Automated tests can verify DOCX XML extents/styles; final PDF visual verification still needs a real sample document.
- Existing documents already extracted before this change will not automatically gain logo metadata. If needed, re-upload/reprocess old DOCX files or write a backfill/reprocess command.
- Current code may already respect explicit Word `line_spacing`; this plan adds safer defaults/clamps to avoid overlap rather than removing original Word spacing.
