from pathlib import Path
import warnings

import fitz
import cv2
import numpy as np

from app.services.html_renderer import build_table_html, escape_html

# Suppress torch pin_memory warnings when no GPU is available
warnings.filterwarnings("ignore", message=".*pin_memory.*")


class OcrPipeline:
    def __init__(self, data_root: Path, row_group_threshold_px: int = 30) -> None:
        self.data_root = data_root
        self._row_group_threshold_px = row_group_threshold_px
        self._reader = None

    def extract_scanned_pdf(self, file_path: Path, document_id: str) -> list[dict]:
        doc = fitz.open(file_path)
        pages: list[dict] = []

        for page_index, page in enumerate(doc, start=1):
            image_path = self._render_page_image(page, document_id, page_index)
            lines = self._ocr_image(image_path)
            
            # Detect table regions in the image
            table_regions = self._detect_table_regions(image_path)
            
            # Group OCR results by table regions
            blocks = self._group_ocr_results_by_tables(lines, table_regions, page_index)

            pages.append(
                {
                    "page_no": page_index,
                    "image_path": str(image_path),
                    "blocks": blocks,
                }
            )

        doc.close()
        return pages

    def _detect_table_regions(self, image_path: Path) -> list[dict]:
        """Detect table regions in an image using OpenCV."""
        try:
            image = cv2.imread(str(image_path))
            if image is None:
                return []
            
            gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
            
            # Apply threshold to get binary image
            _, binary = cv2.threshold(gray, 150, 255, cv2.THRESH_BINARY)
            
            # Detect horizontal and vertical lines
            horizontal_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (40, 1))
            vertical_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (1, 40))
            
            horizontal_lines = cv2.morphologyEx(binary, cv2.MORPH_OPEN, horizontal_kernel)
            vertical_lines = cv2.morphologyEx(binary, cv2.MORPH_OPEN, vertical_kernel)
            
            # Combine horizontal and vertical lines
            combined = cv2.add(horizontal_lines, vertical_lines)
            
            # Find contours
            contours, _ = cv2.findContours(combined, cv2.RETR_TREE, cv2.CHAIN_APPROX_SIMPLE)
            
            table_regions = []
            for contour in contours:
                x, y, w, h = cv2.boundingRect(contour)
                # Filter by minimum size to avoid noise
                if w > 100 and h > 50:
                    table_regions.append({
                        "x": float(x),
                        "y": float(y),
                        "width": float(w),
                        "height": float(h),
                    })
            
            return table_regions
        except Exception:
            return []

    @staticmethod
    def _compute_ocr_page_margin_x(lines: list[dict]) -> float:
        """Return 10th-percentile of OCR line x0 pixel values as the left margin baseline."""
        x0_vals = sorted(line["bbox"][0] for line in lines if line.get("bbox"))
        if len(x0_vals) < 2:
            return 0.0
        return x0_vals[len(x0_vals) // 10]

    @staticmethod
    def _estimate_ocr_layout(line: dict, page_margin_x_px: float, reading_order: int) -> dict:
        """Build layout dict from EasyOCR bbox (image pixels at 2x render scale).
        Converts pixel offset to twips: 2px = 1 PDF point, 1 pt = 20 twips.
        """
        indent_left = None
        bbox = line.get("bbox")
        if bbox:
            x0_px = bbox[0]
            indent_px = max(0.0, x0_px - page_margin_x_px)
            if indent_px >= 5.0:  # absorb EasyOCR position jitter
                indent_pt = indent_px / 2.0
                indent_left = round(indent_pt * 20)
        return {
            "bbox": bbox,
            "reading_order": reading_order,
            "alignment": None,
            "indent_left": indent_left,
            "indent_first_line": None,
            "indent_hanging": None,
            "tabs": [],
            "spacing_before": None,
            "spacing_after": None,
            "line_spacing": None,
        }

    def _group_ocr_results_by_tables(self, lines: list[dict], table_regions: list[dict], page_index: int) -> list[dict]:
        """Group OCR results by detected table regions and create table blocks."""
        blocks: list[dict] = []
        used_line_indices = set()
        reading_order = 1
        page_margin_x_px = self._compute_ocr_page_margin_x(lines)

        # Process each detected table region
        for region in table_regions:
            table_lines, new_used_indices = self._collect_lines_in_region(lines, region, used_line_indices)

            if table_lines:
                table_block = self._create_table_block_from_lines(table_lines, page_index, reading_order)
                if table_block:
                    blocks.append(table_block)
                    reading_order += 1
                    used_line_indices = new_used_indices

        # Add remaining lines as regular blocks
        reading_order = self._add_remaining_lines(blocks, lines, used_line_indices, page_index, reading_order, page_margin_x_px)

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

    def _add_remaining_lines(self, blocks: list[dict], lines: list[dict], used_indices: set, page_index: int, start_order: int, page_margin_x_px: float = 0.0) -> int:
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
                    "meta": {
                        "layout": self._estimate_ocr_layout(line, page_margin_x_px, reading_order)
                    },
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
        
        # Check if center of bbox is within region
        center_x = (x0 + x1) / 2
        center_y = (y0 + y1) / 2
        
        return region_x <= center_x <= region_x1 and region_y <= center_y <= region_y1

    def _create_table_block_from_lines(self, lines: list[dict], page_index: int, reading_order: int) -> dict | None:
        """Create a table block from grouped OCR lines."""
        if not lines:
            return None
        
        sorted_lines = sorted(lines, key=lambda l: (l["bbox"][1], l["bbox"][0]))
        rows = self._group_lines_into_rows(sorted_lines)
        
        if not rows:
            return None
        
        table_rows = self._convert_rows_to_cells(rows)
        
        if not table_rows:
            return None
        
        return self._build_table_block(table_rows, sorted_lines, page_index, reading_order)

    def _group_lines_into_rows(self, sorted_lines: list[dict]) -> list[list[dict]]:
        """Group sorted lines into rows based on y position."""
        rows: list[list[dict]] = []
        current_row: list[dict] = []
        last_y = None
        row_height_threshold = self._row_group_threshold_px
        
        for line in sorted_lines:
            y_pos = line["bbox"][1]
            
            if last_y is not None and abs(y_pos - last_y) > row_height_threshold:
                if current_row:
                    rows.append(current_row)
                current_row = []
            
            current_row.append(line)
            last_y = y_pos
        
        if current_row:
            rows.append(current_row)
        
        return rows

    def _convert_rows_to_cells(self, rows: list[list[dict]]) -> list[list[dict]]:
        """Convert grouped lines into table cell structure."""
        table_rows: list[list[dict]] = []
        
        for row_lines in rows:
            row_lines_sorted = sorted(row_lines, key=lambda l: l["bbox"][0])
            table_row: list[dict] = []
            
            for line in row_lines_sorted:
                table_row.append({
                    "text": line["text"],
                    "colspan": 1,
                    "rowspan": 1,
                    "alignment": None,
                })
            
            if table_row:
                table_rows.append(table_row)
        
        return table_rows

    def _build_table_block(self, table_rows: list[list[dict]], sorted_lines: list[dict], page_index: int, reading_order: int) -> dict:
        """Build complete table block structure."""
        headers = [cell["text"] for cell in table_rows[0]] if table_rows else []
        body = [[cell["text"] for cell in row] for row in table_rows[1:]] if len(table_rows) > 1 else []
        html = build_table_html(table_rows)
        raw_text = "\n".join("\t".join(cell["text"] for cell in row) for row in table_rows)
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
                "table": {
                    "headers": headers,
                    "rows": body,
                    "cells": table_rows,
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

    def _render_page_image(self, page: fitz.Page, document_id: str, page_no: int) -> Path:
        page_dir = self.data_root / "pages" / document_id
        page_dir.mkdir(parents=True, exist_ok=True)
        output_path = page_dir / f"page-{page_no}.png"

        pix = page.get_pixmap(matrix=fitz.Matrix(2.0, 2.0))
        pix.save(output_path)
        return output_path

    def _ocr_image(self, image_path: Path) -> list[dict]:
        reader = self._ensure_reader()
        result = reader.readtext(str(image_path), detail=1, paragraph=True)

        if not result:
            return [{"text": "", "bbox": None, "confidence": 0.0}]

        lines: list[dict] = []
        for item in result:
            # EasyOCR may return (bbox, text, confidence) or (bbox, text)
            if len(item) == 3:
                bbox, text, confidence = item
            elif len(item) == 2:
                bbox, text = item
                confidence = 0.95  # default confidence when not provided
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

            self._reader = easyocr.Reader(["th", "en"], gpu=False, 
                                       detector=True, recognizer=True,
                                       verbose=False)

        return self._reader
