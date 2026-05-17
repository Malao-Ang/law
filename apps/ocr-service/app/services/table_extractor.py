from __future__ import annotations

from pathlib import Path
from typing import List, Dict, Any, Optional, Tuple
import warnings

# Try importing docling with fallback
try:
    from docling.document_converter import DocumentConverter, PdfFormatOption
    from docling.datamodel.pipeline_options import PdfPipelineOptions, TableStructureOptions
    DOCLING_AVAILABLE = True
except ImportError as e:
    warnings.warn(f"docling not available: {e}. Using fallback.")
    DOCLING_AVAILABLE = False

# Fallback to fitz if docling not available
import fitz


class CellRect:
    """Represents a table cell with coordinates."""
    def __init__(self, text: str, bbox: Tuple[float, float, float, float], 
                 row: int, col: int, colspan: int = 1, rowspan: int = 1):
        self.text = text
        self.bbox = bbox  # (x0, y0, x1, y1)
        self.row = row
        self.col = col
        self.colspan = colspan
        self.rowspan = rowspan
        
    @property
    def x0(self) -> float:
        return self.bbox[0]
        
    @property
    def y0(self) -> float:
        return self.bbox[1]
        
    @property
    def x1(self) -> float:
        return self.bbox[2]
        
    @property
    def y1(self) -> float:
        return self.bbox[3]


class TableResult:
    """Represents a detected table with its structure."""
    def __init__(self, bbox: Tuple[float, float, float, float], 
                 rows: List[List[CellRect]], page_no: int):
        self.bbox = bbox  # (x0, y0, x1, y1)
        self.rows = rows
        self.page_no = page_no
        
    @property
    def x0(self) -> float:
        return self.bbox[0]
        
    @property
    def y0(self) -> float:
        return self.bbox[1]
        
    @property
    def x1(self) -> float:
        return self.bbox[2]
        
    @property
    def y1(self) -> float:
        return self.bbox[3]
        
    @property
    def num_rows(self) -> int:
        return len(self.rows)
        
    @property
    def num_cols(self) -> int:
        if not self.rows:
            return 0
        return max(len(row) for row in self.rows)


class DoclingTableExtractor:
    """Extract table structure from PDF using docling TableFormer."""
    
    def __init__(self, fallback_to_fitz: bool = True):
        self.fallback_to_fitz = fallback_to_fitz
        self._converter = None
        self._init_converter()
        
    def _init_converter(self) -> None:
        """Initialize docling converter with TableFormer only."""
        if DOCLING_AVAILABLE:
            try:
                # Configure pipeline options for TableFormer only
                pipeline_options = PdfPipelineOptions()
                pipeline_options.do_ocr = False  # Disable OCR
                pipeline_options.do_table_structure = True  # Enable TableFormer
                pipeline_options.table_structure_options = TableStructureOptions(
                    do_cell_matching=True
                )
                
                self._converter = DocumentConverter(
                    format_options={"pdf": PdfFormatOption(pipeline_options=pipeline_options)}
                )
            except Exception as e:
                warnings.warn(f"Failed to initialize Docling DocumentConverter: {e}")
                self._converter = None
                
    def is_available(self) -> bool:
        """Check if docling TableFormer is available."""
        return DOCLING_AVAILABLE and self._converter is not None
        
    def extract_tables(self, file_path: Path) -> List[TableResult]:
        """Extract table structures from all pages.

        Uses docling TableFormer when available (with ``do_cell_matching=True``
        so cells carry both structure *and* text).  Falls back to PyMuPDF
        ``find_tables()`` on any failure.
        """
        if not self.is_available():
            if self.fallback_to_fitz:
                return self._extract_tables_fitz_fallback(file_path)
            raise RuntimeError("docling not available and fallback disabled")

        try:
            result = self._converter.convert(str(file_path))
            tables: List[TableResult] = []

            # Read page heights from PyMuPDF so we can flip docling's
            # PDF-origin (y=0 at bottom) bboxes to screen-origin (y=0 at top).
            page_heights: Dict[int, float] = {}
            try:
                doc = fitz.open(str(file_path))
                for i, pg in enumerate(doc, start=1):
                    page_heights[i] = float(pg.rect.height)
                doc.close()
            except Exception:
                pass

            # Docling exposes tables via document.iterate_items().
            # Each TableItem has .prov (provenance: page + bbox) and
            # .data.table_cells (structured cell list with span info).
            try:
                from docling.datamodel.document import TableItem  # type: ignore
                item_iter = result.document.iterate_items(item_types=[TableItem])
            except Exception:
                # Older docling: try document.tables attribute directly
                item_iter = ((t, 0) for t in (getattr(result.document, "tables", None) or []))

            for item, _level in item_iter:
                table_result = self._convert_docling_table_item(item, page_heights)
                if table_result:
                    tables.append(table_result)

            if tables:
                return tables

            # If docling found no tables, fall through to fitz
            warnings.warn("docling found 0 tables; using fitz fallback.")
            return self._extract_tables_fitz_fallback(file_path)

        except Exception as e:
            warnings.warn(f"docling table extraction failed: {e}. Using fallback.")
            if self.fallback_to_fitz:
                return self._extract_tables_fitz_fallback(file_path)
            raise

    def _convert_docling_table_item(self, item, page_heights: Dict[int, float] | None = None) -> Optional[TableResult]:
        """Convert a docling TableItem to our TableResult format."""
        try:
            # Provenance: page number and bounding box.
            prov_list = getattr(item, "prov", None) or []
            prov = prov_list[0] if prov_list else None
            page_no: int = int(getattr(prov, "page_no", 1)) if prov else 1

            raw_bbox = getattr(prov, "bbox", None) if prov else None
            if raw_bbox is not None:
                # Docling uses PDF coordinate origin (y=0 at bottom of page,
                # increasing upward).  PyMuPDF uses screen origin (y=0 at top,
                # increasing downward).  We must flip the y-axis so that
                # _rects_overlap() comparisons against PyMuPDF block bboxes work.
                #
                # Docling BoundingBox field names vary by version:
                #   newer: l/t/r/b  (left/top/right/bottom in PDF coords)
                #   older: x0/y0/x1/y1
                page_h = (page_heights or {}).get(page_no, 792.0)
                dl_l = float(getattr(raw_bbox, "l", getattr(raw_bbox, "x0", 0)))
                dl_t = float(getattr(raw_bbox, "t", getattr(raw_bbox, "y0", 0)))
                dl_r = float(getattr(raw_bbox, "r", getattr(raw_bbox, "x1", 0)))
                dl_b = float(getattr(raw_bbox, "b", getattr(raw_bbox, "y1", 0)))
                # In PDF coords "t" > "b" when the origin is at the bottom.
                # Convert to screen coords: screen_y = page_h - pdf_y.
                screen_y0 = page_h - max(dl_t, dl_b)
                screen_y1 = page_h - min(dl_t, dl_b)
                bbox = (min(dl_l, dl_r), screen_y0, max(dl_l, dl_r), screen_y1)
            else:
                bbox = (0.0, 0.0, 100.0, 50.0)

            # table_cells: flat list of TableCell with structural info.
            table_data = getattr(item, "data", None)
            flat_cells = list(getattr(table_data, "table_cells", None) or [])
            if not flat_cells:
                return None

            # Determine grid size.
            num_rows = max(
                (getattr(c, "end_row_offset_idx", getattr(c, "start_row_offset_idx", 0) + 1) or 1)
                for c in flat_cells
            )
            num_cols = max(
                (getattr(c, "end_col_offset_idx", getattr(c, "start_col_offset_idx", 0) + 1) or 1)
                for c in flat_cells
            )

            # Build a 2-D grid of CellRect (one entry per unique top-left position).
            grid: Dict[Tuple[int, int], CellRect] = {}
            for cell in flat_cells:
                r0 = int(getattr(cell, "start_row_offset_idx", 0))
                c0 = int(getattr(cell, "start_col_offset_idx", 0))
                r1 = int(getattr(cell, "end_row_offset_idx", r0 + 1))
                c1 = int(getattr(cell, "end_col_offset_idx", c0 + 1))
                rowspan = max(1, r1 - r0)
                colspan = max(1, c1 - c0)

                text = str(getattr(cell, "text", "") or "").strip()

                cell_bbox_obj = getattr(cell, "bbox", None)
                if cell_bbox_obj is not None:
                    cell_bbox: Tuple[float, float, float, float] = (
                        float(getattr(cell_bbox_obj, "l", getattr(cell_bbox_obj, "x0", 0))),
                        float(getattr(cell_bbox_obj, "t", getattr(cell_bbox_obj, "y0", 0))),
                        float(getattr(cell_bbox_obj, "r", getattr(cell_bbox_obj, "x1", 0))),
                        float(getattr(cell_bbox_obj, "b", getattr(cell_bbox_obj, "y1", 0))),
                    )
                else:
                    cell_bbox = (0.0, 0.0, 50.0, 10.0)

                if (r0, c0) not in grid:
                    grid[(r0, c0)] = CellRect(
                        text=text, bbox=cell_bbox, row=r0, col=c0,
                        colspan=colspan, rowspan=rowspan,
                    )

            if not grid:
                return None

            # Reconstruct row-major list (only top-left cells of each span).
            rows: List[List[CellRect]] = []
            for r in range(num_rows):
                row: List[CellRect] = [grid[(r, c)] for c in range(num_cols) if (r, c) in grid]
                if row:
                    rows.append(row)

            if not rows:
                return None

            return TableResult(bbox=bbox, rows=rows, page_no=page_no)

        except Exception as e:
            warnings.warn(f"Failed to convert docling TableItem: {e}")
            return None
            
    def _calculate_table_bbox(self, docling_table) -> Tuple[float, float, float, float]:
        """Calculate table bbox from its cells."""
        x0_min, y0_min = float('inf'), float('inf')
        x1_max, y1_max = float('-inf'), float('-inf')
        
        if hasattr(docling_table, 'cells'):
            for row_cells in docling_table.cells:
                for cell in row_cells:
                    if hasattr(cell, 'bbox'):
                        bbox = cell.bbox
                        x0_min = min(x0_min, bbox[0])
                        y0_min = min(y0_min, bbox[1])
                        x1_max = max(x1_max, bbox[2])
                        y1_max = max(y1_max, bbox[3])
                        
        # If no valid coordinates found, return default bbox
        if x0_min == float('inf'):
            return (0.0, 0.0, 100.0, 50.0)
            
        return (x0_min, y0_min, x1_max, y1_max)
        
    def _extract_tables_fitz_fallback(self, file_path: Path) -> List[TableResult]:
        """Fallback table extraction using PyMuPDF (fitz)."""
        warnings.warn("Using fitz fallback for table extraction")
        
        doc = fitz.open(file_path)
        tables = []
        
        for page_no, page in enumerate(doc, start=1):
            try:
                # Find tables using fitz
                page_tables = page.find_tables()
                
                for table_idx, table in enumerate(page_tables):
                    # Extract table data
                    table_data = table.extract()
                    if not table_data:
                        continue
                        
                    # Convert to our format
                    table_result = self._convert_fitz_table(table, table_data, page_no)
                    if table_result:
                        tables.append(table_result)
                        
            except Exception as e:
                warnings.warn(f"Failed to extract tables from page {page_no}: {e}")
                continue
                
        doc.close()
        return tables
        
    def _convert_fitz_table(self, fitz_table, table_data: List[List[str]], 
                           page_no: int) -> Optional[TableResult]:
        """Convert fitz table to TableResult format."""
        try:
            # Get table bbox
            bbox = tuple(fitz_table.bbox)
            
            # Convert table data to CellRect format
            rows = []
            for row_idx, row_data in enumerate(table_data):
                row_rects = []
                for col_idx, cell_text in enumerate(row_data):
                    text = str(cell_text).strip()
                    if not text:
                        text = ''
                        
                    # Create a minimal bbox for each cell
                    # In real implementation, we'd need to calculate actual cell positions
                    cell_bbox = (0.0, 0.0, 50.0, 10.0)  # Default size
                    
                    cell_rect = CellRect(text=text, bbox=cell_bbox, 
                                       row=row_idx, col=col_idx)
                    row_rects.append(cell_rect)
                    
                if row_rects:
                    rows.append(row_rects)
                    
            if not rows:
                return None
                
            return TableResult(bbox=bbox, rows=rows, page_no=page_no)
            
        except Exception as e:
            warnings.warn(f"Failed to convert fitz table: {e}")
            return None
