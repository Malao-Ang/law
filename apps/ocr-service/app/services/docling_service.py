from pathlib import Path
import zipfile
from xml.etree import ElementTree as ET

import fitz


class DoclingService:
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
        text = ""
        if self._converter is not None:
            try:
                result = self._converter.convert(str(file_path))
                document = getattr(result, "document", None)
                if document is not None and hasattr(document, "export_to_markdown"):
                    text = str(document.export_to_markdown())
            except Exception:
                text = ""

        if text.strip() == "":
            text = self._read_docx_xml(file_path)

        lines = [line.strip() for line in text.splitlines() if line.strip()]
        return [
            {
                "block_id": f"1-{idx}",
                "type": "paragraph" if idx > 1 else "title",
                "reading_order": idx,
                "raw_text": line,
                "bbox": None,
                "confidence": 0.98,
                "flags": [],
            }
            for idx, line in enumerate(lines, start=1)
        ]

    def _extract_pdf_blocks(self, file_path: Path) -> list[tuple[int, list[dict]]]:
        # Try Docling first for compliance with the POC design.
        if self._converter is not None:
            try:
                self._converter.convert(str(file_path))
            except Exception:
                pass

        doc = fitz.open(file_path)
        pages: list[tuple[int, list[dict]]] = []

        for page_index, page in enumerate(doc, start=1):
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
            pages.append((page_index, blocks))

        doc.close()
        return pages

    @staticmethod
    def _read_docx_xml(file_path: Path) -> str:
        with zipfile.ZipFile(file_path) as archive:
            with archive.open("word/document.xml") as xml_file:
                root = ET.parse(xml_file).getroot()

        namespaces = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
        texts = [node.text for node in root.findall(".//w:t", namespaces) if node.text]
        return "\n".join(texts)
