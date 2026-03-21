from pathlib import Path

import fitz


class OcrPipeline:
    def __init__(self, data_root: Path) -> None:
        self.data_root = data_root
        self._reader = None

    def extract_scanned_pdf(self, file_path: Path, document_id: str) -> list[dict]:
        doc = fitz.open(file_path)
        pages: list[dict] = []

        for page_index, page in enumerate(doc, start=1):
            image_path = self._render_page_image(page, document_id, page_index)
            lines = self._ocr_image(image_path)
            blocks = [
                {
                    "block_id": f"{page_index}-{idx}",
                    "type": "paragraph" if idx > 1 else "title",
                    "reading_order": idx,
                    "raw_text": line["text"],
                    "bbox": line["bbox"],
                    "confidence": line["confidence"],
                    "flags": ["ocr_scan"],
                }
                for idx, line in enumerate(lines, start=1)
            ]

            pages.append(
                {
                    "page_no": page_index,
                    "image_path": str(image_path),
                    "blocks": blocks,
                }
            )

        doc.close()
        return pages

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
            bbox, text, confidence = item
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

            self._reader = easyocr.Reader(["th", "en"], gpu=False)

        return self._reader
