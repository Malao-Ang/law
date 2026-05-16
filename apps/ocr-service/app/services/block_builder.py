from __future__ import annotations

from collections.abc import Callable

from app.services.ai_corrector import MockAICorrector
from app.utils.indent_detector import normalize_indent_for_css


TAB_FALLBACK_PT = 48.0


def build_document_output(
    document_id: str,
    source_file: str,
    source_type: str,
    pages: list[dict],
    normalizer: Callable[[str], dict],
    ai_corrector: MockAICorrector,
    enable_ai_correction: bool,
    review_threshold: float,
) -> dict:
    output_pages: list[dict] = []
    block_count = 0
    review_required = 0

    for page in pages:
        transformed_blocks: list[dict] = []
        page_no = int(page.get("page_no", 1))

        for index, block in enumerate(page.get("blocks", []), start=1):
            raw_text = str(block.get("raw_text", "")).strip()
            block_type = str(block.get("type", "paragraph"))
            source_meta = dict(block.get("meta") or {})
            source_layout = normalize_layout(source_meta.get("layout"), block.get("bbox"), block.get("reading_order", index))
            table = build_table_payload(block, raw_text, block_type)

            if raw_text == "" and table is None and block_type != "image":
                continue

            normalized = normalizer(raw_text) if raw_text != "" else {"text": "", "flags": []}
            confidence = float(block.get("confidence", 1.0))
            flags = list(set(block.get("flags", []) + normalized.get("flags", [])))

            needs_review = confidence < review_threshold or len(flags) > 0
            ai_text = normalized["text"]

            if enable_ai_correction and raw_text != "" and (needs_review or block_type in {"table", "section_header"}):
                ai_result = ai_corrector.suggest(normalized["text"])
                ai_text = ai_result["suggested_text"]
                confidence = min(confidence, ai_result["confidence"])
                flags = list(set(flags + ai_result["reason"]))

            approved_text = ai_text
            bbox = block.get("bbox")
            block_id = block.get("block_id", f"{page_no}-{index}")
            reviewed_html = build_reviewed_html(block_type, approved_text, source_layout, block_id, table, source_meta)
            if table is not None:
                reviewed_html = table["html"]

            transformed_blocks.append(
                {
                    "block_id": block_id,
                    "type": block_type,
                    "bbox": bbox,
                    "reading_order": int(block.get("reading_order", index)),
                    "raw_text": raw_text,
                    "normalized_text": normalized["text"],
                    "ai_suggested_text": ai_text,
                    "approved_text": approved_text,
                    "confidence": max(0.0, min(1.0, confidence)),
                    "needs_review": needs_review,
                    "flags": sorted(set(flags)),
                    "image_path": source_meta.get("image_path") if block_type == "image" else None,
                    "image_data_uri": source_meta.get("image_data_uri") if block_type == "image" else None,
                    "meta": {
                        **source_meta,
                        "section_path": source_meta.get("section_path"),
                        "table_html": table["html"] if table is not None else source_meta.get("table_html"),
                        "reviewed_html": reviewed_html,
                        "layout": source_layout,
                        "table": table,
                    },
                }
            )

            block_count += 1
            if needs_review:
                review_required += 1

        output_pages.append(
            {
                "page_no": page_no,
                "image_path": page.get("image_path"),
                "blocks": transformed_blocks,
            }
        )

    return {
        "document_id": document_id,
        "source_file": source_file,
        "source_type": source_type,
        "language": "th",
        "summary": {
            "page_count": len(output_pages),
            "block_count": block_count,
            "review_required_count": review_required,
        },
        "pages": output_pages,
    }


def build_html_preview(document_data: dict, format: str = "html", include_styles: bool = True) -> dict:
    """Generate HTML preview from document data."""
    html_parts = []
    css_parts = []
    
    # Add CSS styles
    if include_styles:
        css_lines = _generate_preview_css()
        css_parts.extend(css_lines)
    
    # Build HTML content
    html_parts.append('<!DOCTYPE html>')
    html_parts.append('<html lang="th">')
    html_parts.append('<head>')
    html_parts.append('<meta charset="UTF-8">')
    html_parts.append('<meta name="viewport" content="width=device-width, initial-scale=1.0">')
    html_parts.append(f'<title>{document_data.get("source_file", "Document")} - Preview</title>')
    
    if include_styles and css_parts:
        html_parts.append('<style>')
        html_parts.extend(css_parts)
        html_parts.append('</style>')
    
    html_parts.append('</head>')
    html_parts.append('<body>')
    html_parts.append('<div class="document-preview">')
    html_parts.append(f'<h1 class="document-title">{document_data.get("source_file", "Document")}</h1>')
    
    # Add document metadata
    if document_data.get("summary"):
        summary = document_data["summary"]
        html_parts.append('<div class="document-summary">')
        html_parts.append(f'<p>Pages: {summary.get("page_count", 0)} | Blocks: {summary.get("block_count", 0)} | Review Required: {summary.get("review_required_count", 0)}</p>')
        html_parts.append('</div>')
    
    # Add page content
    for page in document_data.get("pages", []):
        page_no = page.get("page_no", 1)
        html_parts.append(f'<div class="page" data-page="{page_no}">')
        html_parts.append(f'<h2 class="page-header">Page {page_no}</h2>')
        
        for block in page.get("blocks", []):
            block_html = block.get("meta", {}).get("reviewed_html", "")
            if block_html:
                html_parts.append(block_html)
        
        html_parts.append('</div>')
    
    html_parts.append('</div>')
    html_parts.append('</body>')
    html_parts.append('</html>')
    
    html_content = "\n".join(html_parts)
    css_content = "\n".join(css_parts) if css_parts else None
    
    return {
        "document_id": document_data.get("document_id", ""),
        "format": format,
        "html": html_content,
        "css": css_content,
        "metadata": {
            "source_file": document_data.get("source_file"),
            "source_type": document_data.get("source_type"),
            "summary": document_data.get("summary")
        }
    }


def _generate_preview_css() -> list[str]:
    """Generate CSS styles for HTML preview."""
    return [
        "/* Document Preview Styles */",
        "body {",
        "  font-family: 'Sarabun', 'Tahoma', sans-serif;",
        "  line-height: 1.6;",
        "  color: #333;",
        "  margin: 0;",
        "  padding: 20px;",
        "  background-color: #f5f5f5;",
        "}",
        "",
        ".document-preview {",
        "  max-width: 800px;",
        "  margin: 0 auto;",
        "  background: white;",
        "  padding: 40px;",
        "  border-radius: 8px;",
        "  box-shadow: 0 2px 10px rgba(0,0,0,0.1);",
        "}",
        "",
        ".document-title {",
        "  text-align: center;",
        "  color: #2c3e50;",
        "  margin-bottom: 30px;",
        "  font-size: 28px;",
        "}",
        "",
        ".document-summary {",
        "  background: #ecf0f1;",
        "  padding: 15px;",
        "  border-radius: 4px;",
        "  margin-bottom: 30px;",
        "  text-align: center;",
        "}",
        "",
        ".page {",
        "  margin-bottom: 40px;",
        "  border-bottom: 2px solid #3498db;",
        "  padding-bottom: 30px;",
        "}",
        "",
        ".page-header {",
        "  color: #3498db;",
        "  border-bottom: 1px solid #bdc3c7;",
        "  padding-bottom: 10px;",
        "  margin-bottom: 20px;",
        "}",
        "",
        "/* Block Styles */",
        ".doc-paragraph {",
        "  margin-bottom: 12px;",
        "  text-align: justify;",
        "}",
        "",
        ".doc-title {",
        "  font-size: 24px;",
        "  font-weight: bold;",
        "  text-align: center;",
        "  margin: 20px 0;",
        "  color: #2c3e50;",
        "}",
        "",
        ".doc-section-header {",
        "  font-size: 18px;",
        "  font-weight: bold;",
        "  margin: 15px 0 10px 0;",
        "  color: #34495e;",
        "}",
        "",
        ".doc-list-item {",
        "  margin-left: 20px;",
        "  margin-bottom: 8px;",
        "  position: relative;",
        "}",
        "",
        ".doc-list-item::before {",
        "  content: '•';",
        "  position: absolute;",
        "  left: -15px;",
        "  color: #3498db;",
        "}",
        "",
        ".doc-figure-caption {",
        "  font-style: italic;",
        "  text-align: center;",
        "  margin: 10px 0;",
        "  font-size: 14px;",
        "  color: #7f8c8d;",
        "}",
        "",
        ".doc-footnote {",
        "  font-size: 12px;",
        "  vertical-align: super;",
        "  color: #95a5a6;",
        "}",
        "",
        "/* Table Styles */",
        "table {",
        "  width: 100%;",
        "  border-collapse: collapse;",
        "  margin: 20px 0;",
        "  font-size: 14px;",
        "}",
        "",
        "th, td {",
        "  border: 1px solid #bdc3c7;",
        "  padding: 8px 12px;",
        "  text-align: left;",
        "}",
        "",
        "th {",
        "  background-color: #3498db;",
        "  color: white;",
        "  font-weight: bold;",
        "}",
        "",
        "tr:nth-child(even) {",
        "  background-color: #f8f9fa;",
        "}",
        "",
        "/* Image Styles */",
        ".doc-image {",
        "  text-align: center;",
        "  margin: 20px 0;",
        "}",
        "",
        ".doc-image img {",
        "  max-width: 100%;",
        "  height: auto;",
        "  border-radius: 4px;",
        "  box-shadow: 0 2px 5px rgba(0,0,0,0.1);",
        "}",
        "",
        ".doc-image--missing {",
        "  border: 2px dashed #e74c3c;",
        "  padding: 20px;",
        "  text-align: center;",
        "  color: #e74c3c;",
        "  background-color: #fadbd8;",
        "}",
        "",
        "/* Tab styles */",
        ".doc-tab {",
        "  display: inline-block;",
        "}",
        "",
        "/* Responsive */",
        "@media (max-width: 768px) {",
        "  .document-preview {",
        "    padding: 20px;",
        "    margin: 10px;",
        "  }",
        "  ",
        "  .document-title {",
        "    font-size: 24px;",
        "  }",
        "  ",
        "  .doc-title {",
        "    font-size: 20px;",
        "  }",
        "  ",
        "  table {",
        "    font-size: 12px;",
        "  }",
        "  ",
        "  th, td {",
        "    padding: 6px 8px;",
        "  }",
        "}",
    ]


def normalize_layout(layout: object, bbox: object, reading_order: object) -> dict:
    source = layout if isinstance(layout, dict) else {}
    tabs: list[dict[str, int | str]] = []
    for tab in source.get("tabs", []) if isinstance(source.get("tabs"), list) else []:
        if not isinstance(tab, dict):
            continue
        position = tab.get("position")
        if position is None:
            continue
        tabs.append(
            {
                "align": str(tab.get("align") or "left"),
                "position": int(position),
            }
        )

    return {
        "bbox": bbox if isinstance(bbox, list) or bbox is None else source.get("bbox"),
        "reading_order": int(source.get("reading_order") or reading_order or 0),
        "alignment": source.get("alignment"),
        "indent_left": to_int_or_none(source.get("indent_left")),
        "indent_first_line": to_int_or_none(source.get("indent_first_line")),
        "indent_hanging": to_int_or_none(source.get("indent_hanging")),
        "tabs": tabs,
    }


def build_reviewed_html(block_type: str, text: str, layout: dict, block_id: str, table: dict | None = None, source_meta: dict | None = None) -> str:
    if block_type == "table" and table is not None:
        html = str(table["html"])
        return html.replace("<table", f'<table data-block-id="{block_id}"', 1)

    if block_type == "image":
        source_meta = source_meta or {}
        data_uri  = source_meta.get("image_data_uri")
        img_path  = source_meta.get("image_path", "")
        img_src   = data_uri or img_path or ""
        if img_src:
            return (
                f'<figure data-block-id="{block_id}" class="doc-image" style="text-align: center; margin: 1rem 0;">'
                f'<img src="{img_src}" alt="embedded image" style="max-width:100%; height:auto; display: block; margin: 0 auto;"/>'
                f'</figure>'
            )
        return f'<figure data-block-id="{block_id}" class="doc-image doc-image--missing"></figure>'

    tag = "p"

    classes = ["doc-paragraph"]
    if block_type == "list_item":
        classes.append("doc-list-item")
    elif block_type == "title":
        classes.append("doc-title")
    elif block_type == "section_header":
        classes.append("doc-section-header")
    elif block_type == "figure_caption":
        classes.append("doc-figure-caption")
    elif block_type == "footnote":
        classes.append("doc-footnote")

    style = build_layout_style(layout)
    text_html = render_text_with_layout(text, layout)
    class_attr = f' class="{" ".join(classes)}"' if classes else ""
    style_attr = f' style="{style}"' if style else ""
    id_attr = f' data-block-id="{block_id}"'

    return f"<{tag}{id_attr}{class_attr}{style_attr}>{text_html}</{tag}>"


def build_table_payload(block: dict, raw_text: str, block_type: str) -> dict | None:
    if block_type != "table":
        return None

    source_meta = block.get("meta") if isinstance(block.get("meta"), dict) else {}
    source_table = source_meta.get("table") if isinstance(source_meta.get("table"), dict) else None

    if isinstance(source_table, dict):
        cells = normalize_table_cells(source_table.get("cells") or source_table.get("grid"))
        if cells:
            headers = normalize_string_row(source_table.get("headers")) or flatten_table_row(cells[0])
            rows = normalize_table_rows(source_table.get("rows")) or [flatten_table_row(row) for row in cells[1:]]
            html = build_table_html(cells)
            return {
                "headers": headers,
                "rows": rows,
                "cells": cells,
                "html": html,
            }

    rows = [row.strip() for row in raw_text.splitlines() if row.strip()]
    parsed_rows = [[cell.strip() for cell in row.split("\t") if cell.strip()] for row in rows]
    parsed_rows = [row for row in parsed_rows if row]
    if not parsed_rows:
        return None

    headers = parsed_rows[0]
    body = parsed_rows[1:] if len(parsed_rows) > 1 else []
    cells = [
        [
            {
                "text": cell,
                "colspan": 1,
                "rowspan": 1,
                "alignment": None,
            }
            for cell in row
        ]
        for row in parsed_rows
    ]

    return {
        "headers": headers,
        "rows": body,
        "cells": cells,
        "html": build_table_html(cells),
    }


def normalize_table_cells(value: object) -> list[list[dict]]:
    if not isinstance(value, list):
        return []

    rows: list[list[dict]] = []
    for row in value:
        if not isinstance(row, list):
            continue
        normalized_row: list[dict] = []
        for cell in row:
            if not isinstance(cell, dict):
                continue
            normalized_row.append(
                {
                    "text": str(cell.get("text") or ""),
                    "colspan": max(1, int(cell.get("colspan") or 1)),
                    "rowspan": max(1, int(cell.get("rowspan") or 1)),
                    "alignment": cell.get("alignment"),
                }
            )
        if normalized_row:
            rows.append(normalized_row)

    return rows


def normalize_string_row(value: object) -> list[str]:
    if not isinstance(value, list):
        return []
    return [str(item) for item in value]


def normalize_table_rows(value: object) -> list[list[str]]:
    if not isinstance(value, list):
        return []
    rows: list[list[str]] = []
    for row in value:
        if not isinstance(row, list):
            continue
        rows.append([str(item) for item in row])
    return rows


def flatten_table_row(row: list[dict]) -> list[str]:
    return [str(cell.get("text") or "") for cell in row]


def build_table_html(rows: list[list[dict]]) -> str:
    html_rows: list[str] = []

    for row_index, row in enumerate(rows):
        rendered_cells: list[str] = []
        cell_tag = "th" if row_index == 0 else "td"
        for cell in row:
            attrs: list[str] = []
            colspan = max(1, int(cell.get("colspan") or 1))
            rowspan = max(1, int(cell.get("rowspan") or 1))
            alignment = cell.get("alignment")
            if colspan > 1:
                attrs.append(f' colspan="{colspan}"')
            if rowspan > 1:
                attrs.append(f' rowspan="{rowspan}"')
            if alignment:
                attrs.append(f' style="text-align:{escape_html(str(alignment))};"')
            text = escape_html(str(cell.get("text") or "")).replace("\n", "<br>")
            rendered_cells.append(f'<{cell_tag}{"".join(attrs)}>{text}</{cell_tag}>')
        html_rows.append("<tr>" + "".join(rendered_cells) + "</tr>")

    return "<table><tbody>" + "".join(html_rows) + "</tbody></table>"


def render_text_with_layout(text: str, layout: dict) -> str:
    if "\t" not in text:
        return escape_html(text).replace("\n", "<br>")

    tab_positions = [
        max(float(tab.get("position") or 0) / 20.0, TAB_FALLBACK_PT)
        for tab in layout.get("tabs", [])
        if isinstance(tab, dict)
    ]
    segments = text.split("\t")
    rendered: list[str] = []

    for index, segment in enumerate(segments):
        rendered.append(escape_html(segment).replace("\n", "<br>"))
        if index >= len(segments) - 1:
            continue
        width = tab_positions[index] if index < len(tab_positions) else TAB_FALLBACK_PT
        rendered.append(f'<span class="doc-tab" style="display:inline-block; width:{width:.1f}pt;"></span>')

    return "".join(rendered)


def build_layout_style(layout: dict) -> str:
    """Build CSS style string from layout information, supporting indent levels."""
    styles: list[str] = []
    alignment = layout.get("alignment")
    if alignment:
        styles.append(f"text-align:{alignment}")

    # Support both DOCX-style indent points and new indent_level
    indent_level = layout.get("indent_level", 0)
    indent_left = to_int_or_none(layout.get("indent_left"))
    indent_first_line = to_int_or_none(layout.get("indent_first_line"))
    indent_hanging = to_int_or_none(layout.get("indent_hanging"))

    # Priority: indent_level > indent_left > other indent settings
    if indent_level > 0:
        # Use CSS-friendly indent based on level
        css_indent = normalize_indent_for_css(indent_level)
        styles.append(f"margin-left:{css_indent}")
    elif indent_left is not None:
        # Convert DOCX twips (1/20 point) to points
        left_margin_pt = indent_left / 20.0
        styles.append(f"margin-left:{left_margin_pt:.1f}pt")
        
        # Handle hanging indent properly
        if indent_hanging is not None and indent_hanging > 0:
            # Hanging indent: first line is indented less than subsequent lines
            # text-indent: negative value pulls first line back
            hanging_pt = indent_hanging / 20.0
            styles.append(f"text-indent:-{hanging_pt:.1f}pt")
        elif indent_first_line is not None and indent_first_line > 0:
            # First line indent: first line indented more than subsequent lines
            first_line_pt = indent_first_line / 20.0
            styles.append(f"text-indent:{first_line_pt:.1f}pt")
    elif indent_first_line is not None:
        # First line indent without left margin
        first_line_pt = indent_first_line / 20.0
        styles.append(f"text-indent:{first_line_pt:.1f}pt")
    elif indent_hanging is not None:
        # Hanging indent without left margin
        hanging_pt = indent_hanging / 20.0
        styles.append(f"text-indent:-{hanging_pt:.1f}pt")

    return "; ".join(styles)


def to_int_or_none(value: object) -> int | None:
    if value is None or value == "":
        return None
    return int(value)


def escape_html(value: str) -> str:
    return (
        value.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
        .replace("'", "&#39;")
    )