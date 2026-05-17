# Golden Fixtures

This directory holds the expected extraction outputs for accuracy regression tests.

Each `.golden.json` file is a full `DocumentOutput` (matching `schemas/document-output.schema.json`)
that was manually verified to be correct for the corresponding sample document.

## Files

| File | Source document | Coverage |
|---|---|---|
| `prakat_1.pdf.golden.json` | `ประกาศ (1).pdf` (text PDF) | Thai paragraph text, section headers |
| `prakat_3.docx.golden.json` | `ประกาศ (3).docx` | DOCX tables, numbered lists, images |
| `prakat_scan.pdf.golden.json` | `ประกาศ (1).pdf` rasterised | OCR accuracy, scan flags |
| `multi_level_list.docx.golden.json` | synthetic | `๑.` / `๑.๑` / `(๑)` / `(ก)` numbering |
| `table_with_merges.docx.golden.json` | synthetic | colspan / rowspan propagation |

## Regenerating

```bash
docker compose exec ocr-service python scripts/regenerate-goldens.py
```

Then hand-edit the generated files as needed before committing.
