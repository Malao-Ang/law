from __future__ import annotations

from pathlib import Path
import re
import zipfile
from xml.etree import ElementTree as ET

import fitz


class DoclingService:
    WORD_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
    NAMESPACES = {"w": WORD_NS}

    def __init__(self) -> None:
        self._converter = None
        try:
            from docling.document_converter import DocumentConverter  # type: ignore

            self._converter = DocumentConverter()
        except Exception:
            self._converter = None

    def extract(self, file_path: Path, source_type: str) -> list[dict]:
        if source_type == "docx":
            blocks = self._extract_docx_blocks(file_path)
            return [{"page_no": 1, "image_path": None, "blocks": blocks}]

        if source_type == "pdf_text":
            blocks_by_page = self._extract_pdf_blocks(file_path)
            return [
                {"page_no": page_no, "image_path": None, "blocks": blocks}
                for page_no, blocks in blocks_by_page
            ]

        return []

    def _extract_docx_blocks(self, file_path: Path) -> list[dict]:
        with zipfile.ZipFile(file_path) as archive:
            with archive.open("word/document.xml") as xml_file:
                root = ET.parse(xml_file).getroot()

        body = root.find("w:body", self.NAMESPACES)
        if body is None:
            return []

        blocks: list[dict] = []
        reading_order = 1

        for child in body:
            tag = self._local_name(child.tag)
            if tag == "p":
                block = self._parse_docx_paragraph(child, reading_order)
            elif tag == "tbl":
                block = self._parse_docx_table(child, reading_order)
            else:
                block = None

            if block is None:
                continue

            blocks.append(block)
            reading_order += 1

        return blocks

    def _extract_pdf_blocks(self, file_path: Path) -> list[tuple[int, list[dict]]]:
        if self._converter is not None:
            try:
                self._converter.convert(str(file_path))
            except Exception:
                pass

        doc = fitz.open(file_path)
        pages: list[tuple[int, list[dict]]] = []

        for page_index, page in enumerate(doc, start=1):
            blocks = self._extract_pdf_page_blocks(page, page_index)
            pages.append((page_index, blocks))

        doc.close()
        return pages

    def _extract_pdf_page_blocks(self, page: object, page_index: int) -> list[dict]:
        """Extract blocks from a PDF page, detecting tables and text separately."""
        blocks: list[dict] = []
        reading_order = 1

        # Try to detect tables using PyMuPDF
        try:
            tables = page.find_tables()
            table_rects = [table.bbox for table in tables] if tables else []
        except Exception:
            table_rects = []

        # Get text with layout information
        text_dict = page.get_text("dict")
        
        # Process blocks from the page
        for block in text_dict.get("blocks", []):
            if block["type"] == 1:  # Image block
                continue
            
            if block["type"] == 0:  # Text block
                block_rect = block["bbox"]
                
                # Check if this text block overlaps with any detected table
                is_in_table = any(
                    self._rects_overlap(block_rect, table_rect)
                    for table_rect in table_rects
                )
                
                if is_in_table:
                    continue  # Skip text blocks that are part of tables
                
                # Extract text from this block
                text_lines = []
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        text = span.get("text", "").strip()
                        if text:
                            text_lines.append(text)
                
                if text_lines:
                    raw_text = " ".join(text_lines)
                    blocks.append({
                        "block_id": f"{page_index}-{reading_order}",
                        "type": "paragraph",
                        "reading_order": reading_order,
                        "raw_text": raw_text,
                        "bbox": list(block_rect),
                        "confidence": 0.99,
                        "flags": [],
                    })
                    reading_order += 1
        
        # Process detected tables
        for table_index, table in enumerate(page.find_tables() or []):
            table_block = self._extract_pdf_table(table, page_index, reading_order + table_index)
            if table_block:
                blocks.append(table_block)
        
        # If no blocks were extracted, fall back to simple line extraction
        if not blocks:
            raw_text = page.get_text("text")
            lines = [line.strip() for line in raw_text.splitlines() if line.strip()]
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

    def _extract_pdf_table(self, table: object, page_index: int, reading_order: int) -> dict | None:
        """Extract table structure from PyMuPDF table object."""
        try:
            # Get table data
            table_data = table.extract()
            if not table_data:
                return None
            
            # Convert to cell structure
            rows: list[list[dict]] = []
            for row_data in table_data:
                row: list[dict] = []
                for cell_text in row_data:
                    cell_text_str = str(cell_text or "").strip()
                    row.append({
                        "text": cell_text_str,
                        "colspan": 1,
                        "rowspan": 1,
                        "alignment": None,
                    })
                if row:
                    rows.append(row)
            
            if not rows:
                return None
            
            # Build table payload
            headers = [cell["text"] for cell in rows[0]] if rows else []
            body = [[cell["text"] for cell in row] for row in rows[1:]] if len(rows) > 1 else []
            html = self._render_table_html(rows)
            raw_text = "\n".join("\t".join(cell["text"] for cell in row) for row in rows)
            
            return {
                "block_id": f"{page_index}-{reading_order}",
                "type": "table",
                "reading_order": reading_order,
                "raw_text": raw_text,
                "bbox": list(table.bbox),
                "confidence": 0.98,
                "flags": ["pdf_table"],
                "meta": {
                    "table": {
                        "headers": headers,
                        "rows": body,
                        "cells": rows,
                        "html": html,
                        "text": raw_text,
                    },
                    "layout": {
                        "bbox": list(table.bbox),
                        "reading_order": reading_order,
                        "alignment": None,
                        "indent_left": None,
                        "indent_first_line": None,
                        "indent_hanging": None,
                        "tabs": [],
                    },
                },
            }
        except Exception:
            return None

    @staticmethod
    def _rects_overlap(rect1: list | tuple, rect2: list | tuple, threshold: float = 0.1) -> bool:
        """Check if two rectangles overlap significantly."""
        if not rect1 or not rect2:
            return False
        
        x0_1, y0_1, x1_1, y1_1 = rect1
        x0_2, y0_2, x1_2, y1_2 = rect2
        
        # Calculate intersection
        x_left = max(x0_1, x0_2)
        y_top = max(y0_1, y0_2)
        x_right = min(x1_1, x1_2)
        y_bottom = min(y1_1, y1_2)
        
        if x_right < x_left or y_bottom < y_top:
            return False
        
        # Calculate overlap area
        overlap_area = (x_right - x_left) * (y_bottom - y_top)
        rect1_area = (x1_1 - x0_1) * (y1_1 - y0_1)
        
        # Return True if overlap is significant
        return overlap_area > (rect1_area * threshold)

    def _parse_docx_paragraph(self, paragraph: ET.Element, reading_order: int) -> dict | None:
        text = self._extract_paragraph_text(paragraph)
        layout = self._extract_paragraph_layout(paragraph)

        if text.strip() == "":
            return None

        block_type = self._classify_paragraph(text=text, layout=layout, reading_order=reading_order)

        return {
            "block_id": f"1-{reading_order}",
            "type": block_type,
            "reading_order": reading_order,
            "raw_text": text,
            "bbox": None,
            "confidence": 0.98,
            "flags": [],
            "meta": {
                "layout": layout,
            },
        }

    def _parse_docx_table(self, table: ET.Element, reading_order: int) -> dict | None:
        rows: list[list[dict]] = []
        active_vertical_merges: dict[int, dict] = {}
        flattened_rows: list[list[str]] = []

        for row in table.findall("w:tr", self.NAMESPACES):
            parsed_row: list[dict] = []
            flat_row: list[str] = []
            next_vertical_merges: dict[int, dict] = {}
            column_index = 0

            for cell in row.findall("w:tc", self.NAMESPACES):
                parsed_cell = self._parse_table_cell(cell)
                colspan = parsed_cell["colspan"]
                v_merge_state = parsed_cell.pop("v_merge_state")

                if v_merge_state == "continue":
                    merged_cell = active_vertical_merges.get(column_index)
                    if merged_cell is not None:
                        merged_cell["rowspan"] += 1
                        for offset in range(colspan):
                            next_vertical_merges[column_index + offset] = merged_cell
                    column_index += colspan
                    continue

                parsed_row.append(parsed_cell)
                flat_row.append(parsed_cell["text"])

                if v_merge_state == "restart":
                    for offset in range(colspan):
                        next_vertical_merges[column_index + offset] = parsed_cell

                column_index += colspan

            if parsed_row:
                rows.append(parsed_row)
                flattened_rows.append(flat_row)

            active_vertical_merges = next_vertical_merges

        if not rows:
            return None

        table_payload = self._build_docx_table_payload(rows, flattened_rows)

        return {
            "block_id": f"1-{reading_order}",
            "type": "table",
            "reading_order": reading_order,
            "raw_text": table_payload["text"],
            "bbox": None,
            "confidence": 0.99,
            "flags": [],
            "meta": {
                "table": table_payload,
                "layout": {
                    "bbox": None,
                    "reading_order": reading_order,
                    "alignment": None,
                    "indent_left": None,
                    "indent_first_line": None,
                    "indent_hanging": None,
                    "tabs": [],
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
        paragraph_properties = paragraph.find("w:pPr", self.NAMESPACES)
        alignment = None
        indent_left = None
        indent_first_line = None
        indent_hanging = None
        tabs: list[dict[str, int | str]] = []

        if paragraph_properties is not None:
            alignment_node = paragraph_properties.find("w:jc", self.NAMESPACES)
            indent_node = paragraph_properties.find("w:ind", self.NAMESPACES)

            if alignment_node is not None:
                alignment = self._word_attr(alignment_node, "val")

            if indent_node is not None:
                indent_left = self._parse_int_attr(indent_node, "left")
                indent_first_line = self._parse_int_attr(indent_node, "firstLine")
                indent_hanging = self._parse_int_attr(indent_node, "hanging")

            for tab_node in paragraph_properties.findall("w:tabs/w:tab", self.NAMESPACES):
                position = self._parse_int_attr(tab_node, "pos")
                if position is None:
                    continue
                tabs.append(
                    {
                        "align": self._word_attr(tab_node, "val") or "left",
                        "position": position,
                    }
                )

        return {
            "bbox": None,
            "reading_order": None,
            "alignment": alignment,
            "indent_left": indent_left,
            "indent_first_line": indent_first_line,
            "indent_hanging": indent_hanging,
            "tabs": tabs,
        }

    def _parse_table_cell(self, cell: ET.Element) -> dict:
        cell_properties = cell.find("w:tcPr", self.NAMESPACES)
        colspan = 1
        v_merge_state: str | None = None

        if cell_properties is not None:
            grid_span = cell_properties.find("w:gridSpan", self.NAMESPACES)
            v_merge = cell_properties.find("w:vMerge", self.NAMESPACES)

            if grid_span is not None:
                colspan = int(self._word_attr(grid_span, "val") or "1")

            if v_merge is not None:
                v_merge_state = self._word_attr(v_merge, "val") or "continue"

        text_parts = [
            self._extract_paragraph_text(paragraph).strip()
            for paragraph in cell.findall("w:p", self.NAMESPACES)
        ]
        text = "\n".join(part for part in text_parts if part != "")
        cell_alignment = self._extract_cell_alignment(cell)

        return {
            "text": text,
            "colspan": colspan,
            "rowspan": 1,
            "alignment": cell_alignment,
            "v_merge_state": v_merge_state,
        }

    def _extract_cell_alignment(self, cell: ET.Element) -> str | None:
        for paragraph in cell.findall("w:p", self.NAMESPACES):
            layout = self._extract_paragraph_layout(paragraph)
            alignment = layout.get("alignment")
            if alignment is not None:
                return str(alignment)

        return None

    def _build_docx_table_payload(self, rows: list[list[dict]], flattened_rows: list[list[str]]) -> dict:
        headers = flattened_rows[0] if flattened_rows else []
        body = flattened_rows[1:] if len(flattened_rows) > 1 else []
        html = self._render_table_html(rows)
        raw_text = "\n".join("\t".join(cell for cell in row if cell != "") for row in flattened_rows)

        return {
            "headers": headers,
            "rows": body,
            "cells": rows,
            "html": html,
            "text": raw_text,
        }

    def _render_table_html(self, rows: list[list[dict]]) -> str:
        html_rows: list[str] = []

        for row_index, row in enumerate(rows):
            rendered_cells: list[str] = []
            cell_tag = "th" if row_index == 0 else "td"

            for cell in row:
                attributes: list[str] = []
                if cell["colspan"] > 1:
                    attributes.append(f' colspan="{cell["colspan"]}"')
                if cell["rowspan"] > 1:
                    attributes.append(f' rowspan="{cell["rowspan"]}"')
                if cell["alignment"]:
                    alignment = escape_html(str(cell["alignment"]))
                    attributes.append(f' data-cell-align="{alignment}"')
                    attributes.append(f' style="text-align:{alignment};"')

                cell_content = escape_html(cell["text"]).replace("\n", "<br>")
                rendered_cells.append(f'<{cell_tag}{"".join(attributes)}>{cell_content}</{cell_tag}>')

            html_rows.append("<tr>" + "".join(rendered_cells) + "</tr>")

        return "<table><tbody>" + "".join(html_rows) + "</tbody></table>"

    def _classify_paragraph(self, text: str, layout: dict, reading_order: int) -> str:
        stripped = text.strip()
        alignment = layout.get("alignment")

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
        value = self._word_attr(node, name)
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