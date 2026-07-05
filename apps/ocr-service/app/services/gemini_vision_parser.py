from __future__ import annotations

import base64
import json
import re
from pathlib import Path

import fitz
import httpx

from app.core.config import get_settings
from app.core.logger import get_logger

_GEMINI_API_BASE = "https://generativelanguage.googleapis.com/v1beta"

_OCR_PROMPT = """You are an OCR engine for Thai legal and government documents.
Extract all visible text from this page image in correct reading order (top to bottom, left to right).
Preserve Thai legal structure markers exactly: มาตรา, ข้อ, วรรค, (ก), (ข), Thai numerals (๐-๙).
Do not translate. Do not summarize. Do not invent text not visible in the image.

Return JSON only with this exact shape:
{
  "blocks": [
    {
      "type": "title|paragraph|list_item|table",
      "text": "full text of the block",
      "confidence": 0.95,
      "alignment": "left|center|right"
    }
  ]
}

Use type "title" for centered headings or document titles.
Use type "table" when the content is clearly tabular.
Split into separate blocks when there is a clear paragraph break or structural marker (มาตรา, ข้อ)."""


class GeminiVisionParser:
    """Google Gemini vision adapter for scanned PDF pages."""

    def __init__(self, data_root: Path) -> None:
        self.data_root = data_root
        self.last_metadata: dict[str, object] | None = None

    def parse_pdf(self, file_path: Path, document_id: str, page_indices: set[int] | None = None) -> list[dict]:
        settings = get_settings()
        if not settings.gemini_api_key:
            raise RuntimeError("Gemini mode selected but GEMINI_API_KEY is not configured")

        page_filter = page_indices or set()
        doc = fitz.open(file_path)
        try:
            page_numbers = [
                page_no
                for page_no in range(1, doc.page_count + 1)
                if not page_filter or (page_no - 1) in page_filter
            ]
        finally:
            doc.close()

        pages: list[dict] = []
        total_duration_ms = 0
        model_name = settings.gemini_model
        failed_pages: list[int] = []

        for page_no in page_numbers:
            image_path = self._render_page_image(file_path, document_id, page_no)
            try:
                blocks, duration_ms = self._ocr_page(image_path, page_no, model_name)
                total_duration_ms += duration_ms
            except Exception:
                failed_pages.append(page_no)
                blocks = [
                    self._fallback_block(
                        page_no=page_no,
                        index=1,
                        text="",
                        confidence=0.0,
                        error="gemini_page_failed",
                    )
                ]

            for order, block in enumerate(blocks, start=1):
                block["reading_order"] = order
                layout = block.setdefault("meta", {}).setdefault("layout", {})
                layout["reading_order"] = order

            pages.append(
                {
                    "page_no": page_no,
                    "image_path": str(image_path),
                    "source_kind": "pdf_scan",
                    "blocks": blocks,
                }
            )

        self.last_metadata = {
            "source": "gemini",
            "model": model_name,
            "page_count": len(page_numbers),
            "duration_ms": total_duration_ms,
            "failed_pages": failed_pages,
        }
        return pages

    def _ocr_page(self, image_path: Path, page_no: int, model_name: str) -> tuple[list[dict], int]:
        settings = get_settings()
        logger = get_logger("gemini-ocr")
        image_bytes = image_path.read_bytes()
        encoded = base64.b64encode(image_bytes).decode("ascii")
        mime_type = "image/png" if image_path.suffix.lower() == ".png" else "image/jpeg"

        url = f"{_GEMINI_API_BASE}/models/{model_name}:generateContent"
        params = {"key": settings.gemini_api_key}
        payload = {
            "contents": [
                {
                    "parts": [
                        {"text": _OCR_PROMPT},
                        {"inline_data": {"mime_type": mime_type, "data": encoded}},
                    ]
                }
            ],
            "generationConfig": {
                "temperature": 0.1,
                "responseMimeType": "application/json",
            },
        }

        with httpx.Client(timeout=settings.gemini_timeout_seconds) as client:
            response = client.post(url, params=params, json=payload)
            response.raise_for_status()
            body = response.json()

        logger.info(
            "gemini OCR page completed",
            extra={"page_no": page_no, "model": model_name, "status_code": response.status_code},
        )

        duration_ms = 0
        metadata = body.get("usageMetadata") if isinstance(body, dict) else None
        if isinstance(metadata, dict):
            duration_ms = int(metadata.get("totalTokenCount") or 0)

        raw_text = self._extract_response_text(body)
        parsed = self._parse_blocks_json(raw_text, page_no)
        if parsed:
            return parsed, duration_ms

        text = raw_text.strip()
        if not text:
            return [self._fallback_block(page_no, 1, "", 0.0, "empty_response")], duration_ms
        return [self._fallback_block(page_no, 1, text, 0.85, "plain_text_fallback")], duration_ms

    @staticmethod
    def _extract_response_text(body: dict) -> str:
        candidates = body.get("candidates") if isinstance(body, dict) else None
        if not isinstance(candidates, list) or not candidates:
            return ""
        content = candidates[0].get("content") if isinstance(candidates[0], dict) else None
        if not isinstance(content, dict):
            return ""
        parts = content.get("parts")
        if not isinstance(parts, list):
            return ""
        texts: list[str] = []
        for part in parts:
            if isinstance(part, dict) and part.get("text"):
                texts.append(str(part["text"]))
        return "\n".join(texts).strip()

    def _parse_blocks_json(self, raw_text: str, page_no: int) -> list[dict]:
        cleaned = self._strip_json_fence(raw_text)
        if not cleaned:
            return []

        try:
            payload = json.loads(cleaned)
        except json.JSONDecodeError:
            return []

        blocks_raw = payload.get("blocks") if isinstance(payload, dict) else None
        if not isinstance(blocks_raw, list):
            return []

        blocks: list[dict] = []
        for index, item in enumerate(blocks_raw, start=1):
            if not isinstance(item, dict):
                continue
            text = str(item.get("text") or "").strip()
            if not text:
                continue
            block_type = self._normalize_block_type(str(item.get("type") or "paragraph"))
            alignment = self._normalize_alignment(item.get("alignment"), block_type)
            confidence = self._to_confidence(item.get("confidence"))
            blocks.append(
                {
                    "block_id": f"{page_no}-{index}",
                    "type": block_type,
                    "reading_order": index,
                    "raw_text": text,
                    "bbox": None,
                    "confidence": confidence,
                    "flags": ["gemini_ocr"],
                    "meta": {
                        "gemini": self._gemini_block_meta(page_no=page_no, block_index=index, confidence=confidence),
                        "layout": {
                            "bbox": None,
                            "reading_order": index,
                            "alignment": alignment,
                            "indent_left": None,
                            "indent_first_line": None,
                            "indent_hanging": None,
                            "tabs": [],
                        },
                    },
                }
            )
        return blocks

    def _fallback_block(
        self,
        page_no: int,
        index: int,
        text: str,
        confidence: float,
        reason: str,
    ) -> dict:
        return {
            "block_id": f"{page_no}-{index}",
            "type": "paragraph",
            "reading_order": index,
            "raw_text": text,
            "bbox": None,
            "confidence": confidence,
            "flags": ["gemini_ocr", reason],
            "meta": {
                "gemini": self._gemini_block_meta(page_no=page_no, block_index=index, confidence=confidence),
                "layout": {
                    "bbox": None,
                    "reading_order": index,
                    "alignment": None,
                    "indent_left": None,
                    "indent_first_line": None,
                    "indent_hanging": None,
                    "tabs": [],
                },
            },
        }

    def _gemini_block_meta(self, page_no: int, block_index: int, confidence: float | None) -> dict:
        settings = get_settings()
        response_meta = self.last_metadata or {}
        return {
            "source": "gemini",
            "model": settings.gemini_model,
            "page_no": page_no,
            "block_index": block_index,
            "confidence": confidence,
            "duration_ms": response_meta.get("duration_ms"),
            "failed_pages": response_meta.get("failed_pages", []),
        }

    @staticmethod
    def _strip_json_fence(raw_text: str) -> str:
        text = raw_text.strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text, flags=re.IGNORECASE)
            text = re.sub(r"\s*```$", "", text)
        return text.strip()

    @staticmethod
    def _normalize_block_type(value: str) -> str:
        normalized = value.strip().lower()
        if normalized in {"title", "heading", "section_header"}:
            return "title"
        if normalized in {"table"}:
            return "table"
        if normalized in {"list_item", "list"}:
            return "list_item"
        return "paragraph"

    @staticmethod
    def _normalize_alignment(value: object, block_type: str) -> str | None:
        if isinstance(value, str) and value.strip().lower() in {"left", "center", "right"}:
            return value.strip().lower()
        if block_type == "title":
            return "center"
        return None

    @staticmethod
    def _to_confidence(value: object) -> float:
        try:
            confidence = float(value)
        except (TypeError, ValueError):
            confidence = 0.90
        return max(0.0, min(1.0, confidence))

    def _render_page_image(self, file_path: Path, document_id: str, page_no: int) -> Path:
        page_dir = self.data_root / "pages" / document_id
        page_dir.mkdir(parents=True, exist_ok=True)
        output_path = page_dir / f"page-{page_no}-z1_5.png"
        if output_path.exists():
            return output_path

        doc = fitz.open(file_path)
        page = doc[page_no - 1]
        pix = page.get_pixmap(matrix=fitz.Matrix(1.5, 1.5))
        pix.save(output_path)
        doc.close()
        return output_path
