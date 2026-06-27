from __future__ import annotations

import re
from collections.abc import Callable

from app.services.ai_corrector import MockAICorrector
from app.services.html_renderer import build_table_html
from app.services.layout_inferrer import infer_indent_levels
from app.services.numbering_tokenizer import annotate_blocks as annotate_list_markers
from app.services.semantic_indent_resolver import resolve_semantic_indents
from app.services.thai_spellchecker import get_spell_checker


TAB_FALLBACK_PT = 18.0
_MARKER_ONLY_PATTERNS = (
    re.compile(r"^(?:มาตรา|ข้อ|วรรค)\s+[0-9๐-๙]+$"),
    re.compile(r"^\((?:[ก-ฮ]|[0-9๐-๙]+)\)$"),
    re.compile(r"^(?:[0-9๐-๙]+\.|[0-9๐-๙]+(?:\.[0-9๐-๙]+)+)$"),
)


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
    prepared_pages: list[dict] = []
    spellcheck_inputs: list[str] = []
    spellcheck_targets: list[dict] = []
    output_pages: list[dict] = []
    block_count = 0
    review_required = 0

    # Document-wide passes:
    # 1. Infer indent levels by clustering left-edge x positions across all
    #    pages so the same nesting depth maps to the same level everywhere.
    # 2. Detect leading list markers (มาตรา / ข้อ / (ก) / ๑.๑ / bullets) so the
    #    block model carries structured numbering even when DOCX `w:numPr` was
    #    not used (legal docs often type markers as literal text).
    infer_indent_levels(pages)
    for page in pages:
        annotate_list_markers(page.get("blocks", []) or [])
    # 3. Apply semantic Thai legal indent conventions (มาตรา/ข้อ/paren hierarchy,
    #    continuation text, divider→โดย rule).  Overwrites indent_level from the
    #    geometry pass where marker rules are stronger.
    resolve_semantic_indents(pages)

    for page in pages:
        prepared_blocks: list[dict] = []
        page_no = int(page.get("page_no", 1))

        for index, block in enumerate(page.get("blocks", []), start=1):
            raw_text = str(block.get("raw_text", "")).strip()
            block_type = str(block.get("type", "paragraph"))
            source_meta = dict(block.get("meta") or {})
            source_layout = normalize_layout(source_meta.get("layout"), block.get("bbox"), block.get("reading_order", index))
            table = build_table_payload(block, raw_text, block_type)

            if raw_text == "" and table is None and block_type != "image":
                continue

            normalized = _normalize_block_text(raw_text, normalizer)
            confidence = float(block.get("confidence", 1.0))
            flags = list(set(block.get("flags", []) + normalized.get("flags", [])))

            needs_review = confidence < review_threshold or len(flags) > 0

            prepared = {
                "block": block,
                "index": index,
                "page_no": page_no,
                "raw_text": raw_text,
                "block_type": block_type,
                "source_meta": source_meta,
                "source_layout": source_layout,
                "table": table,
                "normalized": normalized,
                "confidence": confidence,
                "flags": flags,
                "needs_review": needs_review,
                "spell_suggestions": [],
            }
            prepared_blocks.append(prepared)

            if needs_review and raw_text and block_type not in {"table", "image"}:
                spellcheck_inputs.append(str(normalized["text"]))
                spellcheck_targets.append(prepared)

        prepared_pages.append(
            {
                "page": page,
                "page_no": page_no,
                "blocks": prepared_blocks,
            }
        )

    if spellcheck_inputs:
        try:
            spellcheck_results = get_spell_checker().bulk_check(spellcheck_inputs)
            for target, suggestions in zip(spellcheck_targets, spellcheck_results):
                target["spell_suggestions"] = suggestions
        except Exception:
            pass

    for prepared_page in prepared_pages:
        page = prepared_page["page"]
        page_no = prepared_page["page_no"]
        transformed_blocks: list[dict] = []

        for prepared in prepared_page["blocks"]:
            block = prepared["block"]
            index = prepared["index"]
            raw_text = prepared["raw_text"]
            block_type = prepared["block_type"]
            source_meta = prepared["source_meta"]
            source_layout = prepared["source_layout"]
            table = prepared["table"]
            normalized = prepared["normalized"]
            confidence = prepared["confidence"]
            flags = prepared["flags"]
            needs_review = prepared["needs_review"]
            spell_suggestions = prepared["spell_suggestions"]

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

            layout_css = build_layout_style(source_layout)

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
                    "meta": {
                        **source_meta,
                        "section_path": source_meta.get("section_path"),
                        "table_html": table["html"] if table is not None else source_meta.get("table_html"),
                        "reviewed_html": reviewed_html,
                        "layout": {**source_layout, "layout_css": layout_css},
                        "table": table,
                        "spell_suggestions": spell_suggestions if spell_suggestions else source_meta.get("spell_suggestions", []),
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
                "image_url": page.get("image_url"),
                "source_kind": page.get("source_kind") or _default_page_source_kind(source_type, page),
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


def _normalize_block_text(raw_text: str, normalizer: Callable[[str], dict]) -> dict:
    if raw_text == "":
        return {"text": "", "flags": []}
    if _is_marker_only(raw_text):
        return {"text": raw_text, "flags": []}
    return normalizer(raw_text)


def _is_marker_only(text: str) -> bool:
    value = text.strip()
    if not value:
        return False
    return any(pattern.fullmatch(value) for pattern in _MARKER_ONLY_PATTERNS)


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

    indent_level_raw = source.get("indent_level")
    indent_level: int | None
    if indent_level_raw is None:
        indent_level = None
    else:
        try:
            indent_level = max(0, min(10, int(indent_level_raw)))
        except (TypeError, ValueError):
            indent_level = None

    indent_unit_raw = source.get("indent_unit_pt")
    try:
        indent_unit_pt = float(indent_unit_raw) if indent_unit_raw is not None else None
    except (TypeError, ValueError):
        indent_unit_pt = None

    return {
        "bbox": bbox if isinstance(bbox, list) or bbox is None else source.get("bbox"),
        "reading_order": int(source.get("reading_order") or reading_order or 0),
        "alignment": source.get("alignment"),
        "indent_left": to_int_or_none(source.get("indent_left")),
        "indent_first_line": to_int_or_none(source.get("indent_first_line")),
        "indent_hanging": to_int_or_none(source.get("indent_hanging")),
        "indent_level": indent_level,
        "indent_unit_pt": indent_unit_pt,
        "tabs": tabs,
        "spacing_before": to_int_or_none(source.get("spacing_before")),
        "spacing_after": to_int_or_none(source.get("spacing_after")),
        "line_spacing": to_int_or_none(source.get("line_spacing")),
        "layout_css": str(source.get("layout_css") or "") if source.get("layout_css") else "",
    }


def build_reviewed_html(block_type: str, text: str, layout: dict, block_id: str, table: dict | None = None, source_meta: dict | None = None) -> str:
    if block_type == "table" and table is not None:
        html = str(table["html"])
        return html.replace("<table", f'<table data-block-id="{block_id}"', 1)

    if block_type == "image":
        source_meta = source_meta or {}
        img_path = source_meta.get("image_path", "")
        if img_path:
            return (
                f'<figure data-block-id="{block_id}" class="doc-image" style="text-align:center; margin:1rem 0;">'
                f'<img src="{escape_html(str(img_path))}" alt="embedded image" '
                f'style="max-width:100%; height:auto; display:block; margin:0 auto;"/>'
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

    indent_level = layout.get("indent_level") if isinstance(layout, dict) else None
    if isinstance(indent_level, int) and indent_level >= 0:
        classes.append(f"doc-indent-{indent_level}")

    style = build_layout_style(layout)
    if not style and isinstance(layout, dict):
        style = str(layout.get("layout_css") or "")
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
            html = source_table.get("html") or build_table_html(cells)
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
    styles: list[str] = []
    alignment = layout.get("alignment")
    if alignment:
        styles.append(f"text-align:{alignment}")

    indent_left = to_int_or_none(layout.get("indent_left"))
    indent_first_line = to_int_or_none(layout.get("indent_first_line"))
    indent_hanging = to_int_or_none(layout.get("indent_hanging"))
    indent_level = layout.get("indent_level")
    spacing_before = to_int_or_none(layout.get("spacing_before"))
    spacing_after = to_int_or_none(layout.get("spacing_after"))
    line_spacing = to_int_or_none(layout.get("line_spacing"))

    if indent_left is not None:
        # Word stores indent in twips (1/20 point), convert to points
        styles.append(f"margin-left:{indent_left / 20:.1f}pt")

    if indent_first_line is not None:
        # First line indent (positive = indent, negative = hanging)
        styles.append(f"text-indent:{indent_first_line / 20:.1f}pt")
    elif indent_hanging is not None:
        # Hanging indent (always negative)
        styles.append(f"text-indent:-{indent_hanging / 20:.1f}pt")

    # Paragraph spacing (before/after)
    if spacing_before is not None:
        styles.append(f"margin-top:{spacing_before / 20:.1f}pt")
    if spacing_after is not None:
        styles.append(f"margin-bottom:{spacing_after / 20:.1f}pt")

    # Line spacing (Word uses 240 = single line, 480 = double, etc.).
    # Enforce a minimum of 1.8 so Thai above/below-consonant vowel marks are not clipped.
    if line_spacing is not None and line_spacing > 0:
        line_height = max(line_spacing / 240.0, 1.8)
    else:
        line_height = 1.8
    styles.append(f"line-height:{line_height:.2f}")

    generated_style = "; ".join(styles)
    existing_style = str(layout.get("layout_css") or "").strip() if isinstance(layout, dict) else ""
    if not existing_style:
        return generated_style
    if not generated_style:
        return existing_style
    if generated_style == existing_style:
        return generated_style
    return f"{existing_style}; {generated_style}"


def to_int_or_none(value: object) -> int | None:
    if value is None or value == "":
        return None
    return int(value)


def _default_page_source_kind(source_type: str, page: dict) -> str:
    if page.get("image_path"):
        return "pdf_scan"
    if source_type == "pdf_scan":
        return "pdf_scan"
    if source_type == "docx":
        return "docx"
    return "pdf_text"


def escape_html(value: str) -> str:
    return (
        value.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
        .replace("'", "&#39;")
    )
