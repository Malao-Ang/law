from __future__ import annotations

from pathlib import Path
import re
import zipfile
from xml.etree import ElementTree as ET

import fitz

from app.services.confidence_scorer import score_docx_block, score_text_pdf_block
from app.services.html_renderer import build_table_html
from app.services.thai_normalizer import normalize_text as _normalize_thai_text


# ─────────────────────────────────────────────────────────────────────────────
# Span-joining helper
# ─────────────────────────────────────────────────────────────────────────────

_THAI_COMBINING_START = set("ิีึืุูั็่้๊๋์ํฺ๎า")

# Pixels between span end-x and next span start-x above which we treat the
# whitespace as a real tab rather than a single space. ~12pt ≈ 4 monospaced
# characters in body text — empirically large enough to skip normal word gaps
# but small enough to catch deliberate indent stops in Thai legal documents.
PDF_TAB_GAP_THRESHOLD_PT = 12.0

# Narrow empty spans from fonts with broken ToUnicode CMap entries are almost
# always Thai tone marks (่ ้ ๊ ๋ ็ etc.).  Tone-mark glyphs are typically
# ≤ 5 pt wide, so any zero-text span narrower than this threshold is treated
# as a ghost tone mark and replaced by a zero-width-space sentinel (​)
# so that _join_thai_spans does not insert a word space across it.
# The sentinel is later stripped by thai_normalizer._remove_zero_width.
GHOST_TONE_MAX_WIDTH_PT = 6.0
_GHOST_SENTINEL = "​"


def _join_thai_spans(parts: list[str]) -> str:
    """Join PDF text spans without inserting spaces where Thai combining marks
    would split a syllable.  PyMuPDF sometimes places a vowel/tone mark in its
    own span, separated from the base consonant by a space.  This helper skips
    the space when the *next* span starts with such a mark (or the current
    span ends with one), so downstream normalisation sees the correct sequence.

    Ghost-tone sentinels (​, added by _extract_line_text_with_tabs for spans
    whose text was empty but whose bbox was very narrow) are also treated as
    combining markers, suppressing a word space.  The sentinels are later
    stripped by thai_normalizer._remove_zero_width.
    """
    non_empty = [p for p in parts if p]
    result: list[str] = []
    for i, part in enumerate(non_empty):
        result.append(part)
        if i < len(non_empty) - 1:
            next_part = non_empty[i + 1]
            if next_part[0] in _THAI_COMBINING_START:
                continue
            if next_part == _GHOST_SENTINEL:
                continue
            if part[-1] in _THAI_COMBINING_START:
                continue
            if part == _GHOST_SENTINEL:
                continue
            if part.strip() == "":
                continue
        result.append(" ")
    return "".join(result).strip()


def _block_is_bold(block_lines: list) -> bool:
    """Return True when ≥50% of character weight in this block comes from bold spans.

    PyMuPDF span dicts carry `flags` (int) where bit 4 (value 16) means bold,
    and a `font` string (e.g. ``THSarabunNewBold``) as a secondary signal.
    We accumulate character count weighted by text length so that a single bold
    keyword in an otherwise normal paragraph is not marked bold overall.
    """
    bold_chars = 0
    total_chars = 0
    for line in block_lines:
        for span in line.get("spans", []):
            text = span.get("text", "")
            n = len(text.strip())
            if not n:
                continue
            flags = span.get("flags", 0)
            font = span.get("font", "")
            is_bold = bool(flags & 16) or "bold" in font.lower() or font.lower().endswith("bd")
            total_chars += n
            if is_bold:
                bold_chars += n
    if total_chars == 0:
        return False
    return bold_chars / total_chars >= 0.5


def _extract_line_text_with_tabs(
    line: dict, page_margin_x: float
) -> tuple[str, list[dict]]:
    """Build line text from PyMuPDF spans, inserting \\t where the gap between
    consecutive spans exceeds PDF_TAB_GAP_THRESHOLD_PT.  Returns (text, tabs)
    where `tabs` lists detected tab-stop positions in twips relative to
    page_margin_x — suitable for `meta.layout.tabs`.
    """
    detected_tabs: list[dict] = []
    parts: list[str] = []
    prev_x_end: float | None = None

    for span in line.get("spans", []):
        text = span.get("text", "")
        span_bbox = span.get("bbox", [0, 0, 0, 0])
        if not text.strip():
            # A narrow empty span between two text spans is almost certainly a
            # Thai tone mark whose ToUnicode CMap entry is broken in the source
            # font.  Insert a zero-width sentinel so _join_thai_spans does NOT
            # insert a word space across the gap.
            span_w = (span_bbox[2] - span_bbox[0]) if len(span_bbox) >= 3 else 0.0
            if 0 < span_w < GHOST_TONE_MAX_WIDTH_PT and parts:
                parts.append(_GHOST_SENTINEL)
            continue
        x_start = span.get("origin", [0])[0]

        if prev_x_end is not None and (x_start - prev_x_end) > PDF_TAB_GAP_THRESHOLD_PT:
            parts.append("\t")
            tab_pos_twips = round((x_start - page_margin_x) * 20)
            if not any(abs(t["position"] - tab_pos_twips) < 40 for t in detected_tabs):
                detected_tabs.append({"align": "left", "position": tab_pos_twips})

        parts.append(text)
        prev_x_end = span_bbox[2] if len(span_bbox) >= 3 else x_start

    if not parts:
        return "", []

    # Group spans around \t separators so Thai combining-mark joining stays
    # within each tab-separated chunk.
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

    return "".join(groups), detected_tabs


def _compute_page_margin_x(text_dict: dict) -> float:
    """Estimate the page's left margin in points.  Uses the 10th-percentile of
    block x0 positions to be robust against the occasional outlier (page number
    pinned far left, etc.)."""
    x0_vals = sorted(
        b["bbox"][0] for b in text_dict.get("blocks", []) if b.get("type") == 0
    )
    if len(x0_vals) < 2:
        return 0.0
    return x0_vals[len(x0_vals) // 10]


def _is_pdf_header_block(
    raw_text: str,
    block_bbox: list | tuple,
    block_lines: list,
    page_height_pt: float,
    settings: object,
) -> bool:
    """Detect a Thai-legal header block by position OR font size.

    Headers are conventionally centred in Thai legal documents.  We mark a
    block as a header when:
    - The text is short (≤ pdf_header_max_chars) AND single-paragraph, AND
    - Either it sits within the top fraction of the page OR its average font
      size is at or above pdf_header_min_font_pt.
    Returns False for very long blocks regardless of position.
    """
    text = (raw_text or "").strip()
    if not text:
        return False
    if len(text) > int(getattr(settings, "pdf_header_max_chars", 120)):
        return False

    # Position-based: top of page
    top_fraction = float(getattr(settings, "pdf_header_top_fraction", 0.18))
    if page_height_pt > 0 and isinstance(block_bbox, (list, tuple)) and len(block_bbox) >= 4:
        try:
            y0 = float(block_bbox[1])
            if y0 / page_height_pt < top_fraction:
                return True
        except (TypeError, ValueError):
            pass

    # Font-based: notably larger than body text
    min_font_pt = float(getattr(settings, "pdf_header_min_font_pt", 14.0))
    sizes: list[float] = []
    for line in block_lines or []:
        for span in line.get("spans", []) if isinstance(line, dict) else []:
            size = span.get("size")
            if isinstance(size, (int, float)) and size > 0:
                sizes.append(float(size))
    if sizes and (sum(sizes) / len(sizes)) >= min_font_pt:
        return True

    return False


# ─────────────────────────────────────────────────────────────────────────────
# Image utilities
# ─────────────────────────────────────────────────────────────────────────────



# ─────────────────────────────────────────────────────────────────────────────
# Column-pair table reconstruction
# ─────────────────────────────────────────────────────────────────────────────

# Minimum number of rows that must share the same column x-cluster for us to
# treat the group as a table rather than coincidentally aligned paragraphs.
_COL_TABLE_MIN_ROWS = 3
# Two x0 positions are in the same cluster when closer than this many points.
_COL_CLUSTER_GAP_PT = 40.0
# Two blocks are considered "same row" when their y-midpoints differ by less
# than this fraction of the taller block's height.
_COL_ROW_Y_OVERLAP_FRAC = 0.5


def _merge_column_pair_tables(blocks: list[dict], page_index: int) -> list[dict]:
    """Detect side-by-side paragraph blocks that form an implicit table and
    replace them with a single table block.

    Algorithm:
    1. Cluster block x0 values into column bands.
    2. Sort all para blocks by y0 and scan for runs where blocks from ≥2
       distinct columns share overlapping y-bands — these are table rows.
    3. Consecutive matched-row runs (≥ _COL_TABLE_MIN_ROWS rows with ≥2 cols)
       become table blocks.
    """
    para_blocks = [b for b in blocks if b.get("type") == "paragraph" and b.get("bbox")]
    if not para_blocks:
        return blocks

    def _x0(b: dict) -> float:
        return float(b["bbox"][0])

    def _y0(b: dict) -> float:
        return float(b["bbox"][1])

    def _y1(b: dict) -> float:
        return float(b["bbox"][3])

    def _ymid(b: dict) -> float:
        return (_y0(b) + _y1(b)) / 2.0

    def _height(b: dict) -> float:
        return max(1.0, _y1(b) - _y0(b))

    def _y_overlaps(b1: dict, b2: dict) -> bool:
        # Two blocks share a row when their y-ranges intersect by > 30%.
        lo = max(_y0(b1), _y0(b2))
        hi = min(_y1(b1), _y1(b2))
        if hi <= lo:
            return False
        overlap = hi - lo
        return overlap / min(_height(b1), _height(b2)) > 0.30

    # ── Step 1: cluster x0 → column index ────────────────────────────────
    x0_vals = sorted(set(round(_x0(b)) for b in para_blocks))
    col_clusters: list[list[float]] = []
    for x in x0_vals:
        if col_clusters and x - col_clusters[-1][-1] < _COL_CLUSTER_GAP_PT:
            col_clusters[-1].append(float(x))
        else:
            col_clusters.append([float(x)])

    if len(col_clusters) < 2:
        return blocks  # Single column page.

    col_centers = [sum(c) / len(c) for c in col_clusters]

    def _col_idx(b: dict) -> int:
        x = _x0(b)
        return min(range(len(col_centers)), key=lambda i: abs(col_centers[i] - x))

    # ── Step 2: build "row slots" by grouping blocks with overlapping y ───
    # Sort all para blocks by y0 then by col to process top-to-bottom.
    sorted_blocks = sorted(para_blocks, key=lambda b: (_y0(b), _col_idx(b)))

    # Merge blocks that y-overlap into shared "row slots".
    # A slot tracks its y-extent and which col-indexed blocks fall in it.
    slots: list[dict] = []  # {"y0", "y1", "cols": {col_idx: [block]}}
    for b in sorted_blocks:
        placed = False
        for slot in reversed(slots):
            if slot["y1"] > _y0(b) and _y0(b) < slot["y1"]:
                # Check actual y-overlap, not just bounding box touch.
                representative = next(iter(next(iter(slot["cols"].values()))))
                if _y_overlaps(b, representative):
                    ci = _col_idx(b)
                    slot["cols"].setdefault(ci, []).append(b)
                    slot["y0"] = min(slot["y0"], _y0(b))
                    slot["y1"] = max(slot["y1"], _y1(b))
                    placed = True
                    break
        if not placed:
            slots.append({"y0": _y0(b), "y1": _y1(b), "cols": {_col_idx(b): [b]}})

    # ── Step 3: find runs of multi-column slots → table candidates ────────
    used_block_ids: set[int] = set()
    new_table_blocks: list[dict] = []
    tbl_counter = 900

    i = 0
    while i < len(slots):
        slot = slots[i]
        if len(slot["cols"]) < 2:
            i += 1
            continue

        # Start a run — collect consecutive multi-column slots whose y-bands
        # are within _COL_CLUSTER_GAP_PT of each other.
        run = [slot]
        j = i + 1
        while j < len(slots):
            nxt = slots[j]
            gap = nxt["y0"] - run[-1]["y1"]
            if gap > _COL_CLUSTER_GAP_PT:
                break
            # Accept single-column slots as continuation (rowspan cells) but
            # only if they y-touch the run.
            run.append(nxt)
            j += 1

        # Count how many slots in the run have ≥2 columns.
        multi_col_count = sum(1 for s in run if len(s["cols"]) >= 2)
        if multi_col_count >= _COL_TABLE_MIN_ROWS:
            all_cols = sorted(set(ci for s in run for ci in s["cols"]))
            rows_out: list[list[dict]] = []
            for s in run:
                row_cells: list[dict] = []
                for ci in all_cols:
                    col_bs = s["cols"].get(ci, [])
                    text = " ".join(
                        b.get("raw_text", "").replace("\n", " ").strip()
                        for b in col_bs
                    ).strip()
                    row_cells.append({"text": text, "colspan": 1, "rowspan": 1, "alignment": None})
                rows_out.append(row_cells)

            all_blocks_in_run = [b for s in run for col_bs in s["cols"].values() for b in col_bs]
            all_bboxes = [b["bbox"] for b in all_blocks_in_run]
            bbox = [
                min(bb[0] for bb in all_bboxes),
                min(bb[1] for bb in all_bboxes),
                max(bb[2] for bb in all_bboxes),
                max(bb[3] for bb in all_bboxes),
            ]
            first_ro = min(b.get("reading_order", 0) for b in all_blocks_in_run)

            html = build_table_html(rows_out)
            headers = [c["text"] for c in rows_out[0]] if rows_out else []
            body = [[c["text"] for c in r] for r in rows_out[1:]]
            raw_text = "\n".join("\t".join(c["text"] for c in r) for r in rows_out)

            new_table_blocks.append({
                "block_id": f"{page_index}-coltbl{tbl_counter}",
                "type": "table",
                "reading_order": first_ro,
                "raw_text": raw_text,
                "bbox": bbox,
                "confidence": 0.90,
                "flags": ["pdf_table", "column_pair"],
                "meta": {
                    "table": {"headers": headers, "rows": body, "cells": rows_out, "html": html, "text": raw_text},
                    "layout": {
                        "bbox": bbox, "reading_order": first_ro,
                        "alignment": "center", "indent_left": None,
                        "indent_first_line": None, "indent_hanging": None, "tabs": [],
                    },
                },
            })
            tbl_counter += 1
            for b in all_blocks_in_run:
                used_block_ids.add(id(b))

        i = j if j > i else i + 1

    if not new_table_blocks:
        return blocks

    result = [b for b in blocks if id(b) not in used_block_ids]
    result.extend(new_table_blocks)
    result.sort(key=lambda b: b.get("reading_order", 0))
    return result


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

    def _build_image_meta(
        self,
        img_path: Path,
        img_data: bytes,
        ext: str,
        document_id: str,
        width: int = 0,
        height: int = 0,
        source: str = "embedded",
        caption: str | None = None,
    ) -> dict:
        """Return the canonical ``meta.image`` payload for an image block.

        Produces ``src_path`` (absolute container path), ``src_url`` (the
        Laravel API route that serves the file), and ``data_uri`` when the
        image is small enough to inline.
        """
        filename = img_path.name
        data_uri: str | None = None
        if len(img_data) <= 200 * 1024:
            safe_ext = ext.lstrip(".").lower()
            if safe_ext in {"jpg", "jfif"}:
                safe_ext = "jpeg"
            from base64 import b64encode
            mime_map = {
                "png": "image/png", "jpeg": "image/jpeg", "gif": "image/gif",
                "webp": "image/webp", "bmp": "image/bmp", "tiff": "image/tiff",
            }
            mime = mime_map.get(safe_ext, "image/png")
            data_uri = f"data:{mime};base64,{b64encode(img_data).decode()}"

        return {
            "image_path": str(img_path),  # kept for backward compat
            "image": {
                "src_path": str(img_path),
                "src_url": f"/api/documents/{document_id}/images/{filename}",
                "data_uri": data_uri,
                "width": width or None,
                "height": height or None,
                "caption": caption,
            },
            "source": source,
        }

    # ── public API ───────────────────────────────────────────────────────────

    def extract(
        self, file_path: Path, source_type: str, document_id: str = "doc"
    ) -> list[dict]:
        if source_type == "docx":
            blocks = self._extract_docx_blocks(file_path, document_id)
            return [{"page_no": 1, "image_path": None, "source_kind": "docx", "blocks": blocks}]

        if source_type == "pdf_text":
            pages_data = self._extract_pdf_blocks(file_path, document_id)
            return [
                {"page_no": page_no, "image_path": None, "source_kind": "pdf_text", "blocks": blocks}
                for page_no, blocks in pages_data
            ]

        return []

    def extract_mixed_pdf(
        self,
        file_path: Path,
        page_classification: dict,
        document_id: str,
        ocr_pipeline: object | None,
        scan_results: list[dict] | None = None,
    ) -> list[dict]:
        """Extract a PDF whose pages may be a mix of text and scanned.

        page_classification is the dict returned by detect_file_type:
            {"text": [0, 1, ...], "scan": [3, 4, ...]}

        Text pages are handled via PyMuPDF; scan pages go through ocr_pipeline.
        Results are merged and returned in page order.
        """
        text_indices: set[int] = set(page_classification.get("text", []))
        scan_indices: set[int] = set(page_classification.get("scan", []))

        import fitz

        doc = fitz.open(file_path)
        page_count = doc.page_count
        doc.close()

        # Build per-page results
        pages_by_index: dict[int, dict] = {}

        if text_indices:
            text_pages_data = self._extract_pdf_blocks_selective(file_path, document_id, text_indices)
            for page_no, blocks in text_pages_data:
                pages_by_index[page_no - 1] = {
                    "page_no": page_no,
                    "image_path": None,
                    "source_kind": "pdf_text",
                    "blocks": blocks,
                }

        if scan_indices:
            resolved_scan_results = scan_results
            if resolved_scan_results is None:
                if ocr_pipeline is None:
                    raise RuntimeError("Mixed PDF scan extraction requested without an OCR pipeline")
                resolved_scan_results = ocr_pipeline.extract_scanned_pdf_selective(
                    file_path=file_path,
                    document_id=document_id,
                    page_indices=scan_indices,
                )
            for page in resolved_scan_results:
                idx = page["page_no"] - 1
                pages_by_index[idx] = page

        return [pages_by_index[i] for i in sorted(pages_by_index)]

    def _extract_pdf_blocks_selective(
        self, file_path: Path, document_id: str, page_indices: set[int]
    ) -> list[tuple[int, list[dict]]]:
        """Extract only the pages at the given 0-based indices."""
        if self._converter is not None:
            try:
                self._converter.convert(str(file_path))
            except Exception:
                pass

        import fitz

        doc = fitz.open(file_path)
        pages: list[tuple[int, list[dict]]] = []

        for page_index, page in enumerate(doc, start=1):
            if (page_index - 1) not in page_indices:
                continue
            blocks = self._extract_pdf_page_blocks(page, page_index, document_id)
            pages.append((page_index, blocks))

        doc.close()
        return pages

    # ── DOCX ─────────────────────────────────────────────────────────────────

    def _parse_numbering_xml(self, archive: zipfile.ZipFile, name_set: frozenset[str]) -> dict:
        """Parse word/numbering.xml and return numbering context."""
        numbering_path = "word/numbering.xml"
        if numbering_path not in name_set:
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
            name_set = frozenset(archive.namelist())

            # Build rId → zip-path map for embedded images
            rel_map: dict[str, str] = {}
            rel_path = "word/_rels/document.xml.rels"
            if rel_path in name_set:
                with archive.open(rel_path) as fh:
                    for rel in ET.parse(fh).getroot():
                        if rel.get("Type", "").endswith("/image"):
                            rid = rel.get("Id", "")
                            target = rel.get("Target", "").lstrip("./")
                            if not target.startswith("word/"):
                                target = "word/" + target
                            rel_map[rid] = target

            # Parse numbering definitions
            numbering_context = self._parse_numbering_xml(archive, name_set)

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
                        child, archive, rel_map, name_set, document_id, reading_order
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
        name_set: frozenset[str],
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
        if zip_path not in name_set:
            return None

        img_data = archive.read(zip_path)
        ext = Path(zip_path).suffix
        img_name = f"img-{reading_order:04d}"
        img_path = self._save_image(img_data, ext, document_id, img_name)

        return {
            "block_id": f"1-{reading_order}",
            "type": "image",
            "reading_order": reading_order,
            "raw_text": "",
            "bbox": None,
            "confidence": 1.0,
            "flags": [],
            "meta": self._build_image_meta(img_path, img_data, ext, document_id, source="docx_embedded"),
        }

    # ── PDF ──────────────────────────────────────────────────────────────────

    def _extract_pdf_blocks(
        self, file_path: Path, document_id: str
    ) -> list[tuple[int, list[dict]]]:
        # Pre-pass: try DoclingTableExtractor (TableFormer) for the whole file
        # so that per-page extraction can use proper colspan/rowspan structure.
        docling_tables_by_page: dict[int, list] = {}
        try:
            from app.services.table_extractor import DoclingTableExtractor
            from app.services.table_text_merger import build_cell_grid, merge_text_into_cells
            extractor = DoclingTableExtractor(fallback_to_fitz=False)
            if extractor.is_available():
                for tbl in extractor.extract_tables(file_path):
                    docling_tables_by_page.setdefault(tbl.page_no, []).append(tbl)
        except Exception:
            docling_tables_by_page = {}

        doc = fitz.open(file_path)
        pages: list[tuple[int, list[dict]]] = []

        for page_index, page in enumerate(doc, start=1):
            docling_page_tables = docling_tables_by_page.get(page_index)
            blocks = self._extract_pdf_page_blocks(
                page, page_index, document_id, docling_page_tables=docling_page_tables
            )
            pages.append((page_index, blocks))

        doc.close()
        return pages

    def _extract_pdf_page_blocks(
        self, page: object, page_index: int, document_id: str,
        docling_page_tables: list | None = None,
    ) -> list[dict]:
        blocks: list[dict] = []
        reading_order = 1

        # Detect table regions so overlapping text can be skipped.
        # Prefer docling-derived bboxes; fall back to PyMuPDF find_tables().
        if docling_page_tables:
            table_rects = [tbl.bbox for tbl in docling_page_tables]
            fitz_tables = None
        else:
            try:
                fitz_tables = page.find_tables()
                if not fitz_tables:
                    # Borderless / text-aligned tables have no ruling lines.
                    # Try the text-alignment strategy as a second pass.
                    fitz_tables = page.find_tables(strategy="text")
                table_rects = [t.bbox for t in fitz_tables] if fitz_tables else []
            except Exception:
                fitz_tables = None
                table_rects = []

        text_dict = page.get_text("dict")
        page_margin_x = _compute_page_margin_x(text_dict)

        # Pre-compute median line height for this page (used in spacing_before).
        line_heights: list[float] = []
        for b in text_dict.get("blocks", []):
            if b.get("type") == 0:
                for ln in b.get("lines", []):
                    bb = ln.get("bbox")
                    if bb and len(bb) >= 4:
                        h = bb[3] - bb[1]
                        if h > 0:
                            line_heights.append(h)
        line_heights.sort()
        median_line_height = line_heights[len(line_heights) // 2] if line_heights else 14.0

        prev_block_y1: float | None = None

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

                # Collect spans per-line so that:
                #   - combining-mark-only spans are not lost by premature .strip()
                #   - line boundaries are preserved as \n in the joined text
                #   - inter-span gaps > PDF_TAB_GAP_THRESHOLD_PT become \t (preserved
                #     by the normalizer thanks to thai_normalizer.py:203).
                # Normalization is applied per-line (before joining) so that the
                # whitespace-collapse pass cannot destroy \n separators.
                block_lines = block.get("lines", [])
                lines_text: list[str] = []
                detected_tabs_all: list[dict] = []
                # Track (text, y_mid, x0) so we can detect same-row columns.
                line_records: list[tuple[str, float, float]] = []
                for line in block_lines:
                    line_text, line_tabs = _extract_line_text_with_tabs(line, page_margin_x)
                    if line_text:
                        lbb = line.get("bbox") or [0, 0, 0, 0]
                        line_records.append((line_text,
                                             (lbb[1] + lbb[3]) / 2.0,
                                             float(lbb[0])))
                        for t in line_tabs:
                            if not any(abs(t["position"] - existing["position"]) < 40
                                       for existing in detected_tabs_all):
                                detected_tabs_all.append(t)

                # Merge lines that share the same visual row (y_mid within
                # median_line_height/2) but differ in x — these are table columns
                # placed side-by-side in the PDF.  Join them with \t so the tab
                # renderer shows the horizontal gap correctly.
                if line_records:
                    visual_rows: list[list[tuple[str, float, float]]] = []
                    for rec in line_records:
                        placed = False
                        for vrow in reversed(visual_rows):
                            ref_y = vrow[0][1]
                            if abs(rec[1] - ref_y) < median_line_height * 0.6:
                                vrow.append(rec)
                                placed = True
                                break
                        if not placed:
                            visual_rows.append([rec])

                    for vrow in visual_rows:
                        vrow.sort(key=lambda r: r[2])  # sort by x0
                        if len(vrow) > 1:
                            lines_text.append("\t".join(r[0] for r in vrow))
                            # Register a tab-stop at each column x position.
                            for r in vrow[1:]:
                                tab_pos = int((r[2] - page_margin_x) * 20)
                                if tab_pos > 0 and not any(
                                    abs(tab_pos - e["position"]) < 40
                                    for e in detected_tabs_all
                                ):
                                    detected_tabs_all.append({"align": "left", "position": tab_pos})
                        else:
                            lines_text.append(vrow[0][0])

                if lines_text:
                    joined = "\n".join(lines_text)
                    pdf_confidence, pdf_flags = score_text_pdf_block(joined)
                    layout = self._infer_pdf_paragraph_layout(block_lines, page.rect)
                    if detected_tabs_all:
                        layout["tabs"] = detected_tabs_all

                    # Spacing before: if the gap from the previous block's bottom
                    # exceeds 1.5× the median line height, record it.
                    block_y0 = block_rect[1]
                    if prev_block_y1 is not None:
                        gap_pt = block_y0 - prev_block_y1
                        if gap_pt > median_line_height * 1.5:
                            layout["spacing_before"] = int(gap_pt * 20)

                    # Force-center short top-of-page or large-font blocks
                    # (Thai legal convention for headers / titles).
                    from app.core.config import get_settings as _get_settings
                    _settings = _get_settings()
                    if layout.get("alignment") is None and _is_pdf_header_block(
                        joined, block_rect, block_lines, float(page.rect.height), _settings
                    ):
                        layout["alignment"] = "center"
                        pdf_flags = list(pdf_flags) + ["pdf_header_centered"]

                    meta: dict = {"layout": layout}
                    if _block_is_bold(block_lines):
                        meta["formatting"] = {"weight": "bold"}

                    blocks.append({
                        "block_id": f"{page_index}-{reading_order}",
                        "type": "paragraph",
                        "reading_order": reading_order,
                        "raw_text": joined,
                        "bbox": list(block_rect),
                        "confidence": pdf_confidence,
                        "flags": pdf_flags,
                        "meta": meta,
                    })
                    reading_order += 1

                prev_block_y1 = block_rect[3]

        # ── column-pair table detection ───────────────────────────────────
        # Some PDFs lay out tables as two (or more) independent PyMuPDF text
        # blocks placed side-by-side — one per column — with no ruling lines.
        # Neither Docling nor fitz.find_tables() detects these.  We reconstruct
        # them by clustering paragraph blocks whose y-ranges overlap into groups,
        # then checking whether multiple x-clusters exist within the group.
        if not docling_page_tables:
            blocks = _merge_column_pair_tables(blocks, page_index)

        # ── table blocks ──────────────────────────────────────────────────
        if docling_page_tables:
            # Use TableFormer-derived structure (proper colspan/rowspan).
            try:
                from app.services.table_text_merger import build_cell_grid, merge_text_into_cells
                word_cells = [
                    {
                        "bbox": (w[0], w[1], w[2], w[3]),
                        "text": w[4],
                    }
                    for w in (page.get_text("words") or [])
                ]
                for i, docling_tbl in enumerate(docling_page_tables):
                    merge_text_into_cells(docling_tbl, word_cells)
                    cell_grid = build_cell_grid(docling_tbl)
                    headers = [c["text"] for c in cell_grid[0]] if cell_grid else []
                    body = [[c["text"] for c in r] for r in cell_grid[1:]]
                    raw_text = "\n".join("\t".join(c["text"] for c in r) for r in cell_grid)
                    html = build_table_html(cell_grid)
                    blocks.append({
                        "block_id": f"{page_index}-tbl{reading_order + i}",
                        "type": "table",
                        "reading_order": reading_order + i,
                        "raw_text": raw_text,
                        "bbox": list(docling_tbl.bbox),
                        "confidence": 0.98,
                        "flags": ["pdf_table", "tableformer"],
                        "meta": {
                            "table": {
                                "headers": headers,
                                "rows": body,
                                "cells": cell_grid,
                                "html": html,
                                "text": raw_text,
                            },
                            "layout": {
                                "bbox": list(docling_tbl.bbox),
                                "reading_order": reading_order + i,
                                "alignment": "center", "indent_left": None,
                                "indent_first_line": None, "indent_hanging": None, "tabs": [],
                            },
                        },
                    })
            except Exception:
                # Fall through to fitz on any error
                for i, table in enumerate(page.find_tables() or []):
                    tb = self._extract_pdf_table(table, page, page_index, reading_order + i)
                    if tb:
                        blocks.append(tb)
        else:
            # Reuse fitz_tables detected above for text masking — avoid a
            # second find_tables() call which could pick a different strategy.
            for i, table in enumerate(fitz_tables or []):
                tb = self._extract_pdf_table(table, page, page_index, reading_order + i)
                if tb:
                    blocks.append(tb)

        # Renumber reading_order by spatial y-position so that table blocks
        # inserted from Docling/fitz (appended after paragraph blocks) are
        # interleaved at the correct document position.
        blocks_with_bbox = [b for b in blocks if b.get("bbox")]
        blocks_no_bbox   = [b for b in blocks if not b.get("bbox")]
        blocks_with_bbox.sort(key=lambda b: (float(b["bbox"][1]), float(b["bbox"][0])))
        for idx, b in enumerate(blocks_with_bbox, start=1):
            b["reading_order"] = idx
        for idx, b in enumerate(blocks_no_bbox, start=len(blocks_with_bbox) + 1):
            b["reading_order"] = idx
        blocks = blocks_with_bbox + blocks_no_bbox

        # Fallback for pages with no structured content
        if not blocks:
            raw = page.get_text("text")
            lines = [ln.strip() for ln in raw.splitlines() if ln.strip()]
            for idx, line in enumerate(lines, start=1):
                line_confidence, line_flags = score_text_pdf_block(line)
                blocks.append({
                    "block_id": f"{page_index}-{idx}",
                    "type": "paragraph",
                    "reading_order": idx,
                    "raw_text": line,
                    "bbox": None,
                    "confidence": line_confidence,
                    "flags": line_flags,
                })

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

            return {
                "block_id": f"{page_index}-{reading_order}",
                "type": "image",
                "reading_order": reading_order,
                "raw_text": "",
                "bbox": list(block.get("bbox", [])),
                "confidence": 1.0,
                "flags": [],
                "meta": self._build_image_meta(
                    img_path, img_bytes, ext, document_id,
                    width=width, height=height, source="pdf_embedded",
                ),
            }
        except Exception:
            return None

    # ── table ─────────────────────────────────────────────────────────────────

    def _extract_pdf_table(
        self, table: object, page: object, page_index: int, reading_order: int
    ) -> dict | None:
        try:
            table_data = table.extract()
            if not table_data:
                return None

            # P5: attempt per-cell span extraction so _join_thai_spans() can fix
            # combining-mark ordering.  table.cells is a flat list of Rect objects
            # in row-major order (matches table.extract() iteration order).
            cell_rects: list | None = None
            try:
                raw_cells = table.cells  # available in PyMuPDF 1.23+
                if raw_cells and len(raw_cells) == sum(len(r) for r in table_data):
                    cell_rects = raw_cells
            except Exception:
                cell_rects = None

            rows: list[list[dict]] = []
            cell_idx = 0
            for row_data in table_data:
                row: list[dict] = []
                for c in row_data:
                    cell_text = self._extract_pdf_cell_text(
                        page, cell_rects[cell_idx] if cell_rects else None, c
                    )
                    row.append({
                        "text": cell_text,
                        "colspan": 1, "rowspan": 1, "alignment": None,
                    })
                    cell_idx += 1
                if row:
                    rows.append(row)

            if not rows:
                return None

            headers  = [c["text"] for c in rows[0]]
            body     = [[c["text"] for c in r] for r in rows[1:]]
            html     = build_table_html(rows)
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
                        "alignment": "center", "indent_left": None,
                        "indent_first_line": None, "indent_hanging": None, "tabs": [],
                    },
                },
            }
        except Exception:
            return None

    def _extract_pdf_cell_text(
        self, page: object, cell_rect: object | None, fallback_text: str | None
    ) -> str:
        """Extract cell text with span-level awareness for Thai combining marks.

        When a cell rect is available, re-extracts the text using
        page.get_text("dict", clip=cell_rect) so that _join_thai_spans() can fix
        combining-mark ordering.  Falls back to normalising the pre-assembled
        string from table.extract() if the span-level path fails or is unavailable.
        """
        if cell_rect is not None:
            try:
                text_dict = page.get_text("dict", clip=cell_rect)
                spans: list[str] = []
                for blk in text_dict.get("blocks", []):
                    if blk.get("type") != 0:
                        continue
                    for ln in blk.get("lines", []):
                        for sp in ln.get("spans", []):
                            t = sp.get("text", "")
                            if t.strip():
                                spans.append(t)
                if spans:
                    return _join_thai_spans(spans)
            except Exception:
                pass
        # Fallback: return the pre-assembled string verbatim (normalization happens in block_builder)
        return str(fallback_text or "").strip()

    @staticmethod
    def _infer_pdf_paragraph_layout(lines: list, page_rect: object) -> dict:
        """Infer paragraph layout from per-line x-positions in a PyMuPDF block.

        Returns a layout dict compatible with the DOCX layout schema used by
        block_builder.normalize_layout().  All measurements are in twips
        (1/20 pt) to match the DOCX encoding convention.

        What can be inferred:
        - indent_left: position of continuation lines relative to page left edge
        - indent_hanging: first line is further LEFT than continuation lines
        - indent_first_line: first line is further RIGHT than continuation lines

        What cannot be trusted:
        - alignment (center/justify/right) — needs right-edge analysis
        - tab stops — PDFs don't encode them; only visual positions exist
        - semantic meaning (numbered list vs. block quote vs. plain indent)
        """
        # Collect x0/x1 positions only from lines that have actual spans.
        line_bboxes: list[tuple[float, float]] = []
        for line in lines:
            if line.get("spans"):
                bbox = line.get("bbox")
                if bbox and len(bbox) >= 4:
                    line_bboxes.append((float(bbox[0]), float(bbox[2])))

        # Page geometry
        try:
            page_left  = float(page_rect[0])
            page_right = float(page_rect[2])
            page_width = page_right - page_left
        except Exception:
            page_left = 0.0
            page_right = 595.0
            page_width = 595.0

        if not line_bboxes:
            return {
                "bbox": None, "reading_order": None, "alignment": None,
                "indent_left": None, "indent_first_line": None, "indent_hanging": None,
                "tabs": [], "spacing_before": None, "spacing_after": None, "line_spacing": None,
            }

        x0s = [bx0 - page_left for bx0, _ in line_bboxes]
        x1s = [bx1 - page_left for _, bx1 in line_bboxes]

        first_x0 = x0s[0]
        rest_x0s = x0s[1:] if len(x0s) > 1 else [first_x0]
        avg_rest = sum(rest_x0s) / len(rest_x0s)

        # Threshold from settings (default 2pt) — captures fine Thai first-line
        # indents that the legacy 5pt floor dropped silently.
        from app.core.config import get_settings as _get_settings
        INDENT_THRESHOLD_PT = float(_get_settings().pdf_indent_threshold_pt)
        ALIGN_TOLERANCE     = 0.05  # 5% of page width
        diff_pt = first_x0 - avg_rest

        indent_left       = int(avg_rest * 20)
        indent_hanging    = int(-diff_pt * 20) if diff_pt < -INDENT_THRESHOLD_PT else None
        indent_first_line = int(diff_pt * 20)  if diff_pt > INDENT_THRESHOLD_PT  else None

        # ── Alignment detection ───────────────────────────────────────────
        # Right offsets: distance from each line's right edge to the page right.
        right_offsets = [page_width - x1 for x1 in x1s]
        left_offsets  = x0s

        def _all_close(values: list[float], tol_frac: float) -> bool:
            if len(values) < 2:
                return True
            mn, mx = min(values), max(values)
            return (mx - mn) / page_width < tol_frac

        # Center: both left AND right offsets are approximately equal across lines
        if (_all_close(left_offsets, ALIGN_TOLERANCE)
                and _all_close(right_offsets, ALIGN_TOLERANCE)
                and abs(sum(left_offsets) / len(left_offsets)
                        - sum(right_offsets) / len(right_offsets)) / page_width < ALIGN_TOLERANCE):
            alignment = "center"
        # Justify: non-last lines have small right offset; last line may be short
        elif (len(right_offsets) > 1
              and all(r / page_width < ALIGN_TOLERANCE for r in right_offsets[:-1])
              and _all_close(left_offsets, ALIGN_TOLERANCE)):
            alignment = "justify"
        # Right: all lines have small right offset; left offsets vary
        elif all(r / page_width < ALIGN_TOLERANCE for r in right_offsets):
            alignment = "right"
        else:
            alignment = None

        return {
            "bbox": None,
            "reading_order": None,
            "alignment": alignment,
            "indent_left": indent_left,
            "indent_first_line": indent_first_line,
            "indent_hanging": indent_hanging,
            "tabs": [],
            "spacing_before": None,
            "spacing_after": None,
            "line_spacing": None,
        }

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
            text = numbering_info["prefix"] + " " + text
            layout = numbering_info["layout"]
        
        if text.strip() == "":
            return None
            
        docx_confidence, docx_flags = score_docx_block(text)
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
                numbering_ilvl=numbering_info["meta"]["ilvl"] if numbering_info else None,
            ),
            "reading_order": reading_order,
            "raw_text": text,
            "bbox": None,
            "confidence": docx_confidence,
            "flags": docx_flags,
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
                    # else: vMerge continuation with no tracked restart (malformed DOCX).
                    # Advance col but do not write next_merges — remaining columns stay
                    # correctly indexed and the cell is silently skipped as intended.
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
        # Detect images inside this cell (w:drawing contains r:embed or v:imagedata)
        _REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
        has_image = any(
            node.get(f"{{{_REL_NS}}}embed") or (
                (node.tag.split("}", 1)[-1] if "}" in node.tag else node.tag) == "imagedata"
                and node.get(f"{{{_REL_NS}}}id")
            )
            for p in cell.findall("w:p", self.NAMESPACES)
            for node in p.iter()
        )
        return {
            "text": text, "colspan": colspan, "rowspan": 1,
            "alignment": self._extract_cell_alignment(cell),
            "v_merge_state": v_merge_state,
            "has_image": has_image,
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
        html     = build_table_html(rows)
        raw_text = "\n".join("\t".join(c for c in r if c) for r in flattened_rows)
        return {"headers": headers, "rows": body, "cells": rows, "html": html, "text": raw_text}

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
        # Top-level legal section headers: มาตรา / ข้อ.
        if re.match(r"^(มาตรา\s+[๐-๙0-9]+(?:/[๐-๙0-9]+)?)\b", stripped):
            return "section_header"
        if re.match(r"^ข้อ\s*[๐-๙0-9]+(?:\.[๐-๙0-9]+)*", stripped):
            return "section_header"
        # List items: parenthesised markers (numbers OR Thai/Latin letters),
        # multi-level numerics, single-level numerics, dashes, bullets.
        if re.match(r"^(\(([๐-๙0-9]+|[ก-ฮ]|[a-zA-Z])\)|[๐-๙0-9]+(?:\.[๐-๙0-9]+)+\.?\s|[๐-๙0-9]+\.\s|-|•)", stripped):
            return "list_item"
        return "paragraph"

    @staticmethod
    def _local_name(tag: str) -> str:
        return tag.split("}", 1)[-1]

    def _word_attr(self, node: ET.Element | None, name: str) -> str | None:
        if node is None:
            return None
        return node.get(f"{{{self.WORD_NS}}}{name}")

    def _parse_int_attr(self, node: ET.Element | None, name: str) -> int | None:
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
