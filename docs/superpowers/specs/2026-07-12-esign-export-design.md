# eSign Export — PDF + DOCX Design

**Goal:** Add two export buttons on the Result page (PDF and DOCX) that produce A4 documents matching 100% of the formatting the user set in the review editor — font family/size, bold/italic/underline, heading levels, paragraph indents, tab stops, and tables.

**Architecture:** A new `pdf-service` Node.js/Puppeteer container handles HTML→PDF conversion. The existing `WordExportController` is enhanced to parse `reviewed_html` + `meta.layout` per block. A shared `DocumentExportService` builds the HTML template used by both the PDF path and as the basis for the DOCX parser. The `ResultPage` UI gets two independent export buttons.

**Tech Stack:** PHP/PhpWord (DOCX), Node 20 + Puppeteer (PDF), Laravel HTTP client (pdf-service calls), DOMDocument (reviewed_html parsing).

---

## Formatting source of truth

All formatting comes from what the user set in the TipTap review editor. Nothing is hardcoded.

| Property | Source |
|---|---|
| Font family | `<span style="font-family: ...">` in `meta.reviewed_html` |
| Font size | `<span style="font-size: ...">` in `meta.reviewed_html` |
| Bold / italic / underline | `<strong>`, `<em>`, `<u>` tags in `meta.reviewed_html` |
| Heading level | `<h1>`, `<h2>`, `<h3>` tags in `meta.reviewed_html` |
| Paragraph alignment | `meta.layout.alignment` (`left`/`center`/`right`/`justify`) |
| Left indent | `meta.layout.indent_left` (twips) |
| First-line indent | `meta.layout.indent_first_line` (twips) |
| Hanging indent | `meta.layout.indent_hanging` (twips) |
| Tab stops | `meta.layout.tabs[]` (`{position: number, type: string}`) |
| Table structure | `meta.table.cells[][]` (`{text, colspan, rowspan, alignment}`) |

**Fallback chain per block:** `meta.reviewed_html` → `approved_text` → `normalized_text` → `raw_text` (plain, no formatting). If a block is a table (`block.type === 'table'`), use `meta.table` regardless of `reviewed_html`.

**Page setup (both formats):**
- Paper: A4
- Margins: Word default — top 2.54 cm, bottom 2.54 cm, left 3.17 cm, right 3.17 cm
- No override CSS or forced font/size — the user's choices from the editor are authoritative

---

## Components

### 1. `pdf-service` (new Docker container)

**Location:** `apps/pdf-service/`

**Files:**
- `Dockerfile` — `node:20-slim`, installs Puppeteer (includes Chromium)
- `index.js` — Express server on port 3001, single route `POST /render`
- `package.json` — deps: `express`, `puppeteer`

**Contract:**
```
POST /render
Body: { html: string }          // complete self-contained HTML document
Response: application/pdf       // PDF bytes
Status 200 on success, 500 on render error
```

**Puppeteer settings:**
```js
await page.pdf({
  format: 'A4',
  margin: { top: '2.54cm', bottom: '2.54cm', left: '3.17cm', right: '3.17cm' },
  printBackground: true,
})
```

### 2. `DocumentExportService` (new PHP class)

**Location:** `app/Services/DocumentExportService.php`

**Responsibilities:**
- `buildHtml(array $document): string` — assembles a self-contained HTML document from all block `reviewed_html` fragments, wrapping them in minimal page CSS (A4 dimensions, Word-default margins). No font or size overrides in the CSS.
- `toPdf(array $document): string` — calls `buildHtml()`, POSTs to `PDF_SERVICE_URL/render`, returns raw PDF bytes.
- `toDocx(array $document): string` — iterates blocks, calls `parseHtmlRuns()` per block, builds PhpWord document, returns raw DOCX bytes.
- `parseHtmlRuns(string $html): array` — DOMDocument walk of a single block's `reviewed_html`; returns array of `{text, bold, italic, underline, fontFamily, fontSize}` run objects.

**HTML template for PDF (`buildHtml`):**
```html
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { size: A4; margin: 2.54cm 3.17cm; }
  body { margin: 0; padding: 0; }
  * { box-sizing: border-box; }
</style>
</head>
<body>
  <!-- one <div class="block"> per block, containing its reviewed_html verbatim -->
</body>
</html>
```

No font-family, font-size, or color is set globally in `<style>` — the inline styles from TipTap's `reviewed_html` are the sole source of visual formatting.

### 3. Enhanced `WordExportController`

**Location:** `app/Http/Controllers/Api/WordExportController.php` (rewrite existing)

**Route:** `POST /api/documents/{id}/export-word` (existing route, same URL)

**Per-block DOCX logic:**

```
if block.type === 'table' AND meta.table.cells exists:
    → PhpWord Table with cells[], colspan/rowspan, cell alignment
else:
    runs = DocumentExportService::parseHtmlRuns(meta.reviewed_html OR fallback plain text)
    paragraphStyle = {
        alignment: layout.alignment → Jc::BOTH/LEFT/CENTER/RIGHT,
        indentation: {
            left: layout.indent_left,
            firstLine: layout.indent_first_line,
            hanging: layout.indent_hanging,
        },
        tabs: layout.tabs[] → PhpWord Tab objects,
        spaceAfter: 0,
    }
    textRun = { name: run.fontFamily, size: run.fontSize (pt→halfpt), bold, italic, underline }
    section.addText(run.text, textRun, paragraphStyle) per run
```

**Heading detection:** if `reviewed_html` starts with `<h1>`, `<h2>`, `<h3>` — apply the heading's inner bold/size/alignment as found in the HTML (no override).

**Font size conversion:** TipTap stores sizes as `"16pt"` or `"16px"`. PhpWord takes half-points (so 16pt → 32). Convert pt directly; convert px by ×0.75 to pt then ×2.

### 4. New `PdfExportController`

**Location:** `app/Http/Controllers/Api/PdfExportController.php`

**Route:** `POST /api/documents/{id}/export-pdf`

```php
public function store(string $documentId): Response
{
    $document = $this->reviewStore->getReviewDocument($documentId);
    $pdfBytes = $this->exportService->toPdf($document);

    $this->reviewStore->setStatus($documentId, [
        'esign_exported_at' => now()->toIso8601String(),
    ]);

    return response($pdfBytes, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="' . $safeName . '.pdf"',
    ]);
}
```

### 5. `ResultPage.vue` UI changes

Replace current single export button section with:

```html
<!-- Not yet exported -->
<template v-else>
  <div class="d-flex flex-wrap ga-3">
    <v-btn color="admin-primary" prepend-icon="mdi-file-pdf-box"
      :loading="exportingPdf" @click="handlePdfExport">
      Export PDF for e-Sign
    </v-btn>
    <v-btn variant="outlined" prepend-icon="mdi-microsoft-word"
      :loading="exportingDocx" @click="handleWordExport">
      Export Word for e-Sign
    </v-btn>
  </div>
</template>

<!-- Exported, awaiting confirm — show both re-export buttons -->
<template v-else-if="esignExportedAt">
  ...existing confirm button + both re-export buttons...
</template>
```

Two independent `loading` refs: `exportingPdf` and `exportingDocx`. Either successful export sets `esign_exported_at` and re-fetches status.

---

## Docker changes

**`docker-compose.yml`:**
```yaml
pdf-service:
  build:
    context: apps/pdf-service
  ports:
    - "3001:3001"
  restart: unless-stopped

laravel-app:
  environment:
    - PDF_SERVICE_URL=http://pdf-service:3001

queue-worker:
  environment:
    - PDF_SERVICE_URL=http://pdf-service:3001
```

**`apps/pdf-service/Dockerfile`:**
```dockerfile
FROM node:20-slim
RUN apt-get update && apt-get install -y \
    chromium fonts-thai-tlwg \
    --no-install-recommends && rm -rf /var/lib/apt/lists/*
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev
COPY index.js ./
EXPOSE 3001
CMD ["node", "index.js"]
```

The `fonts-thai-tlwg` package installs Thai fonts system-wide so Chromium renders Thai text in the PDF. If the user's document uses a font not in `tlwg`, the system's fallback renders it — this is acceptable for a PDF export.

---

## API routes

| Method | Path | Controller | Auth |
|---|---|---|---|
| `POST` | `/api/documents/{id}/export-word` | `WordExportController@store` | existing |
| `POST` | `/api/documents/{id}/export-pdf` | `PdfExportController@store` | new |

---

## Error handling

- **`pdf-service` down:** `DocumentExportService::toPdf()` catches `ConnectionException`, throws `RuntimeException("PDF service unavailable")`. `PdfExportController` catches and returns 503. UI shows error alert under the button.
- **Empty `reviewed_html`:** Falls back to plain text (no runs, no formatting) — block still appears in the export.
- **Table with no `meta.table`:** Treated as a paragraph using fallback text.
- **Font not available in PhpWord/Word:** Word substitutes silently. The `name` field in DOCX XML declares the requested font; the reader machine decides rendering.

---

## Out of scope

- Page headers/footers (law title, page numbers) — add later
- Digital signature embedding in PDF — external eSign platform handles this
- Image blocks in PDF/DOCX — images already have `data_uri` in `meta.image`; future task
- Password protection or DRM on the output files
