from pathlib import Path
import warnings

import fitz
import cv2
import numpy as np

# Import new docling-parse based services
from app.services.docling_parse_extractor import DoclingParseExtractor
from app.utils.indent_detector import cluster_x_positions, detect_indent_level
from app.utils.gap_detector import detect_gaps, join_cells_with_gaps

# Suppress torch pin_memory warnings when no GPU is available
warnings.filterwarnings("ignore", message=".*pin_memory.*")


class OcrPipeline:
    def __init__(self, data_root: Path) -> None:
        self.data_root = data_root
        self._reader = None
        # Initialize docling-parse extractor for hybrid approach
        self._text_extractor = DoclingParseExtractor(fallback_to_fitz=False)

    def extract_scanned_pdf(self, file_path: Path, document_id: str) -> list[dict]:
        """Extract text from scanned PDF using hybrid approach.
        
        New approach:
        1. Try docling-parse first (some "scanned" PDFs have text layers)
        2. Fall back to EasyOCR if docling-parse fails or returns minimal text
        3. Apply indent/gap detection to OCR results
        """
        doc = fitz.open(file_path)
        pages: list[dict] = []

        for page_index, page in enumerate(doc, start=1):
            image_path = self._render_page_image(page, document_id, page_index)
            
            # Try hybrid approach: docling-parse + EasyOCR fallback
            page_blocks = self._extract_page_with_hybrid_ocr(
                file_path, page_index, document_id, image_path
            )

            pages.append(
                {
                    "page_no": page_index,
                    "image_path": str(image_path),
                    "blocks": page_blocks,
                }
            )

        doc.close()
        return pages
        
    def _extract_page_with_hybrid_ocr(
        self, file_path: Path, page_no: int, document_id: str, image_path: Path
    ) -> list[dict]:
        """Extract text from a single page using hybrid OCR approach."""
        
        # Step 1: Try docling-parse first
        try:
            text_pages = self._text_extractor.extract_pages(file_path)
            if text_pages and page_no <= len(text_pages):
                page_cells = text_pages[page_no - 1]
                
                # Check if docling-parse found meaningful text
                total_text_length = sum(len(cell.text.strip()) for cell in page_cells.word_cells)
                
                if total_text_length > 50:  # Reasonable amount of text found
                    return self._create_blocks_from_docling_parse_cells(
                        page_cells, page_no, document_id
                    )
        except Exception as e:
            warnings.warn(f"docling-parse failed for page {page_no}: {e}")
            
        # Step 2: Fall back to EasyOCR
        ocr_lines = self._ocr_image(image_path)
        
        # Detect table regions in the image
        table_regions = self._detect_table_regions(image_path)
        
        # Group OCR results by table regions
        blocks = self._group_ocr_results_by_tables(ocr_lines, table_regions, page_no)
        
        return blocks
        
    def _create_blocks_from_docling_parse_cells(
        self, page_cells, page_no: int, document_id: str
    ) -> list[dict]:
        """Create blocks from docling-parse cells with layout analysis."""
        blocks = []
        reading_order = 1
        
        # Analyze indent patterns
        x_positions = [cell.x0 for cell in page_cells.word_cells]
        indent_clusters = cluster_x_positions(x_positions)
        
        # Group cells into lines (use existing line grouping from docling-parse)
        for line_idx, line_cell in enumerate(page_cells.line_cells):
            # Detect gaps between cells in this line
            gaps = detect_gaps(line_cell.cells)
            
            # Join text with appropriate spacing
            joined_text = join_cells_with_gaps(line_cell.cells, gaps)
            
            # Detect indent level (use first cell's x0)
            indent_level = 0
            if line_cell.cells:
                indent_level = detect_indent_level(line_cell.cells[0].x0, indent_clusters)
                
            # Classify block type
            block_type = self._classify_ocr_block(joined_text, indent_level, line_idx)
            
            block = {
                "block_id": f"{page_no}-{reading_order}",
                "type": block_type,
                "reading_order": reading_order,
                "raw_text": joined_text,
                "bbox": list(line_cell.bbox),
                "confidence": 0.95,  # High confidence for docling-parse
                "flags": ["docling_parse_ocr"],
                "meta": {
                    "indent_level": indent_level,
                    "x_position": line_cell.cells[0].x0 if line_cell.cells else 0,
                    "gaps": gaps,
                    "layout": {
                        "bbox": list(line_cell.bbox),
                        "reading_order": reading_order,
                        "alignment": None,
                        "indent_left": line_cell.cells[0].x0 if line_cell.cells else 0,
                        "indent_first_line": None,
                        "indent_hanging": None,
                        "tabs": [],
                    },
                },
            }
            
            blocks.append(block)
            reading_order += 1
            
        return blocks
        
    def _classify_ocr_block(self, text: str, indent_level: int, position: int) -> str:
        """Classify OCR block type."""
        stripped = text.strip()
        
        if not stripped:
            return "paragraph"
            
        # Title detection (early position, no indent)
        if position <= 2 and indent_level == 0 and len(stripped) < 50:
            return "title"
            
        # Section headers
        import re
        if re.match(r"^(ข้อ\s*[๐-๙0-9]+|ข้อ[๐-๙0-9]+|มาตรา\s*[๐-๙0-9]+|มาตรา[๐-๙0-9]+)", stripped):
            return "section_header"
            
        # List items
        if re.match(r"^(\([๐-๙0-9]+\)|-|•|\d+\.|[ก-ฮ]\.\s)", stripped):
            return "list_item"
            
        return "paragraph"

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
        """Add remaining lines as regular paragraph blocks with layout analysis."""
        reading_order = start_order
        
        # Analyze indent patterns for all remaining lines
        remaining_lines = [line for idx, line in enumerate(lines) if idx not in used_indices]
        if remaining_lines:
            x_positions = [line["bbox"][0] for line in remaining_lines]
            indent_clusters = cluster_x_positions(x_positions)
        
        for idx, line in enumerate(lines):
            if idx not in used_indices:
                # Detect indent level
                indent_level = 0
                if remaining_lines:
                    indent_level = detect_indent_level(line["bbox"][0], indent_clusters)
                
                # Classify block type
                block_type = self._classify_ocr_block(line["text"], indent_level, reading_order)
                
                blocks.append({
                    "block_id": f"{page_index}-{reading_order}",
                    "type": block_type,
                    "reading_order": reading_order,
                    "raw_text": line["text"],
                    "bbox": line["bbox"],
                    "confidence": line["confidence"],
                    "flags": ["ocr_scan"],
                    "meta": {
                        "indent_level": indent_level,
                        "x_position": line["bbox"][0],
                        "layout": {
                            "bbox": line["bbox"],
                            "reading_order": reading_order,
                            "alignment": None,
                            "indent_left": line["bbox"][0],
                            "indent_first_line": None,
                            "indent_hanging": None,
                            "tabs": [],
                        },
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
        row_height_threshold = 30
        
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
        html = self._build_table_html(table_rows)
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

    @staticmethod
    def _build_table_html(rows: list[list[dict]]) -> str:
        """Build HTML table from rows."""
        html_rows: list[str] = []
        
        for row_index, row in enumerate(rows):
            rendered_cells: list[str] = []
            cell_tag = "th" if row_index == 0 else "td"
            
            for cell in row:
                text = str(cell.get("text", "")).replace("<", "&lt;").replace(">", "&gt;")
                rendered_cells.append(f"<{cell_tag}>{text}</{cell_tag}>")
            
            html_rows.append("<tr>" + "".join(rendered_cells) + "</tr>")
        
        return "<table><tbody>" + "".join(html_rows) + "</tbody></table>"

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
