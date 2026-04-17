from __future__ import annotations

from pathlib import Path

import fitz

from app.services.html_renderer import build_table_html
from app.services.image_extractor import ImageExtractor

TAB_GAP_THRESHOLD_PT = 12.0

THAI_COMBINING_START = set("ิีึืุูั็่้๊๋์ํฺ๎า")


def _join_thai_spans(parts: list[str]) -> str:
    """Join PDF text spans without inserting spaces where Thai combining marks would split a syllable."""
    non_empty = [p for p in parts if p]
    result: list[str] = []
    for i, part in enumerate(non_empty):
        result.append(part)
        if i < len(non_empty) - 1:
            next_part = non_empty[i + 1]
            if next_part[0] in THAI_COMBINING_START:
                continue
            if part[-1] in THAI_COMBINING_START:
                continue
            if part.strip() == "":
                continue
            result.append(" ")
    return "".join(result).strip()


def _extract_block_lines(block: dict, page_margin_x: float) -> tuple[str, list[dict]]:
    """Assemble raw_text from PyMuPDF block spans, inserting \\t where inter-span gap > threshold."""
    detected_tabs: list[dict] = []
    line_texts: list[str] = []

    for line in block.get("lines", []):
        spans = line.get("spans", [])
        parts: list[str] = []
        prev_x_end: float | None = None

        for span in spans:
            text = span.get("text", "")
            if not text.strip():
                continue
            x_start = span.get("origin", [0])[0]
            span_bbox = span.get("bbox", [0, 0, 0, 0])

            if prev_x_end is not None and (x_start - prev_x_end) > TAB_GAP_THRESHOLD_PT:
                parts.append("\t")
                tab_pos_twips = round((x_start - page_margin_x) * 20)
                if not any(abs(t["position"] - tab_pos_twips) < 40 for t in detected_tabs):
                    detected_tabs.append({"align": "left", "position": tab_pos_twips})

            parts.append(text)
            prev_x_end = span_bbox[2] if len(span_bbox) >= 3 else x_start

        if parts:
            groups: list[str] = []
            cur: list[str] = []
            for p in parts:
                if p == "\t":
                    if cur:
                        groups.append(_join_thai_spans(cur))
                        cur = []
                    groups.append("\t")
                else:
                    cur.append(p)
            if cur:
                groups.append(_join_thai_spans(cur))
            line_texts.append("".join(groups))

    return " ".join(line_texts), detected_tabs


class PdfTextParser:
    def __init__(self, image_extractor: ImageExtractor) -> None:
        self._images = image_extractor

    def extract(self, file_path: Path, document_id: str) -> list[dict]:
        doc = fitz.open(file_path)
        pages: list[dict] = []

        for page_index, page in enumerate(doc, start=1):
            blocks = self._extract_page_blocks(page, page_index, document_id)
            pages.append({"page_no": page_index, "image_path": None, "blocks": blocks})

        doc.close()
        return pages

    def _extract_page_blocks(self, page: object, page_index: int, document_id: str) -> list[dict]:
        blocks: list[dict] = []
        reading_order = 1

        try:
            tables = page.find_tables()
            table_rects = [t.bbox for t in tables] if tables else []
        except Exception:
            table_rects = []

        text_dict = page.get_text("dict")
        page_margin_x = self._compute_page_margin_x(text_dict)
        page_width = page.rect.width

        for block in text_dict.get("blocks", []):
            if block["type"] == 1:
                img_block = self._extract_image_block(block, page_index, reading_order, document_id)
                if img_block is not None:
                    blocks.append(img_block)
                    reading_order += 1
                continue

            if block["type"] == 0:
                block_rect = block["bbox"]
                if any(self._rects_overlap(block_rect, tr) for tr in table_rects):
                    continue

                raw_text, detected_tabs = _extract_block_lines(block, page_margin_x)
                if raw_text.strip():
                    blocks.append({
                        "block_id": f"{page_index}-{reading_order}",
                        "type": "paragraph",
                        "reading_order": reading_order,
                        "raw_text": raw_text,
                        "bbox": list(block_rect),
                        "confidence": 0.99,
                        "flags": [],
                        "meta": {
                            "layout": self._estimate_layout(
                                block, page_margin_x, page_width, detected_tabs, reading_order
                            )
                        },
                    })
                    reading_order += 1

        for i, table in enumerate(page.find_tables() or []):
            tb = self._extract_table(table, page_index, reading_order + i)
            if tb:
                blocks.append(tb)

        if not blocks:
            raw = page.get_text("text")
            lines = [ln.strip() for ln in raw.splitlines() if ln.strip()]
            blocks = [
                {
                    "block_id": f"{page_index}-{idx}",
                    "type": "paragraph",
                    "reading_order": idx,
                    "raw_text": line,
                    "bbox": None,
                    "confidence": 0.99,
                    "flags": [],
                }
                for idx, line in enumerate(lines, start=1)
            ]

        return blocks

    def _extract_image_block(
        self, block: dict, page_index: int, reading_order: int, document_id: str
    ) -> dict | None:
        try:
            img_bytes: bytes = block.get("image", b"")
            if not img_bytes:
                return None
            width = block.get("width", 0)
            height = block.get("height", 0)
            if width < 32 or height < 32:
                return None
            ext = block.get("ext", "png")
            img_name = f"p{page_index:03d}-img{reading_order:04d}"
            img_path = self._images.save(img_bytes, ext, document_id, img_name)
            data_uri = self._images.to_data_uri(img_bytes, ext)
            return {
                "block_id": f"{page_index}-{reading_order}",
                "type": "image",
                "reading_order": reading_order,
                "raw_text": "",
                "bbox": list(block.get("bbox", [])),
                "confidence": 1.0,
                "flags": [],
                "meta": {
                    "image_path": str(img_path),
                    "image_data_uri": data_uri,
                    "width": width,
                    "height": height,
                    "source": "pdf_embedded",
                },
            }
        except Exception:
            return None

    def _extract_table(self, table: object, page_index: int, reading_order: int) -> dict | None:
        try:
            table_data = table.extract()
            if not table_data:
                return None
            rows: list[list[dict]] = []
            for row_data in table_data:
                row = [
                    {"text": str(c or "").strip(), "colspan": 1, "rowspan": 1, "alignment": None}
                    for c in row_data
                ]
                if row:
                    rows.append(row)
            if not rows:
                return None
            headers = [c["text"] for c in rows[0]]
            body = [[c["text"] for c in r] for r in rows[1:]]
            html = build_table_html(rows)
            raw_text = "\n".join("\t".join(c["text"] for c in r) for r in rows)
            return {
                "block_id": f"{page_index}-{reading_order}",
                "type": "table",
                "reading_order": reading_order,
                "raw_text": raw_text,
                "bbox": list(table.bbox),
                "confidence": 0.98,
                "flags": ["pdf_table"],
                "meta": {
                    "table": {"headers": headers, "rows": body, "cells": rows, "html": html, "text": raw_text},
                    "layout": {
                        "bbox": list(table.bbox), "reading_order": reading_order,
                        "alignment": None, "indent_left": None,
                        "indent_first_line": None, "indent_hanging": None, "tabs": [],
                    },
                },
            }
        except Exception:
            return None

    @staticmethod
    def _compute_page_margin_x(text_dict: dict) -> float:
        x0_vals = sorted(
            b["bbox"][0] for b in text_dict.get("blocks", []) if b.get("type") == 0
        )
        if len(x0_vals) < 2:
            return 0.0
        return x0_vals[len(x0_vals) // 10]

    @staticmethod
    def _estimate_layout(
        block: dict, page_margin_x: float, page_width: float, tabs: list[dict], reading_order: int
    ) -> dict:
        x0 = block["bbox"][0]
        indent_pt = max(0.0, x0 - page_margin_x)
        indent_left = round(indent_pt * 20) if indent_pt >= 3.0 else None

        alignment = None
        center_offset = abs((block["bbox"][0] + block["bbox"][2]) / 2 - page_width / 2)
        if center_offset < 15:
            alignment = "center"

        return {
            "bbox": list(block["bbox"]),
            "reading_order": reading_order,
            "alignment": alignment,
            "indent_left": indent_left,
            "indent_first_line": None,
            "indent_hanging": None,
            "tabs": tabs,
            "spacing_before": None,
            "spacing_after": None,
            "line_spacing": None,
        }

    @staticmethod
    def _rects_overlap(rect1: list | tuple, rect2: list | tuple, threshold: float = 0.1) -> bool:
        if not rect1 or not rect2:
            return False
        x0_1, y0_1, x1_1, y1_1 = rect1
        x0_2, y0_2, x1_2, y1_2 = rect2
        ix0, iy0 = max(x0_1, x0_2), max(y0_1, y0_2)
        ix1, iy1 = min(x1_1, x1_2), min(y1_1, y1_2)
        if ix1 < ix0 or iy1 < iy0:
            return False
        return (ix1 - ix0) * (iy1 - iy0) > (x1_1 - x0_1) * (y1_1 - y0_1) * threshold
