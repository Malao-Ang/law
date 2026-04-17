from __future__ import annotations

from pathlib import Path

from app.services.docx_parser import DocxParser
from app.services.image_extractor import ImageExtractor
from app.services.pdf_text_parser import PdfTextParser


class DoclingService:
    def __init__(self, data_root: Path | None = None) -> None:
        images = ImageExtractor(data_root)
        self._pdf = PdfTextParser(images)
        self._docx = DocxParser(images)

    def extract(self, file_path: Path, source_type: str, document_id: str = "doc") -> list[dict]:
        if source_type == "docx":
            blocks = self._docx.extract(file_path, document_id)
            return [{"page_no": 1, "image_path": None, "blocks": blocks}]

        if source_type == "pdf_text":
            return self._pdf.extract(file_path, document_id)

        return []
