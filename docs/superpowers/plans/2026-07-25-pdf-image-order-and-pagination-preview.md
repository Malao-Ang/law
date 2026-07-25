# PDF Image Order Fix + Pagination Preview — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** (Part A) Fix the bug where images render at the bottom/last page ("footer") of the exported PDF instead of in place. (Part B) Reconcile the "preview shows page gaps like separate pages" request with the already-approved pagination spec.

---

## Part A — Image-at-footer on PDF export (root-caused)

**Root cause (evidence-backed):**
- `ResizableImageExtension` is `inline: true` and renders its `blockId` as `data-block-id` **on the `<img>`** (`resources/js/extensions/ResizableImageExtension.ts:30-35`). So saved `draft_html` contains images as `<p><img data-block-id="imgX"></p>` — the id is on a **nested** node and the wrapping `<p>` has empty text.
- `DocumentExportService::orderedExportNodes()` was recently rewritten to walk **only top-level** `$root->childNodes` (`app/Services/DocumentExportService.php:390`). The wrapping `<p>` therefore matches the new **blank-line branch** (empty `textContent`), the image's `data-block-id` is never matched, and the image block falls through to the **end-of-document fallback append** → all images pile up after the text.
- Only reproduces when `draft_html` exists (i.e. after review), which is why `test_docx_embeds_image_blocks` (no `draft_html`) missed it.

**Fix:** when a top-level element has no `data-block-id` of its own, look for tracked block ids on its **descendants** (restoring the old any-depth match) before deciding it's a blank line; and never treat an element containing an `<img>` as a blank line.

**Files:**
- Modify: `apps/app-laravel/app/Services/DocumentExportService.php` (`orderedExportNodes`, the `foreach ($root->childNodes ...)` loop, lines ~390-410)
- Test: `apps/app-laravel/tests/Unit/DocumentExportServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add to `DocumentExportServiceTest`:

```php
public function test_inline_image_keeps_position_from_draft_html(): void
{
    $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    $document = [
        'pages' => [[
            'page_no' => 1,
            'blocks' => [
                [
                    'block_id' => 'img1',
                    'type' => 'image',
                    'reading_order' => 1,
                    'meta' => ['image' => ['data_uri' => $png, 'display_width_px' => 120]],
                ],
                [
                    'block_id' => 't2',
                    'type' => 'paragraph',
                    'reading_order' => 2,
                    'approved_text' => 'ท้ายเอกสาร',
                    'normalized_text' => 'ท้ายเอกสาร',
                    'meta' => ['reviewed_html' => '<p data-block-id="t2">ท้ายเอกสาร</p>', 'layout' => []],
                ],
            ],
        ]],
        // TipTap serializes an inline image as <img data-block-id> nested in a <p>.
        'document_review' => [
            'draft_html' => '<p><img data-block-id="img1"></p><p data-block-id="t2">ท้ายเอกสาร</p>',
        ],
    ];

    $documentXml = $this->readDocxXml($this->makeService()->toDocx($document), 'word/document.xml');

    $drawingPos = strpos($documentXml, '<w:drawing');
    $tailPos = strpos($documentXml, 'ท้ายเอกสาร');
    $this->assertNotFalse($drawingPos, 'expected an inline image drawing in the document');
    $this->assertNotFalse($tailPos, 'expected the trailing paragraph text in the document');
    // The image must come BEFORE the trailing text — not appended at the end.
    $this->assertLessThan($tailPos, $drawingPos, 'image must keep its in-flow position, not land at the end/footer');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel-app php artisan test --filter=test_inline_image_keeps_position_from_draft_html`
Expected: FAIL — `$drawingPos` is greater than `$tailPos` (image emitted at the end).

- [ ] **Step 3: Fix `orderedExportNodes`**

Replace the loop body (the block starting `foreach ($root->childNodes as $element) {` through its closing `}`, currently lines ~390-410) with:

```php
        foreach ($root->childNodes as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            if ($element->hasAttribute('data-page-break')) {
                $nodes[] = ['type' => 'page_break'];
                continue;
            }

            // A top-level element that carries its own id IS the block.
            $selfId = trim((string) $element->getAttribute('data-block-id'));
            if ($selfId !== '') {
                if (isset($blocksById[$selfId]) && ! isset($seenBlockIds[$selfId])) {
                    $seenBlockIds[$selfId] = true;
                    $nodes[] = ['type' => 'block', 'block' => $blocksById[$selfId]];
                }
                continue;
            }

            // No id on the wrapper: look for tracked ids on descendants. Inline
            // images serialize as <p><img data-block-id></p>, so the id is nested.
            $matched = [];
            foreach ($element->getElementsByTagName('*') as $descendant) {
                if (! $descendant instanceof DOMElement) {
                    continue;
                }
                $id = trim((string) $descendant->getAttribute('data-block-id'));
                if ($id !== '' && isset($blocksById[$id]) && ! isset($seenBlockIds[$id]) && ! in_array($id, $matched, true)) {
                    $matched[] = $id;
                }
            }
            if ($matched !== []) {
                foreach ($matched as $id) {
                    $seenBlockIds[$id] = true;
                    $nodes[] = ['type' => 'block', 'block' => $blocksById[$id]];
                }
                continue;
            }

            // Truly empty paragraph (no text, no media) → reviewer-inserted blank
            // line. An unmappable image (block deleted) is skipped, not blanked.
            if (trim($element->textContent) === '' && $element->getElementsByTagName('img')->length === 0) {
                $nodes[] = ['type' => 'blank'];
            }
        }
```

- [ ] **Step 4: Run the new test + the full export suite**

Run: `docker compose exec -T laravel-app php artisan test --filter=DocumentExportServiceTest`
Expected: PASS — including `test_inline_image_keeps_position_from_draft_html`, `test_docx_preserves_reviewer_inserted_blank_lines_from_draft_html`, `test_docx_respects_page_break_nodes_from_draft_html`, and `test_docx_embeds_image_blocks`.

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/app/Services/DocumentExportService.php apps/app-laravel/tests/Unit/DocumentExportServiceTest.php
git commit -m "fix(export): keep inline images in flow, not appended at end

orderedExportNodes only walked top-level draft_html children, so
inline images (<p><img data-block-id></p>) matched the blank-line
branch and their block fell through to the end-of-doc fallback.
Now match tracked ids on descendants and never blank an <img>."
```

---

## Part B — Preview shows page gaps like separate pages

This is **already designed** in `docs/superpowers/specs/2026-07-25-review-multi-page-pagination-design.md` (approved): stacked A4 sheets with gaps + page numbers on the `/review` editor, soft pagination (measure on idle, push whole blocks to the next sheet), plus manual page breaks.

**Accuracy caveat (answers "know the content → new page in PDF"):** the true PDF pagination is produced by LibreOffice, whose line-breaking will not land identically to the on-screen measured boundaries. Therefore:
- **Manual page breaks are exact** — the export honors `data-page-break` (and Part A's fix keeps that path intact), so a manual break on screen = a real new page in the PDF.
- **Auto/soft boundaries are an approximation** — they show roughly where pages fall; they are not guaranteed to match the PDF's exact break points.

**Next step for Part B:** generate the implementation plan from the approved pagination spec (via the writing-plans skill) when ready to build — it is not yet broken into tasks. No new design decisions are needed.

---

## Self-Review

- **Part A coverage:** root cause identified with file/line evidence; failing test added (Step 1) that fails on current code (Step 2) and passes after the fix (Steps 3-4); page-break and blank-line behaviors preserved and re-asserted (Step 4). ✓
- **Placeholders:** none. ✓
- **Part B:** correctly reconciled to the existing spec; accuracy limitation stated honestly. ✓
