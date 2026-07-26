# Match Line Height to Uploaded DOCX — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the review editor and exported PDF use the uploaded DOCX's **default line spacing** instead of a hardcoded 1.85, so line height matches the source document; keep it editable (Google-Docs-style presets already exist) and keep blank/Enter lines rendering in the PDF.

**Architecture:** The Fast DOCX extractor already captures per-paragraph `w:spacing/@line` into `layout.line_spacing`, and the export already honors that and per-paragraph editor line-height (commit 112a7c9). The gap: paragraphs that **inherit** spacing get `line_spacing = null`, so both the editor (`.ProseMirror { line-height: 1.85 }`) and the export fall back to 1.85 — not the document's real default. This plan reads the DOCX's default line spacing from `styles.xml`, stores it as `compose_state.line_height` (a multiplier), and uses it as the shared fallback in the editor and the export.

**Tech Stack:** Laravel (PHP 8.3, PHPUnit), Vue 3 + Vuetify 3 + TypeScript.

**Already shipped (verify, don't rebuild):** per-paragraph line-height honored in PDF; blank/Enter lines render (zero-width space + line spacing). Task 6 only verifies these.

**Deliberately deferred (flag):** per-paragraph `w:lineRule="exact"/"atLeast"` fixed heights (only `auto`/multiplier handled); the standard/Python docling DOCX path; a custom numeric line-height input in the toolbar (presets 1.0/1.15/1.5/2.0 already exist).

**Representation:** `compose_state.line_height` = unitless multiplier (e.g. `1.0`, `1.15`), or absent/`null` → fall back to 1.85. Word stores `w:line` in 240ths for `lineRule="auto"`, so multiplier = `line / 240`.

---

### Task 1: Read the DOCX default line spacing from styles.xml

**Files:**
- Create: `apps/app-laravel/app/Services/Fast/Docx/DefaultLineSpacingReader.php`
- Modify: `apps/app-laravel/app/Services/Fast/Docx/DocxArchive.php` (add `stylesXml()`)
- Test: `apps/app-laravel/tests/Unit/DefaultLineSpacingReaderTest.php`

- [ ] **Step 1: Add a `stylesXml()` accessor to DocxArchive**

After `numberingXml()` (line 39), add:

```php
    public function stylesXml(): ?DOMDocument
    {
        if (! $this->exists('word/styles.xml')) {
            return null;
        }

        return $this->loadXml('word/styles.xml');
    }
```

- [ ] **Step 2: Write the failing test**

Create `apps/app-laravel/tests/Unit/DefaultLineSpacingReaderTest.php` (plain PHPUnit — this Unit test needs no Laravel bootstrap, matching `FastDocxExtractorTest`/`DocumentExportServiceTest`):

```php
<?php

namespace Tests\Unit;

use App\Services\Fast\Docx\DefaultLineSpacingReader;
use DOMDocument;
use PHPUnit\Framework\TestCase;

class DefaultLineSpacingReaderTest extends TestCase
{
    private function styles(string $inner): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->loadXML(
            '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .$inner.'</w:styles>'
        );

        return $dom;
    }

    public function test_reads_multiplier_from_default_paragraph_style(): void
    {
        $dom = $this->styles(
            '<w:style w:type="paragraph" w:default="1"><w:pPr>'
            .'<w:spacing w:line="360" w:lineRule="auto"/></w:pPr></w:style>'
        );

        $this->assertSame(1.5, (new DefaultLineSpacingReader)->multiplier($dom));
    }

    public function test_falls_back_to_doc_defaults(): void
    {
        $dom = $this->styles(
            '<w:docDefaults><w:pPrDefault><w:pPr>'
            .'<w:spacing w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
        );

        $this->assertSame(1.0, (new DefaultLineSpacingReader)->multiplier($dom));
    }

    public function test_null_when_no_spacing_or_fixed_rule(): void
    {
        $this->assertNull((new DefaultLineSpacingReader)->multiplier($this->styles('')));
        $this->assertNull((new DefaultLineSpacingReader)->multiplier(null));

        $exact = $this->styles(
            '<w:docDefaults><w:pPrDefault><w:pPr>'
            .'<w:spacing w:line="480" w:lineRule="exact"/></w:pPr></w:pPrDefault></w:docDefaults>'
        );
        $this->assertNull((new DefaultLineSpacingReader)->multiplier($exact));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=DefaultLineSpacingReaderTest`
Expected: FAIL — class `DefaultLineSpacingReader` does not exist.

- [ ] **Step 4: Implement the reader**

Create `apps/app-laravel/app/Services/Fast/Docx/DefaultLineSpacingReader.php`:

```php
<?php

namespace App\Services\Fast\Docx;

use DOMDocument;
use DOMElement;

/**
 * Reads the DOCX's default line spacing (the value inherited by paragraphs that
 * carry no direct w:spacing) and returns it as a unitless line-height multiplier,
 * or null when the document specifies none or uses a fixed rule we don't model.
 */
final class DefaultLineSpacingReader
{
    public function multiplier(?DOMDocument $stylesXml): ?float
    {
        if ($stylesXml === null) {
            return null;
        }

        $xpath = WordXml::createXPath($stylesXml);

        // Prefer the default paragraph style; fall back to document defaults.
        $spacing = $xpath->query('//w:style[@w:type="paragraph" and @w:default="1"]/w:pPr/w:spacing')?->item(0);
        if (! $spacing instanceof DOMElement) {
            $spacing = $xpath->query('/w:styles/w:docDefaults/w:pPrDefault/w:pPr/w:spacing')?->item(0);
        }
        if (! $spacing instanceof DOMElement) {
            return null;
        }

        $line = WordXml::parseIntAttr($spacing, 'line');
        if ($line === null || $line <= 0) {
            return null;
        }

        // Only 'auto' (or unspecified) maps to a clean multiple of single spacing.
        // 'exact'/'atLeast' are fixed twip heights we don't convert here.
        $rule = WordXml::wordAttr($spacing, 'lineRule');
        if ($rule === 'exact' || $rule === 'atLeast') {
            return null;
        }

        return round($line / 240, 3);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T laravel-app php artisan test --filter=DefaultLineSpacingReaderTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/app/Services/Fast/Docx/DefaultLineSpacingReader.php apps/app-laravel/app/Services/Fast/Docx/DocxArchive.php apps/app-laravel/tests/Unit/DefaultLineSpacingReaderTest.php
git commit -m "feat(fast): read default line spacing from DOCX styles.xml"
```

---

### Task 2: Thread the default into the extractor's compose_state

**Files:**
- Modify: `apps/app-laravel/app/Services/Fast/FastDocxExtractor.php`
- Test: `apps/app-laravel/tests/Unit/FastDocxExtractorTest.php` (plain PHPUnit; PhpWord-built temp DOCX, matching the existing `buildTestDocx` convention)

- [ ] **Step 1: Write the failing test**

`FastDocxExtractorTest` extends plain `PHPUnit\Framework\TestCase` and builds a DOCX in a temp path via PhpWord (no Laravel helpers). Add a test that builds a DOCX with a known **document default** line spacing via `setDefaultParagraphStyle(['lineHeight' => 1.5])` (PhpWord writes this into `styles.xml` `w:docDefaults`), then asserts the extractor threads the reader's value through. Add these imports at the top of the file if missing (`use App\Services\Fast\Docx\DefaultLineSpacingReader; use App\Services\Fast\Docx\DocxArchive; use PhpOffice\PhpWord\IOFactory; use PhpOffice\PhpWord\PhpWord;`), then add:

```php
    public function test_extract_threads_document_default_line_height(): void
    {
        $path = sys_get_temp_dir().'/fast-docx-lh-'.uniqid('', true).'.docx';
        $phpWord = new PhpWord;
        $phpWord->setDefaultParagraphStyle(['lineHeight' => 1.5]);
        $section = $phpWord->addSection();
        $section->addText('ทดสอบระยะบรรทัด');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        try {
            // Ground truth: what the reader sees for this exact file.
            $expected = (new DefaultLineSpacingReader)->multiplier((new DocxArchive($path))->stylesXml());
            $this->assertNotNull($expected, 'PhpWord should emit a docDefaults line spacing for lineHeight 1.5');

            $output = (new FastDocxExtractor)->extract($path, 'doc-lh');
            $this->assertSame($expected, $output['compose_state']['line_height'] ?? null);
        } finally {
            @unlink($path);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=test_extract_threads_document_default_line_height`
Expected: FAIL — extractor output has no `compose_state.line_height` key (returns `null`).

- [ ] **Step 3: Set compose_state.line_height in the returned document**

In `FastDocxExtractor::extract`, after `$numberingResolver = ...` (line 35) add:

```php
        $defaultLineHeight = (new \App\Services\Fast\Docx\DefaultLineSpacingReader)->multiplier($archive->stylesXml());
```

Then in the returned array (after `'language' => 'th',`, line 101) add:

```php
            'compose_state' => $defaultLineHeight !== null ? ['line_height' => $defaultLineHeight] : [],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T laravel-app php artisan test --filter=test_extract_threads_document_default_line_height`
Expected: PASS. (If PhpWord emits the default spacing in a form the reader doesn't match, the `assertNotNull` on `$expected` fails first — surfacing the dependency rather than a false pass.)

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/app/Services/Fast/FastDocxExtractor.php apps/app-laravel/tests/Unit/FastDocxExtractorTest.php
git commit -m "feat(fast): expose DOCX default line height as compose_state.line_height"
```

---

### Task 3: Preserve line_height through ReviewStore compose-state defaults

**Files:**
- Modify: `apps/app-laravel/app/Services/ReviewStore.php` (`ensureComposeStateDefaults`, ~line 1227)
- Test: `apps/app-laravel/tests/Feature/ReviewComposeStateTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `apps/app-laravel/tests/Feature/ReviewComposeStateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class ReviewComposeStateTest extends TestCase
{
    public function test_compose_state_line_height_survives_sync(): void
    {
        $store = app(ReviewStore::class);
        $id = 'lh_'.uniqid();
        $store->setStatus($id, ['status' => 'done', 'source_file' => 'x.docx']);
        $store->writeReviewDocument($id, [
            'document_id' => $id, 'source_file' => 'x.docx', 'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'compose_state' => ['line_height' => 1.15],
            'law_meta' => ['title' => 'T'],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);

        $document = $store->getReviewDocument($id);

        $this->assertSame(1.15, (float) ($document['compose_state']['line_height'] ?? 0));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=test_compose_state_line_height_survives_sync`
Expected: FAIL — `line_height` key missing after sync (defaults don't include it).

- [ ] **Step 3: Add line_height to the compose-state defaults**

In `ensureComposeStateDefaults`, the first array passed to `array_merge` (the defaults, currently `'font_family' => 'sarabun', 'font_size_pt' => 16, 'page_margins' => ..., 'metadata' => []`) — add `'line_height' => null,`:

```php
        $document['compose_state'] = array_merge([
            'font_family' => 'sarabun',
            'font_size_pt' => 16,
            'line_height' => null,
            'page_margins' => $this->normalizePageMargins(),
            'metadata' => [],
        ], $compose, [
```

(The existing `$compose` still overrides, so a stored `line_height` wins; documents without one get `null`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T laravel-app php artisan test --filter=test_compose_state_line_height_survives_sync`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/app/Services/ReviewStore.php apps/app-laravel/tests/Feature/ReviewComposeStateTest.php
git commit -m "feat(review): keep compose_state.line_height through sync"
```

---

### Task 4: Export uses the document line height as the fallback

**Files:**
- Modify: `apps/app-laravel/app/Services/DocumentExportService.php`
- Test: `apps/app-laravel/tests/Unit/DocumentExportServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add to `DocumentExportServiceTest`:

```php
    public function test_document_line_height_is_the_paragraph_fallback(): void
    {
        // compose_state.line_height 1.15 -> w:line="276" (1.15 * 240).
        $document = [
            'compose_state' => ['line_height' => 1.15],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1', 'type' => 'paragraph', 'reading_order' => 1,
                    'approved_text' => 'ก', 'normalized_text' => 'ก',
                    'meta' => ['reviewed_html' => '<p>ก</p>', 'layout' => []],
                ]],
            ]],
        ];

        $xml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

        $this->assertStringContainsString('w:line="276"', $xml);
        $this->assertStringNotContainsString('w:line="444"', $xml); // not the 1.85 default
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=test_document_line_height_is_the_paragraph_fallback`
Expected: FAIL — still emits `w:line="444"` (hardcoded 1.85).

- [ ] **Step 3: Add a document-line-height helper**

In `DocumentExportService`, add near `blankParagraphStyle`:

```php
    private function documentLineHeight(array $document): float
    {
        $composeState = is_array($document['compose_state'] ?? null) ? $document['compose_state'] : [];
        $lineHeight = $composeState['line_height'] ?? null;

        return is_numeric($lineHeight) && (float) $lineHeight > 0
            ? (float) $lineHeight
            : self::DEFAULT_LINE_HEIGHT;
    }
```

- [ ] **Step 4: Thread it through buildDocxFile as the fallback**

In `buildDocxFile`, right after `$section = $phpWord->addSection([...]);` (about line 133), add:

```php
        $docLineHeight = $this->documentLineHeight($document);
```

Change the blank-node branch to pass it:

```php
            if (($node['type'] ?? null) === 'blank') {
                $blankRun = $section->addTextRun($this->blankParagraphStyle($docLineHeight));
                $blankRun->addText("\u{200B}", ['name' => self::EXPORT_FONT, 'size' => 16]);

                continue;
            }
```

Change both `paragraphStyleForBlock($block, $lineHeight)` call sites (the empty-runs branch and the normal branch) to `paragraphStyleForBlock($block, $lineHeight, $docLineHeight)`.

Update `blankParagraphStyle` to take the fallback:

```php
    private function blankParagraphStyle(float $lineHeight = self::DEFAULT_LINE_HEIGHT): array
    {
        return [
            'spaceAfter' => 0,
            'lineHeight' => $lineHeight,
        ];
    }
```

Update `paragraphStyleForBlock` signature + resolution so the document line height is the last fallback (replacing the bare `self::DEFAULT_LINE_HEIGHT`):

```php
    private function paragraphStyleForBlock(array $block, ?float $lineHeight = null, ?float $docLineHeight = null): array
    {
        $layout = is_array($block['meta']['layout'] ?? null) ? $block['meta']['layout'] : [];
        $isHeading = ($block['type'] ?? '') === 'section_header';
        $resolvedLineHeight = $lineHeight
            ?? $this->layoutLineHeight($layout)
            ?? $docLineHeight
            ?? self::DEFAULT_LINE_HEIGHT;
        $style = [
            'spaceAfter' => 0,
            'widowControl' => true,
            'lineHeight' => $resolvedLineHeight,
        ];
```

(Leave the rest of `paragraphStyleForBlock` unchanged.)

- [ ] **Step 5: Run the full export suite**

Run: `docker compose exec -T laravel-app php artisan test --filter=DocumentExportServiceTest`
Expected: PASS — including the new test and the existing line-spacing/blank-line tests (those documents have no `compose_state.line_height`, so they still fall back to 1.85 → `w:line="444"`).

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/app/Services/DocumentExportService.php apps/app-laravel/tests/Unit/DocumentExportServiceTest.php
git commit -m "feat(export): use document line height as paragraph fallback"
```

---

### Task 5: Editor renders the document line height (still editable)

**Files:**
- Modify: `apps/app-laravel/resources/js/types/document.ts` (compose_state type)
- Modify: `apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue`

- [ ] **Step 1: Add line_height to the ComposeState interface**

In `types/document.ts`, the `ComposeState` interface (around line 182) currently declares `font_family`, `font_size_pt`, `page_margins`, `metadata`. Add an optional field so existing documents without it still type-check:

```ts
export interface ComposeState {
  font_family: ThaiFont;
  font_size_pt: number;
  line_height?: number | null;
  page_margins: PageMargins;
  metadata: DocumentMetadata;
}
```

(`ReviewDocument.compose_state?: ComposeState` already exists, so `documentStore.review?.compose_state?.line_height` type-checks.)

- [ ] **Step 2: Drive the editor page's line-height from compose_state**

In `DocumentEditorShell.vue` `<script setup>`, near `pageMargins`, add a computed:

```ts
const docLineHeight = computed<number>(() => {
  const value = documentStore.review?.compose_state?.line_height;
  return typeof value === 'number' && value > 0 ? value : 1.85;
});
```

In `pageFrameStyle` (the computed returning the CSS custom properties), add the variable to the returned object:

```ts
    '--doc-line-height': `${docLineHeight.value}`,
```

- [ ] **Step 3: Use the variable in the ProseMirror CSS**

In `<style scoped>`, change the `.editor-shell-content :deep(.ProseMirror)` rule's `line-height: 1.85;` to:

```css
  line-height: var(--doc-line-height, 1.85);
```

- [ ] **Step 4: Typecheck + build**

Run (on host): `cd apps/app-laravel && npm run typecheck && npm run build`
Expected: both PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/resources/js/types/document.ts apps/app-laravel/resources/js/components/review/DocumentEditorShell.vue
git commit -m "feat(review): editor default line height from uploaded DOCX"
```

---

### Task 6: Verify end to end (blank lines, editable presets, source match)

**Files:** none (verification only)

- [ ] **Step 1: Re-upload a DOCX and check the editor**

Upload a DOCX known to be single-spaced (or 1.15). Open `/review`: paragraphs render at the source spacing, not the old looser 1.85.

- [ ] **Step 2: Blank/Enter lines**

Press Enter to add blank lines between paragraphs, save, export PDF: the blank lines appear in the PDF at the document line height (already-shipped behavior, now at the source spacing).

- [ ] **Step 3: Editable line height (Google-Docs-style)**

Use the "ระยะบรรทัด" toolbar select (presets 1.0 / 1.15 / 1.5 / 2.0) on a paragraph, save, export: that paragraph's chosen spacing wins over the document default in both the editor and the PDF.

- [ ] **Step 4: Commit (only if verification required tweaks)**

```bash
git add -A && git commit -m "fix(review): line-height polish after manual check"
```

---

## Self-Review

- **Spec coverage:** line height matches uploaded DOCX (Tasks 1-5), blank/Enter lines in PDF (already shipped; Task 6 verifies), editable like Google Docs (existing presets; Task 5 keeps per-paragraph override winning; Task 6 verifies). ✓
- **Placeholders:** none — concrete code, tests, and commands throughout. ✓
- **Type consistency:** `DefaultLineSpacingReader::multiplier()` returns `?float`, consumed in Task 2; `compose_state.line_height` written in Task 2, preserved in Task 3, read in Task 4 (`documentLineHeight`) and Task 5 (`docLineHeight`); `paragraphStyleForBlock(block, lineHeight, docLineHeight)` and `blankParagraphStyle(lineHeight)` signatures updated consistently at all call sites. ✓
- **Deferred, flagged:** per-paragraph `exact`/`atLeast` line rules; standard/Python path; custom numeric line-height input. ✓
- **Test conventions verified against the repo:** Unit tests (Tasks 1, 2, 4) extend plain `PHPUnit\Framework\TestCase`, build services with `new`, and use PhpWord-built temp DOCX fixtures — no `app()`/`base_path()`; the Feature test (Task 3) extends Laravel `Tests\TestCase` and uses `app()`. `WordXml::createXPath` registers the `w` namespace and `wordAttr`/`parseIntAttr` read `w:`-namespaced attributes, so the reader's XPath/attribute access is correct. `readDocxXml`/`makeService` helpers exist in `DocumentExportServiceTest`; `ComposeState` is a named interface with `ReviewDocument.compose_state?: ComposeState`. ✓
