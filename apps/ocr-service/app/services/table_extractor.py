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
                    do_cell_matching=False  # Disable cell matching for now
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
        """Extract table structures from all pages."""
        if not self.is_available():
            if self.fallback_to_fitz:
                return self._extract_tables_fitz_fallback(file_path)
            else:
                raise RuntimeError("docling not available and fallback disabled")
                
        try:
            result = self._converter.convert(str(file_path))
            tables = []
            
            # Process each page for tables
            for page_no, page in enumerate(result.pages, start=1):
                page_tables = self._extract_tables_from_page(page, page_no)
                tables.extend(page_tables)
                
            return tables
            
        except Exception as e:
            warnings.warn(f"docling table extraction failed: {e}. Using fallback.")
            if self.fallback_to_fitz:
                return self._extract_tables_fitz_fallback(file_path)
            else:
                raise
                
    def _extract_tables_from_page(self, page, page_no: int) -> List[TableResult]:
        """Extract tables from a single docling page."""
        tables = []
        
        # Check if page has table elements
        if hasattr(page, 'tables') and page.tables:
            for table_idx, table in enumerate(page.tables):
                # Convert docling table to our format
                table_result = self._convert_docling_table(table, page_no)
                if table_result:
                    tables.append(table_result)
                    
        return tables
        
    def _convert_docling_table(self, docling_table, page_no: int) -> Optional[TableResult]:
        """Convert docling table to TableResult format."""
        try:
            # Extract table bbox
            if hasattr(docling_table, 'bbox'):
                bbox = tuple(docling_table.bbox)
            else:
                # Fallback: calculate bbox from cells
                bbox = self._calculate_table_bbox(docling_table)
                
            # Extract rows and cells
            rows = []
            if hasattr(docling_table, 'cells'):
                for row_idx, row_cells in enumerate(docling_table.cells):
                    row_rects = []
                    for col_idx, cell in enumerate(row_cells):
                        cell_rect = self._convert_docling_cell(cell, row_idx, col_idx)
                        if cell_rect:
                            row_rects.append(cell_rect)
                    if row_rects:
                        rows.append(row_rects)
                        
            if not rows:
                return None
                
            return TableResult(bbox=bbox, rows=rows, page_no=page_no)
            
        except Exception as e:
            warnings.warn(f"Failed to convert docling table: {e}")
            return None
            
    def _convert_docling_cell(self, docling_cell, row: int, col: int) -> Optional[CellRect]:
        """Convert docling cell to CellRect format."""
        try:
            text = str(getattr(docling_cell, 'text', '')).strip()
            if not text:
                text = ''
                
            # Get cell bbox
            if hasattr(docling_cell, 'bbox'):
                bbox = tuple(docling_cell.bbox)
            else:
                # Create a minimal bbox based on position
                bbox = (0.0, 0.0, 50.0, 10.0)  # Default size
                
            # Get colspan/rowspan if available
            colspan = getattr(docling_cell, 'colspan', 1) or 1
            rowspan = getattr(docling_cell, 'rowspan', 1) or 1
            
            return CellRect(text=text, bbox=bbox, row=row, col=col,
                          colspan=colspan, rowspan=rowspan)
                          
        except Exception as e:
            warnings.warn(f"Failed to convert docling cell: {e}")
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
