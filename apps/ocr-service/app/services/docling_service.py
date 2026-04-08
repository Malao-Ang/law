from __future__ import annotations

import base64
from pathlib import Path
import re
import zipfile
from xml.etree import ElementTree as ET

import fitz


# ─────────────────────────────────────────────────────────────────────────────
# Span-joining helper
# ─────────────────────────────────────────────────────────────────────────────

def _join_thai_spans(parts: list[str]) -> str:
    """Join PDF text spans without inserting spaces where Thai combining marks
    would split a syllable.  PyMuPDF sometimes places a vowel/tone mark in its
    own span, separated from the base consonant by a space.  This helper skips
    the space when the *next* span starts with such a mark (or the current
    span ends with one), so downstream normalisation sees the correct sequence.
    """
    THAI_COMBINING_START = set("ิีึืุูั็่้๊๋์ํฺ๎า")
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


# ─────────────────────────────────────────────────────────────────────────────
# Image utilities
# ─────────────────────────────────────────────────────────────────────────────

_EXT_MIME: dict[str, str] = {
    "png": "image/png",
    "jpg": "image/jpeg",
    "jpeg": "image/jpeg",
    "jfif": "image/jpeg",
    "gif": "image/gif",
    "webp": "image/webp",
    "bmp": "image/bmp",
    "tiff": "image/tiff",
    "tif": "image/tiff",
    "jpx": "image/jp2",
}

# Images larger than 2 MB are saved to disk only; smaller ones get an inline
# data-URI so the API response is self-contained.
_INLINE_MAX_BYTES = 2 * 1024 * 1024


def _bytes_to_data_uri(data: bytes, ext: str) -> str | None:
    """Return a base64 data-URI, or None when the payload exceeds the limit."""
    if len(data) > _INLINE_MAX_BYTES:
        return None
    safe = ext.lstrip(".").lower()
    if safe in {"jpg", "jfif"}:
        safe = "jpeg"
    mime = _EXT_MIME.get(safe, "image/png")
    return f"data:{mime};base64,{base64.b64encode(data).decode()}"


# ─────────────────────────────────────────────────────────────────────────────
# DoclingService
# ─────────────────────────────────────────────────────────────────────────────

class DoclingService:
    WORD_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
    NAMESPACES = {"w": WORD_NS}

    def __init__(self, data_root: Path | None = None) -> None:
        # data_root is used to persist extracted images to disk
        self._data_root = data_root
        self._converter = None
        try:
            from docling.document_converter import DocumentConverter  # type: ignore
            self._converter = DocumentConverter()
        except Exception:
            self._converter = None

    # ── image storage ────────────────────────────────────────────────────────

    def _image_dir(self, document_id: str) -> Path:
        root = self._data_root or Path("/tmp")
        path = root / "images" / document_id
        path.mkdir(parents=True, exist_ok=True)
        return path

    def _save_image(self, data: bytes, ext: str, document_id: str, name: str) -> Path:
        safe = ext.lstrip(".").lower()
        if safe in {"jpg", "jfif"}:
            safe = "jpeg"
        out = self._image_dir(document_id) / f"{name}.{safe}"
        out.write_bytes(data)
        return out

    # ── public API ───────────────────────────────────────────────────────────

    def extract(
        self, file_path: Path, source_type: str, document_id: str = "doc"
    ) -> list[dict]:
        if source_type == "docx":
            blocks = self._extract_docx_blocks(file_path, document_id)
            return [{"page_no": 1, "image_path": None, "blocks": blocks}]

        if source_type == "pdf_text":
            pages_data = self._extract_pdf_blocks(file_path, document_id)
            return [
                {"page_no": page_no, "image_path": None, "blocks": blocks}
                for page_no, blocks in pages_data
            ]

        return []

    # ── DOCX ─────────────────────────────────────────────────────────────────

    def _parse_numbering_xml(self, archive: zipfile.ZipFile) -> dict:
        """Parse word/numbering.xml and return numbering context."""
        numbering_path = "word/numbering.xml"
        if numbering_path not in archive.namelist():
            return {"abstract_nums": {}, "num_map": {}, "counters": {}}

        with archive.open(numbering_path) as fh:
            root = ET.parse(fh).getroot()

        abstract_nums = {}
        num_map = {}

        # Parse abstractNum definitions
        for abstract_num in root.findall("w:abstractNum", self.NAMESPACES):
            abstract_num_id = self._word_attr(abstract_num, "abstractNumId")
            if abstract_num_id is None:
                continue

            levels = {}
            for lvl in abstract_num.findall("w:lvl", self.NAMESPACES):
                ilvl = self._word_attr(lvl, "ilvl")
                if ilvl is None:
                    continue

                start_elem = lvl.find("w:start", self.NAMESPACES)
                start = int(self._word_attr(start_elem, "val") or "1")

                num_fmt_elem = lvl.find("w:numFmt", self.NAMESPACES)
                num_fmt = self._word_attr(num_fmt_elem, "val") or "decimal"

                lvl_text_elem = lvl.find("w:lvlText", self.NAMESPACES)
                lvl_text = self._word_attr(lvl_text_elem, "val") or "%1."

                # Parse indentation for this level
                indent_left = None
                indent_hanging = None
                indent_first_line = None

                ind = lvl.find("w:pPr/w:ind", self.NAMESPACES)
                if ind is not None:
                    indent_left = self._parse_int_attr(ind, "left")
                    indent_hanging = self._parse_int_attr(ind, "hanging")
                    indent_first_line = self._parse_int_attr(ind, "firstLine")

                levels[int(ilvl)] = {
                    "numFmt": num_fmt,
                    "lvlText": lvl_text,
                    "start": start,
                    "indent_left": indent_left,
                    "indent_hanging": indent_hanging,
                    "indent_first_line": indent_first_line,
                }

            abstract_nums[int(abstract_num_id)] = {"levels": levels}

        # Parse num instances that reference abstractNum
        for num in root.findall("w:num", self.NAMESPACES):
            num_id = self._word_attr(num, "numId")
            abstract_num_id_ref = None

            abstract_num_ref = num.find("w:abstractNumId", self.NAMESPACES)
            if abstract_num_ref is not None:
                abstract_num_id_ref = self._word_attr(abstract_num_ref, "val")

            if num_id is not None and abstract_num_id_ref is not None:
                # Check for level overrides
                overrides = {}
                for lvl_override in num.findall("w:lvlOverride", self.NAMESPACES):
                    ilvl = self._word_attr(lvl_override, "ilvl")
                    if ilvl is None:
                        continue

                    start_elem = lvl_override.find("w:startOverride", self.NAMESPACES)
                    start = int(self._word_attr(start_elem, "val") or "1")

                    num_fmt_elem = lvl_override.find("w:numFmt", self.NAMESPACES)
                    num_fmt = self._word_attr(num_fmt_elem, "val")

                    lvl_text_elem = lvl_override.find("w:lvlText", self.NAMESPACES)
                    lvl_text = self._word_attr(lvl_text_elem, "val")

                    override_data = {}
                    if start_elem is not None:
                        override_data["start"] = start
                    if num_fmt is not None:
                        override_data["numFmt"] = num_fmt
                    if lvl_text is not None:
                        override_data["lvlText"] = lvl_text

                    if override_data:
                        overrides[int(ilvl)] = override_data

                num_map[int(num_id)] = {
                    "abstractNumId": int(abstract_num_id_ref),
                    "overrides": overrides,
                }

        return {
            "abstract_nums": abstract_nums,
            "num_map": num_map,
            "counters": {},  # Will track running counters per (numId, ilvl)
        }

    def _extract_docx_blocks(self, file_path: Path, document_id: str) -> list[dict]:
        with zipfile.ZipFile(file_path) as archive:
            # Build rId → zip-path map for embedded images
            rel_map: dict[str, str] = {}
            rel_path = "word/_rels/document.xml.rels"
            if rel_path in archive.namelist():
                with archive.open(rel_path) as fh:
                    for rel in ET.parse(fh).getroot():
                        if rel.get("Type", "").endswith("/image"):
                            rid = rel.get("Id", "")
                            target = rel.get("Target", "").lstrip("./")
                            if not target.startswith("word/"):
                                target = "word/" + target
                            rel_map[rid] = target

            # Parse numbering definitions
            numbering_context = self._parse_numbering_xml(archive)

            with archive.open("word/document.xml") as fh:
                root = ET.parse(fh).getroot()

            body = root.find("w:body", self.NAMESPACES)
            if body is None:
                return []

            blocks: list[dict] = []
            reading_order = 1

            for child in body:
                tag = self._local_name(child.tag)
                block: dict | None = None

                if tag == "p":
                    # Check for embedded image before treating as paragraph
                    img = self._parse_docx_image(
                        child, archive, rel_map, document_id, reading_order
                    )
                    if img is not None:
                        block = img
                    else:
                        block = self._parse_docx_paragraph(child, reading_order, numbering_context)
                elif tag == "tbl":
                    block = self._parse_docx_table(child, reading_order)

                if block is None:
                    continue
                blocks.append(block)
                reading_order += 1

        return blocks

    def _parse_docx_image(
        self,
        paragraph: ET.Element,
        archive: zipfile.ZipFile,
        rel_map: dict[str, str],
        document_id: str,
        reading_order: int,
    ) -> dict | None:
        REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"

        rid: str | None = None
        for node in paragraph.iter():
            rid = node.get(f"{{{REL_NS}}}embed")
            if rid:
                break

        if not rid or rid not in rel_map:
            return None

        zip_path = rel_map[rid]
        if zip_path not in archive.namelist():
            return None

        img_data = archive.read(zip_path)
        ext = Path(zip_path).suffix
        img_name = f"img-{reading_order:04d}"
        img_path = self._save_image(img_data, ext, document_id, img_name)
        data_uri = _bytes_to_data_uri(img_data, ext)

        return {
            "block_id": f"1-{reading_order}",
            "type": "image",
            "reading_order": reading_order,
            "raw_text": "",
            "bbox": None,
            "confidence": 1.0,
            "flags": [],
            "meta": {
                "image_path": str(img_path),
                "image_data_uri": data_uri,
                "source": "docx_embedded",
            },
        }

    # ── PDF ──────────────────────────────────────────────────────────────────

    def _extract_pdf_blocks(
        self, file_path: Path, document_id: str
    ) -> list[tuple[int, list[dict]]]:
        if self._converter is not None:
            try:
                self._converter.convert(str(file_path))
            except Exception:
                pass

        doc = fitz.open(file_path)
        pages: list[tuple[int, list[dict]]] = []

        for page_index, page in enumerate(doc, start=1):
            blocks = self._extract_pdf_page_blocks(page, page_index, document_id)
            pages.append((page_index, blocks))

        doc.close()
        return pages

    def _extract_pdf_page_blocks(
        self, page: object, page_index: int, document_id: str
    ) -> list[dict]:
        blocks: list[dict] = []
        reading_order = 1

        # Detect table regions so overlapping text can be skipped
        try:
            tables = page.find_tables()
            table_rects = [t.bbox for t in tables] if tables else []
        except Exception:
            table_rects = []

        text_dict = page.get_text("dict")

        for block in text_dict.get("blocks", []):
            # ── image block (type 1) ──────────────────────────────────────
            if block["type"] == 1:
                img_block = self._extract_pdf_image_block(
                    block, page_index, reading_order, document_id
                )
                if img_block is not None:
                    blocks.append(img_block)
                    reading_order += 1
                continue

            # ── text block (type 0) ───────────────────────────────────────
            if block["type"] == 0:
                block_rect = block["bbox"]
                if any(self._rects_overlap(block_rect, tr) for tr in table_rects):
                    continue  # covered by a table — handled below

                spans: list[str] = []
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        t = span.get("text", "").strip()
                        if t:
                            spans.append(t)

                if spans:
                    blocks.append({
                        "block_id": f"{page_index}-{reading_order}",
                        "type": "paragraph",
                        "reading_order": reading_order,
                        "raw_text": _join_thai_spans(spans),
                        "bbox": list(block_rect),
                        "confidence": 0.99,
                        "flags": [],
                    })
                    reading_order += 1

        # ── table blocks ──────────────────────────────────────────────────
        for i, table in enumerate(page.find_tables() or []):
            tb = self._extract_pdf_table(table, page_index, reading_order + i)
            if tb:
                blocks.append(tb)

        # Fallback for pages with no structured content
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

    def _extract_pdf_image_block(
        self,
        block: dict,
        page_index: int,
        reading_order: int,
        document_id: str,
    ) -> dict | None:
        try:
            img_bytes: bytes = block.get("image", b"")
            if not img_bytes:
                return None

            width  = block.get("width", 0)
            height = block.get("height", 0)
            # Skip tiny decorative images (icons, rules, etc.)
            if width < 32 or height < 32:
                return None

            ext      = block.get("ext", "png")
            img_name = f"p{page_index:03d}-img{reading_order:04d}"
            img_path = self._save_image(img_bytes, ext, document_id, img_name)
            data_uri = _bytes_to_data_uri(img_bytes, ext)

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

    # ── table ─────────────────────────────────────────────────────────────────

    def _extract_pdf_table(
        self, table: object, page_index: int, reading_order: int
    ) -> dict | None:
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

            headers  = [c["text"] for c in rows[0]]
            body     = [[c["text"] for c in r] for r in rows[1:]]
            html     = self._render_table_html(rows)
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
    def _rects_overlap(
        rect1: list | tuple, rect2: list | tuple, threshold: float = 0.1
    ) -> bool:
        if not rect1 or not rect2:
            return False
        x0_1, y0_1, x1_1, y1_1 = rect1
        x0_2, y0_2, x1_2, y1_2 = rect2
        ix0, iy0 = max(x0_1, x0_2), max(y0_1, y0_2)
        ix1, iy1 = min(x1_1, x1_2), min(y1_1, y1_2)
        if ix1 < ix0 or iy1 < iy0:
            return False
        return (ix1 - ix0) * (iy1 - iy0) > (x1_1 - x0_1) * (y1_1 - y0_1) * threshold

    # ── DOCX paragraph / table helpers ───────────────────────────────────────

    def _parse_docx_paragraph(self, paragraph: ET.Element, reading_order: int, numbering_context: dict) -> dict | None:
        text = self._extract_paragraph_text(paragraph)
        layout = self._extract_paragraph_layout(paragraph)
        
        # Resolve numbering if present
        numbering_info = self._resolve_numbering(paragraph, numbering_context, layout)
        if numbering_info:
            text = numbering_info["prefix"] + "\t" + text
            layout = numbering_info["layout"]
        
        if text.strip() == "":
            return None
            
        meta = {"layout": layout}
        if numbering_info:
            meta["numbering"] = numbering_info["meta"]
            
        return {
            "block_id": f"1-{reading_order}",
            "type": self._classify_paragraph(
                text=text, 
                layout=layout, 
                reading_order=reading_order,
                has_numbering=numbering_info is not None,
                numbering_ilvl=numbering_info["meta"]["ilvl"] if numbering_info else None
            ),
            "reading_order": reading_order,
            "raw_text": text,
            "bbox": None,
            "confidence": 0.98,
            "flags": [],
            "meta": meta,
        }

    def _parse_docx_table(self, table: ET.Element, reading_order: int) -> dict | None:
        rows: list[list[dict]] = []
        active_vertical_merges: dict[int, dict] = {}
        flattened_rows: list[list[str]] = []

        for row in table.findall("w:tr", self.NAMESPACES):
            parsed_row: list[dict] = []
            flat_row: list[str] = []
            next_merges: dict[int, dict] = {}
            col = 0

            for cell in row.findall("w:tc", self.NAMESPACES):
                parsed_cell   = self._parse_table_cell(cell)
                colspan       = parsed_cell["colspan"]
                v_merge_state = parsed_cell.pop("v_merge_state")

                if v_merge_state == "continue":
                    mc = active_vertical_merges.get(col)
                    if mc is not None:
                        mc["rowspan"] += 1
                        for off in range(colspan):
                            next_merges[col + off] = mc
                    col += colspan
                    continue

                parsed_row.append(parsed_cell)
                flat_row.append(parsed_cell["text"])

                if v_merge_state == "restart":
                    for off in range(colspan):
                        next_merges[col + off] = parsed_cell

                col += colspan

            if parsed_row:
                rows.append(parsed_row)
                flattened_rows.append(flat_row)

            active_vertical_merges = next_merges

        if not rows:
            return None

        payload = self._build_docx_table_payload(rows, flattened_rows)
        return {
            "block_id": f"1-{reading_order}",
            "type": "table",
            "reading_order": reading_order,
            "raw_text": payload["text"],
            "bbox": None,
            "confidence": 0.99,
            "flags": [],
            "meta": {
                "table": payload,
                "layout": {
                    "bbox": None, "reading_order": reading_order, "alignment": None,
                    "indent_left": None, "indent_first_line": None, "indent_hanging": None, "tabs": [],
                },
            },
        }

    def _extract_paragraph_text(self, paragraph: ET.Element) -> str:
        parts: list[str] = []
        for node in paragraph.iter():
            tag = self._local_name(node.tag)
            if tag == "t" and node.text:
                parts.append(node.text)
            elif tag == "tab":
                parts.append("\t")
            elif tag in {"br", "cr"}:
                parts.append("\n")
        return "".join(parts).replace("\u00a0", " ").strip("\n")

    def _extract_paragraph_layout(self, paragraph: ET.Element) -> dict:
        pPr = paragraph.find("w:pPr", self.NAMESPACES)
        alignment = indent_left = indent_first_line = indent_hanging = None
        spacing_before = spacing_after = line_spacing = None
        tabs: list[dict] = []
        if pPr is not None:
            jc  = pPr.find("w:jc",  self.NAMESPACES)
            ind = pPr.find("w:ind", self.NAMESPACES)
            spacing = pPr.find("w:spacing", self.NAMESPACES)
            if jc  is not None: alignment          = self._word_attr(jc, "val")
            if ind is not None:
                indent_left       = self._parse_int_attr(ind, "left")
                indent_first_line = self._parse_int_attr(ind, "firstLine")
                indent_hanging    = self._parse_int_attr(ind, "hanging")
            if spacing is not None:
                spacing_before = self._parse_int_attr(spacing, "before")
                spacing_after = self._parse_int_attr(spacing, "after")
                line_spacing = self._parse_int_attr(spacing, "line")
            for tab in pPr.findall("w:tabs/w:tab", self.NAMESPACES):
                pos = self._parse_int_attr(tab, "pos")
                if pos is not None:
                    tabs.append({"align": self._word_attr(tab, "val") or "left", "position": pos})
        return {
            "bbox": None, "reading_order": None, "alignment": alignment,
            "indent_left": indent_left, "indent_first_line": indent_first_line,
            "indent_hanging": indent_hanging, "tabs": tabs,
            "spacing_before": spacing_before, "spacing_after": spacing_after,
            "line_spacing": line_spacing,
        }

    def _parse_table_cell(self, cell: ET.Element) -> dict:
        tcPr          = cell.find("w:tcPr", self.NAMESPACES)
        colspan       = 1
        v_merge_state = None
        if tcPr is not None:
            gs = tcPr.find("w:gridSpan", self.NAMESPACES)
            vm = tcPr.find("w:vMerge",   self.NAMESPACES)
            if gs is not None: colspan       = int(self._word_attr(gs, "val") or "1")
            if vm is not None: v_merge_state = self._word_attr(vm, "val") or "continue"
        text = "\n".join(
            part for part in (
                self._extract_paragraph_text(p).strip()
                for p in cell.findall("w:p", self.NAMESPACES)
            ) if part
        )
        return {
            "text": text, "colspan": colspan, "rowspan": 1,
            "alignment": self._extract_cell_alignment(cell),
            "v_merge_state": v_merge_state,
        }

    def _extract_cell_alignment(self, cell: ET.Element) -> str | None:
        for p in cell.findall("w:p", self.NAMESPACES):
            al = self._extract_paragraph_layout(p).get("alignment")
            if al is not None:
                return str(al)
        return None

    def _build_docx_table_payload(
        self, rows: list[list[dict]], flattened_rows: list[list[str]]
    ) -> dict:
        headers  = flattened_rows[0] if flattened_rows else []
        body     = flattened_rows[1:]
        html     = self._render_table_html(rows)
        raw_text = "\n".join("\t".join(c for c in r if c) for r in flattened_rows)
        return {"headers": headers, "rows": body, "cells": rows, "html": html, "text": raw_text}

    def _render_table_html(self, rows: list[list[dict]]) -> str:
        html_rows: list[str] = []
        for ri, row in enumerate(rows):
            cells: list[str] = []
            tag = "th" if ri == 0 else "td"
            for cell in row:
                attrs: list[str] = []
                if cell["colspan"] > 1: attrs.append(f' colspan="{cell["colspan"]}"')
                if cell["rowspan"] > 1: attrs.append(f' rowspan="{cell["rowspan"]}"')
                if cell["alignment"]:
                    al = escape_html(str(cell["alignment"]))
                    attrs.append(f' data-cell-align="{al}" style="text-align:{al};"')
                content = escape_html(cell["text"]).replace("\n", "<br>")
                cells.append(f'<{tag}{"".join(attrs)}>{content}</{tag}>')
            html_rows.append("<tr>" + "".join(cells) + "</tr>")
        return "<table><tbody>" + "".join(html_rows) + "</tbody></table>"

    def _resolve_numbering(self, paragraph: ET.Element, numbering_context: dict, layout: dict) -> dict | None:
        """Resolve numbering for a paragraph and return prefix and updated layout."""
        # Check if paragraph has numbering properties
        pPr = paragraph.find("w:pPr", self.NAMESPACES)
        if pPr is None:
            return None
            
        numPr = pPr.find("w:numPr", self.NAMESPACES)
        if numPr is None:
            return None
            
        # Get numId and ilvl (indentation level)
        numId_elem = numPr.find("w:numId", self.NAMESPACES)
        ilvl_elem = numPr.find("w:ilvl", self.NAMESPACES)
        
        if numId_elem is None or ilvl_elem is None:
            return None
            
        numId = int(self._word_attr(numId_elem, "val") or "0")
        ilvl = int(self._word_attr(ilvl_elem, "val") or "0")
        
        # Look up numbering definition
        num_map = numbering_context.get("num_map", {})
        abstract_nums = numbering_context.get("abstract_nums", {})
        
        if numId not in num_map:
            return None
            
        num_def = num_map[numId]
        abstract_num_id = num_def["abstractNumId"]
        
        if abstract_num_id not in abstract_nums:
            return None
            
        abstract_num = abstract_nums[abstract_num_id]
        levels = abstract_num["levels"]
        
        if ilvl not in levels:
            return None
            
        level_info = levels[ilvl]
        
        # Apply overrides if present
        overrides = num_def.get("overrides", {})
        if ilvl in overrides:
            override = overrides[ilvl]
            level_info = {**level_info, **override}
        
        # Get or increment counter for this (numId, ilvl) combination
        counters = numbering_context.get("counters", {})
        counter_key = (numId, ilvl)
        
        # Reset counters for lower levels when this level increments
        if ilvl > 0:
            for key in list(counters.keys()):
                if key[0] == numId and key[1] < ilvl:
                    del counters[key]
        
        current_num = counters.get(counter_key, level_info["start"] - 1) + 1
        counters[counter_key] = current_num
        numbering_context["counters"] = counters
        
        # Generate the prefix text based on numFmt and lvlText
        prefix = self._format_numbering_prefix(
            current_num, 
            level_info["numFmt"], 
            level_info["lvlText"],
            numId,
            ilvl,
            numbering_context
        )
        
        # Update layout with numbering indentation if not already set
        updated_layout = layout.copy()
        if updated_layout.get("indent_left") is None and level_info.get("indent_left") is not None:
            updated_layout["indent_left"] = level_info["indent_left"]
        if updated_layout.get("indent_hanging") is None and level_info.get("indent_hanging") is not None:
            updated_layout["indent_hanging"] = level_info["indent_hanging"]
        if updated_layout.get("indent_first_line") is None and level_info.get("indent_first_line") is not None:
            updated_layout["indent_first_line"] = level_info["indent_first_line"]
        
        return {
            "prefix": prefix,
            "layout": updated_layout,
            "meta": {
                "numId": numId,
                "ilvl": ilvl,
                "numFmt": level_info["numFmt"],
                "generated_prefix": prefix,
                "current_num": current_num,
            }
        }

    def _format_numbering_prefix(self, num: int, num_fmt: str, lvl_text: str, numId: int, ilvl: int, numbering_context: dict) -> str:
        """Format the numbering prefix based on numFmt and lvlText pattern."""
        # Handle different numbering formats
        if num_fmt == "decimal":
            num_str = str(num)
        elif num_fmt == "thaiNumbers":
            thai_digits = "๐๑๒๓๔๕๖๗๘๙"
            num_str = "".join(thai_digits[int(d)] for d in str(num))
        elif num_fmt == "thaiLetters":
            thai_letters = "กขคงจฉชซฌญฎฏฐฑฒณดตถทธนบปผพภมยรลวศษสหฬอฮ"
            if 1 <= num <= len(thai_letters):
                num_str = thai_letters[num - 1]
            else:
                num_str = str(num)  # Fallback
        elif num_fmt == "bullet":
            # Use bullet characters
            bullets = ["•", "◦", "▪", "▫", "■", "□", "▪", "▫"]
            num_str = bullets[min(ilvl, len(bullets) - 1)]
        elif num_fmt == "lowerLetter":
            if 1 <= num <= 26:
                num_str = chr(ord('a') + num - 1)
            else:
                num_str = str(num)
        elif num_fmt == "upperLetter":
            if 1 <= num <= 26:
                num_str = chr(ord('A') + num - 1)
            else:
                num_str = str(num)
        elif num_fmt == "lowerRoman":
            roman_numerals = ["i", "ii", "iii", "iv", "v", "vi", "vii", "viii", "ix", "x",
                            "xi", "xii", "xiii", "xiv", "xv", "xvi", "xvii", "xviii", "xix", "xx"]
            if 1 <= num <= len(roman_numerals):
                num_str = roman_numerals[num - 1]
            else:
                num_str = str(num)
        elif num_fmt == "upperRoman":
            roman_numerals = ["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X",
                            "XI", "XII", "XIII", "XIV", "XV", "XVI", "XVII", "XVIII", "XIX", "XX"]
            if 1 <= num <= len(roman_numerals):
                num_str = roman_numerals[num - 1]
            else:
                num_str = str(num)
        else:
            num_str = str(num)  # Default fallback
        
        # Replace placeholders in lvlText
        result = lvl_text
        result = result.replace("%1", num_str)
        
        # Handle multi-level numbering (e.g., %1.%2.%3)
        for level in range(2, 10):
            placeholder = f"%{level}"
            if placeholder in result:
                # Get parent level number
                parent_ilvl = ilvl - (level - 1)
                if parent_ilvl >= 0:
                    parent_key = (numId, parent_ilvl)
                    parent_num = numbering_context.get("counters", {}).get(parent_key, 1)
                    parent_fmt = numbering_context.get("abstract_nums", {}).get(
                        numbering_context.get("num_map", {}).get(numId, {}).get("abstractNumId", 0), {}
                    ).get("levels", {}).get(parent_ilvl, {}).get("numFmt", "decimal")
                    
                    parent_str = self._format_numbering_prefix(parent_num, parent_fmt, "%1", numId, parent_ilvl, numbering_context)
                    result = result.replace(placeholder, parent_str)
        
        return result

    def _classify_paragraph(self, text: str, layout: dict, reading_order: int, has_numbering: bool = False, numbering_ilvl: int | None = None) -> str:
        stripped = text.strip()
        alignment = layout.get("alignment")
        
        # If paragraph has numbering, classify as list_item by default
        if has_numbering:
            return "list_item"
            
        if alignment == "center":
            return "title" if reading_order <= 4 else "section_header"
        if re.match(r"^(ข้อ\s*[๐-๙0-9]+|ข้อ[๐-๙0-9]+)", stripped):
            return "section_header"
        if re.match(r"^(\([๐-๙0-9]+\)|-|•)", stripped):
            return "list_item"
        return "paragraph"

    @staticmethod
    def _local_name(tag: str) -> str:
        return tag.split("}", 1)[-1]

    def _word_attr(self, node: ET.Element, name: str) -> str | None:
        return node.get(f"{{{self.WORD_NS}}}{name}")

    def _parse_int_attr(self, node: ET.Element, name: str) -> int | None:
        v = self._word_attr(node, name)
        return None if not v else int(v)


# ─────────────────────────────────────────────────────────────────────────────

def escape_html(value: str) -> str:
    return (
        value.replace("&", "&amp;")
             .replace("<", "&lt;")
             .replace(">", "&gt;")
             .replace('"', "&quot;")
             .replace("'", "&#39;")
    )
