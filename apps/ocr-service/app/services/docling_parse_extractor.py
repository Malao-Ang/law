from __future__ import annotations

from pathlib import Path
from typing import List, Dict, Any, Optional, Tuple
import warnings

# Try importing docling-parse with fallback
try:
    from docling_parse.pdf_parser import DoclingPdfParser, PdfDocument
    from docling_core.types.doc.page import TextCellUnit
    DOCLING_PARSE_AVAILABLE = True
except ImportError as e:
    warnings.warn(f"docling-parse not available: {e}. Using fallback.")
    DOCLING_PARSE_AVAILABLE = False

# Fallback to fitz if docling-parse not available
import fitz


class TextCell:
    """Represents a text cell with coordinates."""
    def __init__(self, text: str, bbox: Tuple[float, float, float, float], 
                 page_no: int, unit: str = "word"):
        self.text = text
        self.bbox = bbox  # (x0, y0, x1, y1)
        self.page_no = page_no
        self.unit = unit  # "word", "line", or "char"
        
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
    def width(self) -> float:
        return self.x1 - self.x0
        
    @property
    def height(self) -> float:
        return self.y1 - self.y0


class LineCell:
    """Represents a line of text cells."""
    def __init__(self, cells: List[TextCell], bbox: Tuple[float, float, float, float]):
        self.cells = cells
        self.bbox = bbox
        
    @property
    def text(self) -> str:
        return " ".join(cell.text for cell in self.cells)
        
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


class PageCells:
    """Container for all text cells on a page."""
    def __init__(self, page_no: int, word_cells: List[TextCell], 
                 line_cells: List[LineCell]):
        self.page_no = page_no
        self.word_cells = word_cells
        self.line_cells = line_cells


class DoclingParseExtractor:
    """Extract text with coordinates from PDF using docling-parse."""
    
    def __init__(self, fallback_to_fitz: bool = True):
        self.fallback_to_fitz = fallback_to_fitz
        self._parser = None
        self._init_parser()
        
    def _init_parser(self) -> None:
        """Initialize docling-parse parser if available."""
        if DOCLING_PARSE_AVAILABLE:
            try:
                self._parser = DoclingPdfParser()
            except Exception as e:
                warnings.warn(f"Failed to initialize DoclingPdfParser: {e}")
                self._parser = None
                
    def is_available(self) -> bool:
        """Check if docling-parse is available."""
        return DOCLING_PARSE_AVAILABLE and self._parser is not None
        
    def extract_pages(self, file_path: Path) -> List[PageCells]:
        """Extract text cells from all pages."""
        if not self.is_available():
            if self.fallback_to_fitz:
                return self._extract_pages_fitz_fallback(file_path)
            else:
                raise RuntimeError("docling-parse not available and fallback disabled")
                
        try:
            pdf_doc: PdfDocument = self._parser.load(path_or_stream=str(file_path))
            pages = []
            
            for page_no, pred_page in pdf_doc.iterate_pages():
                # Extract word-level cells
                word_cells = []
                for word in pred_page.iterate_cells(unit_type=TextCellUnit.WORD):
                    cell = TextCell(
                        text=word.text,
                        bbox=tuple(word.rect),  # (x0, y0, x1, y1)
                        page_no=page_no,
                        unit="word"
                    )
                    word_cells.append(cell)
                    
                # Group words into lines
                line_cells = self._group_words_into_lines(word_cells)
                
                pages.append(PageCells(page_no=page_no, word_cells=word_cells, 
                                    line_cells=line_cells))
                
            return pages
            
        except Exception as e:
            warnings.warn(f"docling-parse extraction failed: {e}. Using fallback.")
            if self.fallback_to_fitz:
                return self._extract_pages_fitz_fallback(file_path)
            else:
                raise
                
    def extract_lines(self, file_path: Path) -> List[PageCells]:
        """Extract text lines (grouped words) from all pages."""
        pages = self.extract_pages(file_path)
        # Line cells are already grouped in extract_pages
        return pages
        
    def _group_words_into_lines(self, word_cells: List[TextCell]) -> List[LineCell]:
        """Group word cells into lines based on y-coordinate proximity."""
        if not word_cells:
            return []
            
        # Sort words by y-coordinate, then x-coordinate
        sorted_words = sorted(word_cells, key=lambda w: (w.y0, w.x0))
        
        lines = []
        current_line_words = []
        last_y = None
        line_height_threshold = 15.0  # points
        
        for word in sorted_words:
            if last_y is not None and abs(word.y0 - last_y) > line_height_threshold:
                # New line
                if current_line_words:
                    line_bbox = self._merge_bboxes([w.bbox for w in current_line_words])
                    lines.append(LineCell(cells=current_line_words, bbox=line_bbox))
                current_line_words = [word]
            else:
                current_line_words.append(word)
            last_y = word.y0
            
        # Add last line
        if current_line_words:
            line_bbox = self._merge_bboxes([w.bbox for w in current_line_words])
            lines.append(LineCell(cells=current_line_words, bbox=line_bbox))
            
        return lines
        
    def _merge_bboxes(self, bboxes: List[Tuple[float, float, float, float]]) -> Tuple[float, float, float, float]:
        """Merge multiple bounding boxes into one."""
        if not bboxes:
            return (0.0, 0.0, 0.0, 0.0)
            
        x0 = min(bbox[0] for bbox in bboxes)
        y0 = min(bbox[1] for bbox in bboxes)
        x1 = max(bbox[2] for bbox in bboxes)
        y1 = max(bbox[3] for bbox in bboxes)
        
        return (x0, y0, x1, y1)
        
    def _extract_pages_fitz_fallback(self, file_path: Path) -> List[PageCells]:
        """Fallback extraction using PyMuPDF (fitz)."""
        warnings.warn("Using fitz fallback for text extraction")
        
        doc = fitz.open(file_path)
        pages = []
        
        for page_index, page in enumerate(doc, start=1):
            word_cells = []
            
            # Extract text blocks and spans
            text_dict = page.get_text("dict")
            for block in text_dict.get("blocks", []):
                if block["type"] == 0:  # text block
                    for line in block.get("lines", []):
                        for span in line.get("spans", []):
                            text = span.get("text", "").strip()
                            if text:
                                bbox = span.get("bbox", (0, 0, 0, 0))
                                cell = TextCell(
                                    text=text,
                                    bbox=bbox,
                                    page_no=page_index,
                                    unit="word"
                                )
                                word_cells.append(cell)
                                
            # Group words into lines
            line_cells = self._group_words_into_lines(word_cells)
            
            pages.append(PageCells(page_no=page_index, word_cells=word_cells,
                                line_cells=line_cells))
                                
        doc.close()
        return pages
