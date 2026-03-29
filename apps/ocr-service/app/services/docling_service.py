from __future__ import annotations

import base64
from pathlib import Path
import re
import zipfile
from xml.etree import ElementTree as ET

import fitz

# Import new docling-parse based services
from app.services.docling_parse_extractor import DoclingParseExtractor
from app.services.table_extractor import DoclingTableExtractor
from app.utils.bbox import merge_text_into_table_cells, filter_text_outside_tables
from app.utils.indent_detector import cluster_x_positions, detect_indent_level
from app.utils.gap_detector import detect_gaps, join_cells_with_gaps


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
            
        # Initialize new extractors
        self._text_extractor = DoclingParseExtractor(fallback_to_fitz=True)
        self._table_extractor = DoclingTableExtractor(fallback_to_fitz=True)
        
        # Numbering and style caches for DOCX processing
        self._numbering_cache: dict[str, dict] = {}
        self._styles_cache: dict[str, dict] = {}

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

    def _extract_docx_blocks(self, file_path: Path, document_id: str) -> list[dict]:
        with zipfile.ZipFile(file_path) as archive:
            # Parse numbering.xml for list definitions
            self._parse_docx_numbering(archive)
            
            # Parse styles.xml for style-based formatting
            self._parse_docx_styles(archive)
            
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
                        block = self._parse_docx_paragraph(child, reading_order)
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
        """Extract PDF blocks using new docling-parse architecture.
        
        New flow:
        1. docling-parse → text cells (word-level with coordinates)
        2. DoclingTableExtractor → table structures (TableFormer only)
        3. BBox merge → map text into table cells
        4. Filter text outside tables → paragraph blocks
        5. Indent detection + gap detection → layout analysis
        """
        pages: list[tuple[int, list[dict]]] = []
        
        try:
            # Step 1: Extract text cells using docling-parse
            text_pages = self._text_extractor.extract_pages(file_path)
            
            # Step 2: Extract table structures using docling TableFormer
            tables = self._table_extractor.extract_tables(file_path)
            
            # Step 3: Process each page
            for page_idx in range(1, len(text_pages) + 1):
                page_blocks = self._process_page_with_new_architecture(
                    page_idx, text_pages, tables, document_id, file_path
                )
                pages.append((page_idx, page_blocks))
                
        except Exception as e:
            # Fallback to original fitz-based extraction
            import warnings
            warnings.warn(f"New architecture failed: {e}. Using fallback.")
            pages = self._extract_pdf_blocks_fallback(file_path, document_id)
            
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

    # ── DOCX Numbering and Style Parsing ─────────────────────────────────────────────

    def _parse_docx_numbering(self, archive: zipfile.ZipFile) -> None:
        """Parse numbering.xml to extract list definitions."""
        self._numbering_cache.clear()
        
        numbering_path = "word/numbering.xml"
        if numbering_path not in archive.namelist():
            return
            
        try:
            with archive.open(numbering_path) as fh:
                root = ET.parse(fh).getroot()
                
                # Parse abstract numbering definitions (w:abstractNum)
                for abstract_num in root.findall("w:abstractNum", self.NAMESPACES):
                    abstract_num_id = self._word_attr(abstract_num, "abstractNumId")
                    if abstract_num_id is None:
                        continue
                        
                    levels = {}
                    for level in abstract_num.findall("w:lvl", self.NAMESPACES):
                        level_id = self._word_attr(level, "ilvl")
                        if level_id is None:
                            continue
                            
                        level_info = self._parse_numbering_level(level)
                        levels[level_id] = level_info
                        
                    self._numbering_cache[f"abstractNum_{abstract_num_id}"] = {
                        "type": "abstract",
                        "levels": levels
                    }
                
                # Parse concrete numbering instances (w:num)
                for num in root.findall("w:num", self.NAMESPACES):
                    num_id = self._word_attr(num, "numId")
                    if num_id is None:
                        continue
                        
                    abstract_num_ref = num.find("w:abstractNumId", self.NAMESPACES)
                    if abstract_num_ref is None:
                        continue
                        
                    abstract_num_id = self._word_attr(abstract_num_ref, "val")
                    if abstract_num_id is None:
                        continue
                        
                    # Get abstract numbering definition
                    abstract_key = f"abstractNum_{abstract_num_id}"
                    abstract_def = self._numbering_cache.get(abstract_key, {})
                    
                    # Override with any level-specific overrides
                    levels = abstract_def.get("levels", {}).copy()
                    for override in num.findall("w:lvlOverride", self.NAMESPACES):
                        level_id = self._word_attr(override, "ilvl")
                        if level_id is None:
                            continue
                            
                        level_info = self._parse_numbering_level(override)
                        levels[level_id] = level_info
                        
                    self._numbering_cache[f"num_{num_id}"] = {
                        "type": "concrete",
                        "abstract_num_id": abstract_num_id,
                        "levels": levels
                    }
                    
        except Exception as e:
            # If parsing fails, continue without numbering support
            import warnings
            warnings.warn(f"Failed to parse numbering.xml: {e}")

    def _parse_numbering_level(self, level_element: ET.Element) -> dict:
        """Parse a single numbering level definition."""
        level_info = {}
        
        # Get start number
        start = level_element.find("w:start", self.NAMESPACES)
        if start is not None:
            level_info["start"] = int(self._word_attr(start, "val") or "1")
        
        # Get numbering format
        num_fmt = level_element.find("w:numFmt", self.NAMESPACES)
        if num_fmt is not None:
            level_info["format"] = self._word_attr(num_fmt, "val") or "decimal"
        
        # Get level text (e.g., "%1.", "(%a)", "•")
        lvl_text = level_element.find("w:lvlText", self.NAMESPACES)
        if lvl_text is not None:
            level_info["text"] = self._word_attr(lvl_text, "val") or "%1."
        
        # Get justification
        jc = level_element.find("w:jc", self.NAMESPACES)
        if jc is not None:
            level_info["alignment"] = self._word_attr(jc, "val")
        
        # Get paragraph properties (including indent)
        paragraph_props = level_element.find("w:pPr", self.NAMESPACES)
        if paragraph_props is not None:
            level_info["paragraph_properties"] = self._extract_paragraph_layout(paragraph_props)
        
        return level_info

    def _parse_docx_styles(self, archive: zipfile.ZipFile) -> None:
        """Parse styles.xml to extract style definitions."""
        self._styles_cache.clear()
        
        styles_path = "word/styles.xml"
        if styles_path not in archive.namelist():
            return
            
        try:
            with archive.open(styles_path) as fh:
                root = ET.parse(fh).getroot()
                
                for style in root.findall("w:style", self.NAMESPACES):
                    style_id = self._word_attr(style, "styleId")
                    if style_id is None:
                        continue
                        
                    style_type = self._word_attr(style, "type") or "paragraph"
                    
                    # Extract paragraph properties if this is a paragraph style
                    paragraph_props = None
                    if style_type == "paragraph":
                        paragraph_props = style.find("w:pPr", self.NAMESPACES)
                        if paragraph_props is not None:
                            layout = self._extract_paragraph_layout(paragraph_props)
                            self._styles_cache[style_id] = {
                                "type": style_type,
                                "layout": layout
                            }
                    
        except Exception as e:
            # If parsing fails, continue without style support
            import warnings
            warnings.warn(f"Failed to parse styles.xml: {e}")

    def _get_numbering_for_paragraph(self, paragraph: ET.Element) -> dict | None:
        """Get numbering information for a paragraph."""
        paragraph_props = paragraph.find("w:pPr", self.NAMESPACES)
        if paragraph_props is None:
            return None
            
        numbering_props = paragraph_props.find("w:numPr", self.NAMESPACES)
        if numbering_props is None:
            return None
            
        # Get numbering ID and level
        num_id_elem = numbering_props.find("w:numId", self.NAMESPACES)
        ilvl_elem = numbering_props.find("w:ilvl", self.NAMESPACES)
        
        if num_id_elem is None or ilvl_elem is None:
            return None
            
        num_id = self._word_attr(num_id_elem, "val")
        ilvl = self._word_attr(ilvl_elem, "val")
        
        if num_id is None or ilvl is None:
            return None
            
        # Look up numbering definition
        numbering_key = f"num_{num_id}"
        numbering_def = self._numbering_cache.get(numbering_key)
        
        if not numbering_def:
            return None
            
        # Get level-specific information
        levels = numbering_def.get("levels", {})
        level_info = levels.get(ilvl, {})
        
        # Determine list type based on format
        num_format = level_info.get("format", "decimal")
        list_type = "numbered"
        if num_format in {"bullet", "none"}:
            list_type = "bullet"
        elif num_format in {"decimal", "lowerLetter", "upperLetter", "lowerRoman", "upperRoman"}:
            list_type = "numbered"
        
        return {
            "num_id": num_id,
            "level": int(ilvl),
            "type": list_type,
            "format": num_format,
            "text": level_info.get("text", "%1."),
            "paragraph_properties": level_info.get("paragraph_properties", {})
        }

    # ── DOCX paragraph / table helpers ───────────────────────────────────────

    def _parse_docx_paragraph(self, paragraph: ET.Element, reading_order: int) -> dict | None:
        text   = self._extract_paragraph_text(paragraph)
        layout = self._extract_paragraph_layout(paragraph)
        
        # Get numbering information
        numbering_info = self._get_numbering_for_paragraph(paragraph)
        
        # Get style information
        style_info = self._get_style_for_paragraph(paragraph)
        
        # Merge layout information: direct > numbering > style
        merged_layout = self._merge_layout_properties(layout, numbering_info, style_info)
        
        if text.strip() == "":
            return None
            
        # Determine block type (consider numbering)
        block_type = self._classify_paragraph(
            text=text, 
            layout=merged_layout, 
            reading_order=reading_order,
            numbering_info=numbering_info
        )
        
        return {
            "block_id": f"1-{reading_order}",
            "type": block_type,
            "reading_order": reading_order,
            "raw_text": text,
            "bbox": None,
            "confidence": 0.98,
            "flags": [],
            "meta": {
                "layout": merged_layout,
                "numbering": numbering_info,
                "style": style_info
            },
        }

    def _get_style_for_paragraph(self, paragraph: ET.Element) -> dict | None:
        """Get style information for a paragraph."""
        paragraph_props = paragraph.find("w:pPr", self.NAMESPACES)
        if paragraph_props is None:
            return None
            
        style_ref = paragraph_props.find("w:pStyle", self.NAMESPACES)
        if style_ref is None:
            return None
            
        style_id = self._word_attr(style_ref, "val")
        if style_id is None:
            return None
            
        return self._styles_cache.get(style_id)

    def _merge_layout_properties(self, direct_layout: dict, numbering_info: dict | None, style_info: dict | None) -> dict:
        """Merge layout properties with precedence: direct > numbering > style."""
        merged = direct_layout.copy()
        
        # Apply style-based layout first (lowest priority)
        if style_info and "layout" in style_info:
            style_layout = style_info["layout"]
            for key, value in style_layout.items():
                if value is not None and merged.get(key) is None:
                    merged[key] = value
        
        # Apply numbering-based layout (medium priority)
        if numbering_info and "paragraph_properties" in numbering_info:
            numbering_layout = numbering_info["paragraph_properties"]
            for key, value in numbering_layout.items():
                if value is not None and merged.get(key) is None:
                    merged[key] = value
        
        # Add list-specific information
        if numbering_info:
            merged["list_level"] = numbering_info.get("level", 0)
            merged["list_type"] = numbering_info.get("type", "numbered")
            merged["list_marker"] = numbering_info.get("text", "%1.")
        
        return merged

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
        tabs: list[dict] = []
        if pPr is not None:
            jc  = pPr.find("w:jc",  self.NAMESPACES)
            ind = pPr.find("w:ind", self.NAMESPACES)
            if jc  is not None: alignment          = self._word_attr(jc, "val")
            if ind is not None:
                indent_left       = self._parse_int_attr(ind, "left")
                indent_first_line = self._parse_int_attr(ind, "firstLine")
                indent_hanging    = self._parse_int_attr(ind, "hanging")
            for tab in pPr.findall("w:tabs/w:tab", self.NAMESPACES):
                pos = self._parse_int_attr(tab, "pos")
                if pos is not None:
                    tabs.append({"align": self._word_attr(tab, "val") or "left", "position": pos})
        return {
            "bbox": None, "reading_order": None, "alignment": alignment,
            "indent_left": indent_left, "indent_first_line": indent_first_line,
            "indent_hanging": indent_hanging, "tabs": tabs,
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

    def _classify_paragraph(self, text: str, layout: dict, reading_order: int, numbering_info: dict | None = None) -> str:
        stripped  = text.strip()
        alignment = layout.get("alignment")
        
        # Check for numbered/bulleted lists first (highest priority)
        if numbering_info:
            list_type = numbering_info.get("type", "numbered")
            if list_type == "bullet":
                return "list_item"
            elif list_type == "numbered":
                return "list_item"
        
        # Check for alignment-based classification
        if alignment == "center":
            return "title" if reading_order <= 4 else "section_header"
        
        # Check for Thai legal document patterns
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

    # ── New Architecture Methods ─────────────────────────────────────────────────────

    def _process_page_with_new_architecture(
        self, page_no: int, text_pages: list, tables: list, 
        document_id: str, file_path: Path
    ) -> list[dict]:
        """Process a single page using the new docling-parse architecture."""
        blocks = []
        reading_order = 1
        
        # Get page data
        page_idx = page_no - 1
        if page_idx >= len(text_pages):
            return blocks
            
        page_cells = text_pages[page_idx]
        page_tables = [t for t in tables if t.page_no == page_no]
        
        # Extract images using fitz (keep this functionality)
        image_blocks = self._extract_page_images_fallback(file_path, page_no, document_id)
        blocks.extend(image_blocks)
        reading_order += len(image_blocks)
        
        # Step 1: Merge text into table cells
        table_blocks = []
        if page_tables:
            table_blocks = self._create_table_blocks_with_text_merge(
                page_tables, page_cells.word_cells, page_no, reading_order
            )
            reading_order += len(table_blocks)
            
        # Step 2: Filter text outside tables
        table_bboxes = [table.bbox for table in page_tables]
        outside_text_cells = filter_text_outside_tables(
            page_cells.word_cells, table_bboxes, threshold=0.30
        )
        
        # Step 3: Create paragraph blocks from remaining text
        paragraph_blocks = self._create_paragraph_blocks_from_text(
            outside_text_cells, page_no, reading_order
        )
        
        # Combine all blocks
        all_blocks = table_blocks + paragraph_blocks
        
        # Sort by reading order (y-coordinate, then x-coordinate)
        all_blocks.sort(key=lambda b: (b.get("y0", 0), b.get("x0", 0), b.get("reading_order", 0)))
        
        # Reassign reading order
        for i, block in enumerate(all_blocks, start=1):
            block["reading_order"] = i
            
        return all_blocks
        
    def _create_table_blocks_with_text_merge(
        self, tables: list, text_cells: list, page_no: int, start_reading_order: int
    ) -> list[dict]:
        """Create table blocks by merging text cells into table structures."""
        table_blocks = []
        
        for i, table in enumerate(tables):
            # Merge text into table cells
            merged_cells = merge_text_into_table_cells(
                text_cells, table.rows, threshold=0.30
            )
            
            # Build table data
            headers = []
            body = []
            if merged_cells:
                # Group merged cells by row
                row_groups: dict[int, list] = {}
                for cell in merged_cells:
                    row = cell["row"]
                    if row not in row_groups:
                        row_groups[row] = []
                    row_groups[row].append(cell)
                    
                # Sort rows and extract data
                sorted_rows = sorted(row_groups.items())
                for row_idx, row_cells in sorted_rows:
                    sorted_row_cells = sorted(row_cells, key=lambda c: c["col"])
                    row_text = [cell["text"] for cell in sorted_row_cells]
                    
                    if row_idx == 0:  # First row as headers
                        headers = row_text
                    else:
                        body.append(row_text)
                        
            # Build HTML table
            html = self._build_table_html_from_merged(merged_cells)
            raw_text = "\\n".join("\\t".join(cell["text"] for cell in row) 
                                for row in [merged_cells[i:i+len(headers)] 
                                          for i in range(0, len(merged_cells), len(headers))] 
                                if merged_cells)
            
            table_block = {
                "block_id": f"{page_no}-{start_reading_order + i}",
                "type": "table",
                "reading_order": start_reading_order + i,
                "raw_text": raw_text,
                "bbox": list(table.bbox),
                "confidence": 0.98,
                "flags": ["tableformer_detected"],
                "meta": {
                    "table": {
                        "headers": headers,
                        "rows": body,
                        "cells": merged_cells,
                        "html": html,
                        "text": raw_text,
                    },
                    "layout": {
                        "bbox": list(table.bbox),
                        "reading_order": start_reading_order + i,
                        "alignment": None,
                        "indent_left": None,
                        "indent_first_line": None,
                        "indent_hanging": None,
                        "tabs": [],
                    },
                },
            }
            
            table_blocks.append(table_block)
            
        return table_blocks
        
    def _create_paragraph_blocks_from_text(
        self, text_cells: list, page_no: int, start_reading_order: int
    ) -> list[dict]:
        """Create paragraph blocks from text cells with layout analysis."""
        if not text_cells:
            return []
            
        # Group text cells into lines (already done by docling-parse)
        # For now, we'll create blocks from individual cells
        # In a more sophisticated version, we'd group by y-coordinate proximity
        
        # Analyze indent patterns
        x_positions = [cell.x0 for cell in text_cells]
        indent_clusters = cluster_x_positions(x_positions)
        
        blocks = []
        for i, cell in enumerate(text_cells):
            # Detect indent level
            indent_level = detect_indent_level(cell.x0, indent_clusters)
            
            # Detect gaps (if we had multiple cells in a line)
            # For single cells, no gaps to detect
            
            # Classify block type
            block_type = self._classify_text_block(cell.text, indent_level, i)
            
            block = {
                "block_id": f"{page_no}-{start_reading_order + i}",
                "type": block_type,
                "reading_order": start_reading_order + i,
                "raw_text": cell.text,
                "bbox": list(cell.bbox),
                "confidence": 0.99,
                "flags": [],
                "meta": {
                    "indent_level": indent_level,
                    "x_position": cell.x0,
                    "layout": {
                        "bbox": list(cell.bbox),
                        "reading_order": start_reading_order + i,
                        "alignment": None,
                        "indent_left": cell.x0,
                        "indent_first_line": None,
                        "indent_hanging": None,
                        "tabs": [],
                    },
                },
            }
            
            blocks.append(block)
            
        return blocks
        
    def _classify_text_block(self, text: str, indent_level: int, position: int) -> str:
        """Classify text block type based on content and layout."""
        stripped = text.strip()
        
        if not stripped:
            return "paragraph"
            
        # Title detection (center alignment or early position)
        if position <= 2 or indent_level == 0:
            if re.match(r"^[ก-๙a-zA-Z0-9\\s]{1,50}$", stripped) and len(stripped) < 50:
                return "title"
                
        # Section headers
        if re.match(r"^(ข้อ\\s*[๐-๙0-9]+|ข้อ[๐-๙0-9]+|มาตรา\\s*[๐-๙0-9]+|มาตรา[๐-๙0-9]+)", stripped):
            return "section_header"
            
        # List items
        if re.match(r"^(\\([๐-๙0-9]+\\)|-|•|\\d+\\.|[ก-ฮ]\\.\\s)", stripped):
            return "list_item"
            
        return "paragraph"
        
    def _build_table_html_from_merged(self, merged_cells: list[dict]) -> str:
        """Build HTML table from merged cell data."""
        if not merged_cells:
            return "<table><tbody></tbody></table>"
            
        # Group cells by row
        row_groups: dict[int, list] = {}
        for cell in merged_cells:
            row = cell["row"]
            if row not in row_groups:
                row_groups[row] = []
            row_groups[row].append(cell)
            
        # Build HTML
        html_rows = []
        sorted_rows = sorted(row_groups.items())
        
        for row_idx, row_cells in sorted_rows:
            sorted_row_cells = sorted(row_cells, key=lambda c: c["col"])
            rendered_cells = []
            
            cell_tag = "th" if row_idx == 0 else "td"
            
            for cell in sorted_row_cells:
                attrs = []
                if cell.get("colspan", 1) > 1:
                    attrs.append(f' colspan="{cell["colspan"]}"')
                if cell.get("rowspan", 1) > 1:
                    attrs.append(f' rowspan="{cell["rowspan"]}"')
                    
                text = escape_html(str(cell.get("text", ""))).replace("\\n", "<br>")
                rendered_cells.append(f'<{cell_tag}{"".join(attrs)}>{text}</{cell_tag}>')
                
            html_rows.append("<tr>" + "".join(rendered_cells) + "</tr>")
            
        return "<table><tbody>" + "".join(html_rows) + "</tbody></table>"
        
    def _extract_page_images_fallback(
        self, file_path: Path, page_no: int, document_id: str
    ) -> list[dict]:
        """Extract image blocks using fitz fallback."""
        image_blocks = []
        
        try:
            doc = fitz.open(file_path)
            if page_no <= doc.page_count:
                page = doc[page_no - 1]
                text_dict = page.get_text("dict")
                
                reading_order = 1
                for block in text_dict.get("blocks", []):
                    if block["type"] == 1:  # Image block
                        img_block = self._extract_pdf_image_block(
                            block, page_no, reading_order, document_id
                        )
                        if img_block is not None:
                            image_blocks.append(img_block)
                            reading_order += 1
                            
            doc.close()
        except Exception as e:
            import warnings
            warnings.warn(f"Failed to extract images: {e}")
            
        return image_blocks
        
    def _extract_pdf_blocks_fallback(
        self, file_path: Path, document_id: str
    ) -> list[tuple[int, list[dict]]]:
        """Fallback method using original fitz-based extraction."""
        pages: list[tuple[int, list[dict]]] = []
        
        doc = fitz.open(file_path)
        for page_index, page in enumerate(doc, start=1):
            blocks = self._extract_pdf_page_blocks_fallback(page, page_index, document_id)
            pages.append((page_index, blocks))
            
        doc.close()
        return pages
        
    def _extract_pdf_page_blocks_fallback(
        self, page: object, page_index: int, document_id: str
    ) -> list[dict]:
        """Fallback page extraction using original fitz method."""
        blocks: list[dict] = []
        reading_order = 1
        
        # Keep the original implementation for fallback
        try:
            tables = page.find_tables()
            table_rects = [t.bbox for t in tables] if tables else []
        except Exception:
            table_rects = []
            
        text_dict = page.get_text("dict")
        
        for block in text_dict.get("blocks", []):
            if block["type"] == 1:  # Image block
                img_block = self._extract_pdf_image_block(
                    block, page_index, reading_order, document_id
                )
                if img_block is not None:
                    blocks.append(img_block)
                    reading_order += 1
                continue
                
            if block["type"] == 0:  # Text block
                block_rect = block["bbox"]
                if any(self._rects_overlap(block_rect, tr) for tr in table_rects):
                    continue
                    
                spans = []
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
                        "flags": ["fitz_fallback"],
                    })
                    reading_order += 1
                    
        # Add table blocks
        for i, table in enumerate(page.find_tables() or []):
            tb = self._extract_pdf_table(table, page_index, reading_order + i)
            if tb:
                blocks.append(tb)
                
        # Fallback for pages with no content
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
                    "flags": ["fitz_fallback"],
                }
                for idx, line in enumerate(lines, start=1)
            ]
            
        return blocks


# ─────────────────────────────────────────────────────────────────────────────

def escape_html(value: str) -> str:
    return (
        value.replace("&", "&amp;")
             .replace("<", "&lt;")
             .replace(">", "&gt;")
             .replace('"', "&quot;")
             .replace("'", "&#39;")
    )
