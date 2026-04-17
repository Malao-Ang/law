from __future__ import annotations

from collections.abc import Callable

from app.services.ai_corrector import MockAICorrector
from app.services.html_renderer import build_reviewed_html, build_table_html


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


def normalize_layout(layout: object, bbox: object, reading_order: object) -> dict:
    source = layout if isinstance(layout, dict) else {}
    tabs: list[dict] = []
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
        "indent_left": _to_int(source.get("indent_left")),
        "indent_first_line": _to_int(source.get("indent_first_line")),
        "indent_hanging": _to_int(source.get("indent_hanging")),
        "tabs": tabs,
        "spacing_before": _to_int(source.get("spacing_before")),
        "spacing_after": _to_int(source.get("spacing_after")),
        "line_spacing": _to_int(source.get("line_spacing")),
    }


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
            return {"headers": headers, "rows": rows, "cells": cells, "html": html}

    rows = [row.strip() for row in raw_text.splitlines() if row.strip()]
    parsed_rows = [[cell.strip() for cell in row.split("\t") if cell.strip()] for row in rows]
    parsed_rows = [row for row in parsed_rows if row]
    if not parsed_rows:
        return None

    cells = [
        [{"text": cell, "colspan": 1, "rowspan": 1, "alignment": None} for cell in row]
        for row in parsed_rows
    ]
    return {
        "headers": parsed_rows[0],
        "rows": parsed_rows[1:] if len(parsed_rows) > 1 else [],
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
        normalized_row = [
            {
                "text": str(cell.get("text") or ""),
                "colspan": max(1, int(cell.get("colspan") or 1)),
                "rowspan": max(1, int(cell.get("rowspan") or 1)),
                "alignment": cell.get("alignment"),
            }
            for cell in row
            if isinstance(cell, dict)
        ]
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
    return [[str(item) for item in row] for row in value if isinstance(row, list)]


def flatten_table_row(row: list[dict]) -> list[str]:
    return [str(cell.get("text") or "") for cell in row]


def _to_int(value: object) -> int | None:
    if value is None or value == "":
        return None
    return int(value)
