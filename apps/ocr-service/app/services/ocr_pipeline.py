from __future__ import annotations

import threading
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
import shutil
import subprocess
import tempfile
import warnings

import fitz
import cv2
import numpy as np

from app.services.html_renderer import build_table_html

# Suppress torch pin_memory warnings when no GPU is available
warnings.filterwarnings("ignore", message=".*pin_memory.*")

# ─────────────────────────────────────────────────────────────────────────────
# Module-level singleton for the EasyOCR reader.
# Loading the Thai model takes several seconds and ~2 GB of RAM.  Initialising
# once at service startup (rather than on first request) keeps the first scan
# request from timing out.
# ─────────────────────────────────────────────────────────────────────────────

_pipeline_instance: "OcrPipeline | None" = None


def get_ocr_pipeline(data_root: Path | None = None) -> "OcrPipeline":
    global _pipeline_instance
    if _pipeline_instance is None:
        _pipeline_instance = OcrPipeline(data_root=data_root or Path("/tmp"))
    return _pipeline_instance


def warmup_ocr() -> None:
    """Pre-load the EasyOCR model.  Call this from the FastAPI startup event."""
    pipeline = get_ocr_pipeline()
    pipeline._ensure_reader()


class OcrPipeline:
    def __init__(self, data_root: Path) -> None:
        self.data_root = data_root
        self._reader = None
        # On GPU: allow N concurrent readtext() calls — PyTorch queues CUDA work
        # so multiple callers share the GPU without corrupting model state.
        # On CPU: fall back to a plain Lock (N=1) to avoid thrashing.
        self._ocr_lock = self._make_ocr_lock()
        # Memoise _detect_table_regions results keyed by image path string.
        self._table_region_cache: dict[str, list[dict]] = {}

    @staticmethod
    def _make_ocr_lock() -> threading.Semaphore | threading.Lock:
        from app.core.config import get_settings
        try:
            import torch
            if torch.cuda.is_available():
                n = get_settings().ocr_gpu_concurrency
                return threading.Semaphore(max(1, n))
        except Exception:
            pass
        return threading.Lock()

    def is_ready(self) -> bool:
        return self._reader is not None

    @property
    def device(self) -> str:
        """Return "cuda" if EasyOCR is using GPU, "cpu" otherwise."""
        try:
            import torch
            return "cuda" if torch.cuda.is_available() else "cpu"
        except Exception:
            return "cpu"

    def extract_scanned_pdf_selective(
        self, file_path: Path, document_id: str, page_indices: set[int]
    ) -> list[dict]:
        """OCR only the pages at the given 0-based indices (concurrent)."""
        from app.core.config import get_settings
        settings = get_settings()

        doc = fitz.open(file_path)
        fitz_pages = list(doc)
        doc_ref = doc

        target_page_nos = [i + 1 for i in page_indices if i < len(fitz_pages)]

        def _process_page(page_index: int) -> dict:
            page = fitz_pages[page_index - 1]
            image_path = self._render_page_image_adaptive(page, document_id, page_index, settings)
            lines = self._ocr_image(image_path)
            table_regions = self._detect_table_regions(image_path)
            blocks = self._group_ocr_results_by_tables(lines, table_regions, page_index)
            embedded = self._extract_embedded_images(page, doc_ref, document_id, page_index)
            blocks.extend(embedded)
            return {"page_no": page_index, "image_path": str(image_path), "source_kind": "pdf_scan", "blocks": blocks}

        pages: list[dict] = []
        with ThreadPoolExecutor(max_workers=settings.ocr_page_workers) as pool:
            futures = {pool.submit(_process_page, pno): pno for pno in target_page_nos}
            for future in as_completed(futures):
                pages.append(future.result())

        doc_ref.close()
        pages.sort(key=lambda p: p["page_no"])
        return pages

    def extract_scanned_pdf(self, file_path: Path, document_id: str) -> list[dict]:
        """Extract scanned PDF with EasyOCR.

        OCRmyPDF pre-pass is opt-in (``enable_ocrmypdf_prepass`` in config) —
        it adds 5-10× latency for marginal benefit on Thai legal scans.
        Pages are rasterised concurrently via a thread pool; OCR calls are
        serialised by ``_ocr_lock`` so the GPU model is never called re-entrantly.
        """
        from app.core.config import get_settings
        settings = get_settings()

        if settings.enable_ocrmypdf_prepass:
            ocrmypdf_result = self._try_ocrmypdf(file_path, document_id)
            if ocrmypdf_result is not None:
                from app.services.docling_service import DoclingService
                docling = DoclingService(data_root=self.data_root)
                pages = docling.extract(file_path=ocrmypdf_result, source_type="pdf_text", document_id=document_id)
                for page in pages:
                    page["image_path"] = str(self._render_page_image_from_pdf(ocrmypdf_result, page["page_no"], document_id))
                    for block in page.get("blocks", []):
                        flags = list(block.get("flags", []))
                        if "ocrmypdf_scan" not in flags:
                            flags.append("ocrmypdf_scan")
                        block["flags"] = flags
                return pages

        doc = fitz.open(file_path)
        page_count = doc.page_count
        # Pre-extract fitz pages while doc is open (not thread-safe to share).
        fitz_pages = list(doc)
        doc_ref = doc  # keep alive for embedded image extraction

        def _process_page(page_index: int) -> dict:
            page = fitz_pages[page_index - 1]
            image_path = self._render_page_image_adaptive(page, document_id, page_index, settings)
            lines = self._ocr_image(image_path)
            table_regions = self._detect_table_regions(image_path)
            blocks = self._group_ocr_results_by_tables(lines, table_regions, page_index)
            embedded = self._extract_embedded_images(page, doc_ref, document_id, page_index)
            blocks.extend(embedded)
            return {"page_no": page_index, "image_path": str(image_path), "source_kind": "pdf_scan", "blocks": blocks}

        pages: list[dict] = []
        with ThreadPoolExecutor(max_workers=settings.ocr_page_workers) as pool:
            futures = {pool.submit(_process_page, i): i for i in range(1, page_count + 1)}
            for future in as_completed(futures):
                pages.append(future.result())

        doc_ref.close()
        pages.sort(key=lambda p: p["page_no"])
        return pages

    def _try_ocrmypdf(self, file_path: Path, document_id: str) -> Path | None:
        """Run OCRmyPDF to create a searchable PDF. Returns path to output file or None on failure."""
        try:
            output_dir = self.data_root / "intermediate" / document_id
            output_dir.mkdir(parents=True, exist_ok=True)
            output_path = output_dir / "ocrmypdf_searchable.pdf"

            # Skip if already processed
            if output_path.exists():
                return output_path

            cmd = [
                "ocrmypdf",
                "--language", "tha+eng",
                "--deskew",
                "--rotate-pages",
                "--clean",
                "--optimize", "0",
                "--jobs", "1",
                str(file_path),
                str(output_path),
            ]

            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=300,
            )

            if result.returncode == 0 and output_path.exists():
                return output_path

            # OCRmyPDF may exit with warning codes but still produce output
            if output_path.exists() and output_path.stat().st_size > 1024:
                return output_path

            return None
        except (subprocess.TimeoutExpired, FileNotFoundError, Exception):
            return None

    def _render_page_image_from_pdf(self, file_path: Path, page_no: int, document_id: str) -> Path:
        """Render a page image from a PDF file."""
        page_dir = self.data_root / "pages" / document_id
        page_dir.mkdir(parents=True, exist_ok=True)
        output_path = page_dir / f"page-{page_no}.png"

        if output_path.exists():
            return output_path

        doc = fitz.open(file_path)
        page = doc[page_no - 1]
        pix = page.get_pixmap(matrix=fitz.Matrix(2.0, 2.0))
        pix.save(output_path)
        doc.close()
        return output_path

    def _detect_table_regions(self, image_path: Path) -> list[dict]:
        """Detect table regions in an image using OpenCV.

        Requires BOTH horizontal AND vertical lines to be present (bitwise AND)
        so that underlines / paragraph rules don't trigger false-positive tables.
        Set enable_ocr_table_detection=False in config to skip entirely.
        Results are memoised per image path so re-OCR passes skip the CV work.
        """
        cache_key = str(image_path)
        if cache_key in self._table_region_cache:
            return self._table_region_cache[cache_key]

        from app.core.config import get_settings
        if not get_settings().enable_ocr_table_detection:
            self._table_region_cache[cache_key] = []
            return []
        try:
            image = cv2.imread(str(image_path))
            if image is None:
                return []

            gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
            # Invert so dark scan lines become white foreground pixels for the
            # morphology passes below; with a non-inverted mask, the background
            # becomes the dominant contour and valid tables get rejected as
            # "whole page" noise.
            _, binary = cv2.threshold(gray, 150, 255, cv2.THRESH_BINARY_INV)

            horizontal_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (40, 1))
            vertical_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (1, 40))

            horizontal_lines = cv2.morphologyEx(binary, cv2.MORPH_OPEN, horizontal_kernel)
            vertical_lines = cv2.morphologyEx(binary, cv2.MORPH_OPEN, vertical_kernel)

            # Require at least some genuine H/V intersections, otherwise
            # underlines or aligned text columns can masquerade as a table.
            intersections = cv2.bitwise_and(horizontal_lines, vertical_lines)
            if cv2.countNonZero(intersections) == 0:
                self._table_region_cache[cache_key] = []
                return []

            # Once the page has passed the H+V gate, contour the union of the
            # recovered line masks so the bounding boxes cover the full table
            # extent rather than tiny intersection dots.
            combined = cv2.bitwise_or(horizontal_lines, vertical_lines)
            combined = cv2.dilate(combined, cv2.getStructuringElement(cv2.MORPH_RECT, (5, 5)))

            contours, _ = cv2.findContours(combined, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

            img_h, img_w = gray.shape
            table_regions = []
            for contour in contours:
                x, y, w, h = cv2.boundingRect(contour)
                # Real tables are large; reject small noise regions.
                if w < 200 or h < 100:
                    continue
                # Reject detections that cover most of the page — those are background noise.
                if w * h > 0.70 * img_w * img_h:
                    continue
                table_regions.append({
                    "x": float(x),
                    "y": float(y),
                    "width": float(w),
                    "height": float(h),
                })

            self._table_region_cache[cache_key] = table_regions
            return table_regions
        except Exception:
            self._table_region_cache[cache_key] = []
            return []

    def _group_ocr_results_by_tables(self, lines: list[dict], table_regions: list[dict], page_index: int) -> list[dict]:
        """Group OCR results by detected table regions and create table blocks."""
        blocks: list[dict] = []
        used_line_indices = set()
        reading_order = 1

        # Process each detected table region
        for region in table_regions:
            table_lines, new_used_indices = self._collect_lines_in_region(lines, region, used_line_indices)

            if table_lines:
                table_block = self._create_table_block_from_lines(table_lines, page_index, reading_order)
                if table_block:
                    blocks.append(table_block)
                    reading_order += 1
                    used_line_indices = new_used_indices
                else:
                    sorted_region_lines = sorted(table_lines, key=lambda line: (line["bbox"][1], line["bbox"][0]))
                    region_confidence = sorted_region_lines[0].get("table_confidence")
                    rejection_reason = str(sorted_region_lines[0].get("table_detection_reason") or "low_table_confidence")
                    for idx, line in enumerate(sorted_region_lines, start=reading_order):
                        flags = ["ocr_scan", "table_region_rejected"]
                        blocks.append({
                            "block_id": f"{page_index}-{idx}",
                            "type": "paragraph",
                            "reading_order": idx,
                            "raw_text": line["text"],
                            "bbox": line["bbox"],
                            "confidence": line["confidence"],
                            "flags": flags,
                            "meta": {
                                "table_confidence": region_confidence,
                                "table_detection_reason": rejection_reason,
                                "layout": {
                                    "bbox": line["bbox"],
                                    "reading_order": idx,
                                    "alignment": None,
                                    "indent_left": None,
                                    "indent_first_line": None,
                                    "indent_hanging": None,
                                    "tabs": [],
                                },
                            },
                        })
                    reading_order += len(table_lines)
                    used_line_indices = new_used_indices

        # Add remaining lines as regular blocks
        reading_order = self._add_remaining_lines(blocks, lines, used_line_indices, page_index, reading_order)

        return blocks

    def _collect_lines_in_region(self, lines: list[dict], region: dict, used_indices: set) -> tuple:
        """Collect lines that fall within a table region."""
        table_lines = []
        new_used = set(used_indices)

        for idx, line in enumerate(lines):
            if idx in used_indices:
                continue
            if self._bbox_in_region(line["bbox"], region):
                table_lines.append(line)
                new_used.add(idx)

        return table_lines, new_used

    def _add_remaining_lines(self, blocks: list[dict], lines: list[dict], used_indices: set, page_index: int, start_order: int) -> int:
        """Add remaining lines as regular paragraph blocks."""
        reading_order = start_order

        for idx, line in enumerate(lines):
            if idx not in used_indices:
                blocks.append({
                    "block_id": f"{page_index}-{reading_order}",
                    "type": "paragraph" if reading_order > 1 else "title",
                    "reading_order": reading_order,
                    "raw_text": line["text"],
                    "bbox": line["bbox"],
                    "confidence": line["confidence"],
                    "flags": ["ocr_scan"],
                })
                reading_order += 1

        return reading_order

    def _bbox_in_region(self, bbox: list, region: dict) -> bool:
        """Check if a bounding box is within a table region."""
        if not bbox:
            return False

        x0, y0, x1, y1 = bbox
        region_x = region["x"]
        region_y = region["y"]
        region_x1 = region_x + region["width"]
        region_y1 = region_y + region["height"]

        center_x = (x0 + x1) / 2
        center_y = (y0 + y1) / 2

        return region_x <= center_x <= region_x1 and region_y <= center_y <= region_y1

    def _create_table_block_from_lines(self, lines: list[dict], page_index: int, reading_order: int) -> dict | None:
        """Create a table block from grouped OCR lines.

        Returns None (so lines fall back to paragraphs) when the detected region
        has fewer than 2 rows or fewer than 2 columns — single-axis structures
        are not tables.
        """
        if not lines:
            return None

        sorted_lines = sorted(lines, key=lambda l: (l["bbox"][1], l["bbox"][0]))
        rows = self._group_lines_into_rows(sorted_lines)

        if len(rows) < 2:
            return None

        table_rows = self._convert_rows_to_cells(rows)

        if not table_rows:
            return None

        max_cols = max((len(r) for r in table_rows), default=0)
        if max_cols < 2:
            return None

        table_confidence, detection_reason = self._score_table_structure(table_rows, rows)
        if table_confidence < 0.75:
            for line in lines:
                line["table_confidence"] = table_confidence
                line["table_detection_reason"] = detection_reason
            return None

        return self._build_table_block(
            table_rows,
            sorted_lines,
            page_index,
            reading_order,
            table_confidence=table_confidence,
            detection_reason=detection_reason,
        )

    def _group_lines_into_rows(self, sorted_lines: list[dict]) -> list[list[dict]]:
        """Group sorted lines into table rows using adaptive 1D mean-gap y-clustering.

        Adaptive threshold: the mean of all consecutive y-position gaps tells us
        the typical inter-line spacing for this page.  A gap larger than
        ``mean_gap * 0.7`` (min 4 pt) marks a row boundary.  This is far more
        robust than the previous fixed 30-px threshold which broke on dense
        Thai text (small gaps) or widely-spaced headers (large gaps).
        """
        if not sorted_lines:
            return []

        ys = [float(line["bbox"][1]) for line in sorted_lines]
        unique_ys = sorted(set(ys))

        if len(unique_ys) <= 1:
            return [list(sorted_lines)]

        gaps = [unique_ys[i + 1] - unique_ys[i] for i in range(len(unique_ys) - 1)]
        mean_gap = sum(gaps) / len(gaps)
        row_threshold = max(mean_gap * 0.7, 4.0)

        rows: list[list[dict]] = []
        current_row: list[dict] = []
        last_y: float | None = None

        for line in sorted_lines:
            y = float(line["bbox"][1])
            if last_y is not None and (y - last_y) > row_threshold:
                if current_row:
                    rows.append(current_row)
                current_row = []
            current_row.append(line)
            last_y = y

        if current_row:
            rows.append(current_row)

        return rows

    def _convert_rows_to_cells(self, rows: list[list[dict]]) -> list[list[dict]]:
        """Convert grouped lines into table cell structure.

        Uses adaptive x-gap clustering within each row to assign column positions,
        then propagates colspan/rowspan by comparing cell bboxes across rows.
        """
        if not rows:
            return []

        # Step 1: assign columns by adaptive x-clustering per row.
        table_rows: list[list[dict]] = []
        for row_lines in rows:
            sorted_cells = sorted(row_lines, key=lambda l: float(l["bbox"][0]))
            xs = [float(c["bbox"][0]) for c in sorted_cells]
            unique_xs = sorted(set(xs))
            if len(unique_xs) > 1:
                x_gaps = [unique_xs[i + 1] - unique_xs[i] for i in range(len(unique_xs) - 1)]
                mean_x_gap = sum(x_gaps) / len(x_gaps)
                x_threshold = max(mean_x_gap * 0.5, 8.0)
            else:
                x_threshold = 8.0

            merged: list[dict] = []
            acc: list[dict] = []
            last_x: float | None = None
            for cell_line in sorted_cells:
                x = float(cell_line["bbox"][0])
                if last_x is not None and (x - last_x) > x_threshold:
                    if acc:
                        merged.append(acc)
                    acc = []
                acc.append(cell_line)
                last_x = x
            if acc:
                merged.append(acc)

            row: list[dict] = []
            for group in merged:
                texts = [g["text"] for g in group if g.get("text")]
                bbox = self._merge_bboxes([g["bbox"] for g in group])
                row.append({
                    "text": " ".join(texts),
                    "colspan": 1,
                    "rowspan": 1,
                    "alignment": None,
                    "_bbox": bbox,
                })
            if row:
                table_rows.append(row)

        # Step 2: naive rowspan detection — if a cell bbox spans into the next row's
        # y-range and there is no cell in the next row that covers the same x-range,
        # increment its rowspan.
        for r_idx in range(len(table_rows) - 1):
            next_row = table_rows[r_idx + 1]
            for cell in table_rows[r_idx]:
                if not cell.get("_bbox"):
                    continue
                cx0, cy0, cx1, cy1 = cell["_bbox"]
                # Check if any next-row cell x-overlaps with this cell
                covered = any(
                    nc.get("_bbox") and not (nc["_bbox"][2] <= cx0 or nc["_bbox"][0] >= cx1)
                    for nc in next_row
                )
                if not covered and cy1 > (next_row[0].get("_bbox") or [0, 0, 0, 0])[1]:
                    cell["rowspan"] = cell.get("rowspan", 1) + 1

        return table_rows

    def _build_table_block(
        self,
        table_rows: list[list[dict]],
        sorted_lines: list[dict],
        page_index: int,
        reading_order: int,
        table_confidence: float,
        detection_reason: str,
    ) -> dict:
        """Build complete table block structure."""
        headers = [cell["text"] for cell in table_rows[0]] if table_rows else []
        body = [[cell["text"] for cell in row] for row in table_rows[1:]] if len(table_rows) > 1 else []
        clean_rows = [[{k: v for k, v in cell.items() if k != "_bbox"} for cell in row] for row in table_rows]
        html = build_table_html(clean_rows)
        raw_text = "\n".join("\t".join(cell["text"] for cell in row) for row in clean_rows)
        merged_bbox = self._merge_bboxes([line["bbox"] for line in sorted_lines])

        return {
            "block_id": f"{page_index}-{reading_order}",
            "type": "table",
            "reading_order": reading_order,
            "raw_text": raw_text,
            "bbox": merged_bbox,
            "confidence": float(np.mean([line["confidence"] for line in sorted_lines])),
            "flags": ["ocr_scan", "table_detected"],
            "meta": {
                "table_confidence": table_confidence,
                "table_detection_reason": detection_reason,
                "table": {
                    "headers": headers,
                    "rows": body,
                    "cells": clean_rows,
                    "html": html,
                    "text": raw_text,
                },
                "layout": {
                    "bbox": merged_bbox,
                    "reading_order": reading_order,
                    "alignment": None,
                    "indent_left": None,
                    "indent_first_line": None,
                    "indent_hanging": None,
                    "tabs": [],
                },
            },
        }

    def _score_table_structure(self, table_rows: list[list[dict]], source_rows: list[list[dict]]) -> tuple[float, str]:
        """Return a confidence score and reason for OCR-derived table structure."""
        if len(table_rows) < 2:
            return 0.0, "insufficient_rows"

        column_counts = [len(row) for row in table_rows if row]
        if not column_counts:
            return 0.0, "empty_rows"

        modal_cols = max(set(column_counts), key=column_counts.count)
        if modal_cols < 2:
            return 0.0, "insufficient_columns"

        stable_row_ratio = sum(1 for count in column_counts if count == modal_cols) / len(column_counts)
        if stable_row_ratio < 0.70:
            return 0.45, "unstable_column_count"

        first_row = next((row for row in table_rows if len(row) == modal_cols), table_rows[0])
        anchor_positions = [self._cell_anchor_x(cell) for cell in first_row]
        if any(anchor is None for anchor in anchor_positions):
            return 0.55, "missing_column_anchors"

        aligned_rows = 0
        paragraph_like_rows = 0
        for row_cells, raw_lines in zip(table_rows, source_rows):
            if len(row_cells) == modal_cols:
                row_anchors = [self._cell_anchor_x(cell) for cell in row_cells]
                if all(anchor is not None for anchor in row_anchors):
                    drift = max(abs(float(anchor) - float(ref)) for anchor, ref in zip(row_anchors, anchor_positions))
                    if drift <= 24.0:
                        aligned_rows += 1

            region_width = self._row_region_width(raw_lines)
            if region_width > 0:
                widest_cell = max((self._cell_width(cell) for cell in row_cells), default=0.0)
                if widest_cell / region_width > 0.85 or (len(row_cells) == 1 and region_width > 0):
                    paragraph_like_rows += 1

        aligned_ratio = aligned_rows / len(table_rows)
        if aligned_ratio < 0.70:
            return 0.55, "unstable_column_alignment"

        paragraph_ratio = paragraph_like_rows / len(table_rows)
        if paragraph_ratio > 0.50:
            return 0.60, "paragraph_like_region"

        confidence = 0.35 + (stable_row_ratio * 0.25) + (aligned_ratio * 0.25) + ((1.0 - paragraph_ratio) * 0.15)
        return min(0.99, confidence), "stable_grid"

    @staticmethod
    def _cell_anchor_x(cell: dict) -> float | None:
        bbox = cell.get("_bbox")
        if not bbox or len(bbox) < 4:
            return None
        return float(bbox[0])

    @staticmethod
    def _cell_width(cell: dict) -> float:
        bbox = cell.get("_bbox")
        if not bbox or len(bbox) < 4:
            return 0.0
        return max(0.0, float(bbox[2]) - float(bbox[0]))

    @staticmethod
    def _row_region_width(lines: list[dict]) -> float:
        bboxes = [line.get("bbox") for line in lines if line.get("bbox")]
        if not bboxes:
            return 0.0
        x0 = min(float(bbox[0]) for bbox in bboxes)
        x1 = max(float(bbox[2]) for bbox in bboxes)
        return max(0.0, x1 - x0)

    @staticmethod
    def _merge_bboxes(bboxes: list) -> list | None:
        """Merge multiple bounding boxes into one."""
        if not bboxes:
            return None

        x_coords = [bbox[0] for bbox in bboxes if bbox]
        y_coords = [bbox[1] for bbox in bboxes if bbox]
        x1_coords = [bbox[2] for bbox in bboxes if bbox]
        y1_coords = [bbox[3] for bbox in bboxes if bbox]

        if not x_coords:
            return None

        return [min(x_coords), min(y_coords), max(x1_coords), max(y1_coords)]

    def _extract_embedded_images(
        self, page: fitz.Page, doc: fitz.Document, document_id: str, page_index: int
    ) -> list[dict]:
        """Return image blocks for images embedded in the PDF page.

        Uses ``page.get_images(full=True)`` to find embedded XObjects and
        emits a canonical ``meta.image`` block for each one that passes the
        minimum size filter.
        """
        blocks: list[dict] = []
        try:
            img_list = page.get_images(full=True)
        except Exception:
            return blocks

        img_dir = self.data_root / "images" / document_id
        img_dir.mkdir(parents=True, exist_ok=True)

        for img_index, img_info in enumerate(img_list, start=1):
            try:
                xref = img_info[0]
                base_image = doc.extract_image(xref)
                img_bytes: bytes = base_image["image"]
                ext: str = base_image.get("ext", "png")
                width: int = base_image.get("width", 0)
                height: int = base_image.get("height", 0)

                if width < 32 or height < 32:
                    continue

                img_name = f"p{page_index:03d}-emb{img_index:04d}"
                safe_ext = ext.lstrip(".").lower()
                if safe_ext in {"jpg", "jfif"}:
                    safe_ext = "jpeg"
                img_path = img_dir / f"{img_name}.{safe_ext}"
                img_path.write_bytes(img_bytes)

                from base64 import b64encode
                data_uri: str | None = None
                if len(img_bytes) <= 200 * 1024:
                    mime_map = {
                        "png": "image/png", "jpeg": "image/jpeg", "gif": "image/gif",
                        "webp": "image/webp", "bmp": "image/bmp", "tiff": "image/tiff",
                    }
                    mime = mime_map.get(safe_ext, "image/png")
                    data_uri = f"data:{mime};base64,{b64encode(img_bytes).decode()}"

                filename = img_path.name
                blocks.append({
                    "block_id": f"{page_index}-emb{img_index}",
                    "type": "image",
                    "reading_order": img_index,
                    "raw_text": "",
                    "bbox": None,
                    "confidence": 1.0,
                    "flags": ["scan_embedded"],
                    "meta": {
                        "image_path": str(img_path),
                        "image": {
                            "src_path": str(img_path),
                            "src_url": f"/api/documents/{document_id}/images/{filename}",
                            "data_uri": data_uri,
                            "width": width,
                            "height": height,
                            "caption": None,
                        },
                        "source": "pdf_scan_embedded",
                    },
                })
            except Exception:
                continue

        return blocks

    def _render_page_image(self, page: fitz.Page, document_id: str, page_no: int) -> Path:
        return self._render_page_image_at_zoom(page, document_id, page_no, zoom=2.0)

    def _render_page_image_at_zoom(
        self, page: fitz.Page, document_id: str, page_no: int, zoom: float
    ) -> Path:
        page_dir = self.data_root / "pages" / document_id
        page_dir.mkdir(parents=True, exist_ok=True)
        suffix = f"z{zoom:.1f}".replace(".", "_")
        output_path = page_dir / f"page-{page_no}-{suffix}.png"
        pix = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom))
        pix.save(output_path)
        return output_path

    @staticmethod
    def _estimate_page_dpi(page: fitz.Page) -> float:
        """Estimate the effective scan DPI from the page's point dimensions.

        PyMuPDF uses 72 pt = 1 inch.  A scanned page stored at 150 DPI would
        have been digitised at that resolution; we approximate by assuming A4
        (297 mm tall = 841 pt at 72 dpi) and computing how the actual page
        height compares.  For PDFs that encode DPI in an image XObject we read
        the first embedded image's resolution if available.
        """
        try:
            imgs = page.get_images(full=True)
            if imgs:
                doc = page.parent
                base = doc.extract_image(imgs[0][0])
                xres = base.get("xres", 0) or 0
                yres = base.get("yres", 0) or 0
                if xres > 0 and yres > 0:
                    return float((xres + yres) / 2)
        except Exception:
            pass
        # Fallback: derive from page rect (72 pt = 1 inch baseline)
        page_height_inches = page.rect.height / 72.0
        return 72.0 / max(page_height_inches / 11.69, 0.01)  # normalise to A4

    def _render_page_image_adaptive(
        self, page: fitz.Page, document_id: str, page_no: int, settings
    ) -> Path:
        """Rasterise at a zoom chosen by source DPI; fall back to confidence retry."""
        if settings.ocr_render_zoom_heuristic_enabled:
            dpi = self._estimate_page_dpi(page)
            if dpi <= 150:
                zoom = 3.0
            elif dpi <= 200:
                zoom = settings.ocr_zoom_slow  # 2.5×
            else:
                zoom = settings.ocr_zoom_fast  # 1.5×
            return self._render_page_image_at_zoom(page, document_id, page_no, zoom)

        # Two-pass path (heuristic disabled): fast → retry at slow if low confidence.
        fast_path = self._render_page_image_at_zoom(page, document_id, page_no, settings.ocr_zoom_fast)
        fast_lines = self._ocr_image(fast_path)

        if fast_lines:
            confidences = [ln["confidence"] for ln in fast_lines if ln.get("confidence") is not None]
            mean_conf = sum(confidences) / len(confidences) if confidences else 0.0
        else:
            mean_conf = 0.0

        if mean_conf >= settings.thai_review_threshold:
            return fast_path

        slow_path = self._render_page_image_at_zoom(page, document_id, page_no, settings.ocr_zoom_slow)
        return slow_path

    def _ocr_image(self, image_path: Path) -> list[dict]:
        from app.core.config import get_settings
        reader = self._ensure_reader()
        batch_size = get_settings().ocr_recognizer_batch_size
        with self._ocr_lock:
            result = reader.readtext(str(image_path), detail=1, paragraph=True, batch_size=batch_size)

        if not result:
            return [{"text": "", "bbox": None, "confidence": 0.0}]

        lines: list[dict] = []
        for item in result:
            if len(item) == 3:
                bbox, text, confidence = item
            elif len(item) == 2:
                bbox, text = item
                confidence = 0.95
            else:
                continue

            x_values = [point[0] for point in bbox]
            y_values = [point[1] for point in bbox]
            lines.append(
                {
                    "text": str(text).strip(),
                    "bbox": [float(min(x_values)), float(min(y_values)), float(max(x_values)), float(max(y_values))],
                    "confidence": float(confidence),
                }
            )

        return lines

    def _ensure_reader(self):
        if self._reader is None:
            import easyocr
            try:
                import torch
                use_gpu = torch.cuda.is_available()
            except Exception:
                use_gpu = False

            self._reader = easyocr.Reader(
                ["th", "en"],
                gpu=use_gpu,
                detector=True,
                recognizer=True,
                verbose=False,
            )

        return self._reader
