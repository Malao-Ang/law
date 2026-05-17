from __future__ import annotations

import re
from pathlib import Path

import fitz
import httpx

from app.core.config import get_settings

# Chunk types that indicate a centered structural title (not body text).
_CENTERED_CHUNK_TYPES = {"heading", "title", "section_header", "caption"}

# Strip markdown syntax that the numbering_tokenizer / semantic resolver
# must not see.  Numbered lists (๑., (ก), 1.) are intentionally kept so
# numbering_tokenizer can annotate them.
def _strip_markdown(text: str) -> str:
    text = re.sub(r"^#{1,6}\s+", "", text, flags=re.MULTILINE)   # # headings
    text = re.sub(r"\*{2}([^*]+)\*{2}", r"\1", text)              # **bold**
    text = re.sub(r"\*([^*]+)\*", r"\1", text)                    # *italic*
    text = re.sub(r"`([^`]+)`", r"\1", text)                      # `code`
    text = re.sub(r"^\s*[-*+]\s+", "", text, flags=re.MULTILINE)  # bullet prefixes
    return text.strip()


class LandingAiAdeParser:
    """LandingAI ADE Parse adapter for scanned documents."""

    def __init__(self, data_root: Path) -> None:
        self.data_root = data_root

    def parse_pdf(self, file_path: Path, document_id: str, page_indices: set[int] | None = None) -> list[dict]:
        settings = get_settings()
        if not settings.landingai_api_key:
            raise RuntimeError("LandingAI mode selected but VISION_AGENT_API_KEY is not configured")

        page_filter = page_indices or set()
        parsed = self._call_ade_parse(file_path)
        pages = self._build_pages_from_parse(parsed, file_path, document_id, page_filter)
        if pages:
            return pages

        # Fallback when parse response has no page grounding.
        return self._build_plain_pages_from_markdown(parsed, file_path, document_id, page_filter)

    def _call_ade_parse(self, file_path: Path) -> dict:
        settings = get_settings()
        url = f"{settings.landingai_base_url.rstrip('/')}/v1/ade/parse"
        headers = {
            "Authorization": f"Bearer {settings.landingai_api_key}",
        }
        data = {
            "model": settings.landingai_parse_model,
            "split": "page",
        }

        with file_path.open("rb") as fp:
            files = {"document": (file_path.name, fp, "application/pdf")}
            with httpx.Client(timeout=settings.landingai_timeout_seconds) as client:
                response = client.post(url, headers=headers, data=data, files=files)
            response.raise_for_status()
            return response.json()

    def _build_pages_from_parse(
        self,
        payload: dict,
        file_path: Path,
        document_id: str,
        page_filter: set[int],
    ) -> list[dict]:
        chunks = payload.get("chunks") if isinstance(payload, dict) else None
        if not isinstance(chunks, list):
            return []

        # Pre-compute page dimensions (points) for bbox normalisation.
        doc = fitz.open(file_path)
        page_dims = {i + 1: (doc[i].rect.width, doc[i].rect.height) for i in range(doc.page_count)}
        doc.close()

        pages: dict[int, list[dict]] = {}
        for index, chunk in enumerate(chunks, start=1):
            if not isinstance(chunk, dict):
                continue
            grounding = chunk.get("grounding") if isinstance(chunk.get("grounding"), dict) else {}
            page_no = self._to_page_no(grounding.get("page"))
            if page_no is None:
                continue
            if page_filter and (page_no - 1) not in page_filter:
                continue
            pages.setdefault(page_no, []).append(
                self._chunk_to_block(chunk, page_no, index, page_dims)
            )

        if not pages:
            return []

        rendered = []
        for page_no in sorted(pages):
            image_path = self._render_page_image(file_path, document_id, page_no)
            blocks = pages[page_no]
            for order, block in enumerate(blocks, start=1):
                block["reading_order"] = order
                layout = block.setdefault("meta", {}).setdefault("layout", {})
                layout["reading_order"] = order
            rendered.append(
                {
                    "page_no": page_no,
                    "image_path": str(image_path),
                    "source_kind": "pdf_scan",
                    "blocks": blocks,
                }
            )
        return rendered

    def _build_plain_pages_from_markdown(
        self,
        payload: dict,
        file_path: Path,
        document_id: str,
        page_filter: set[int],
    ) -> list[dict]:
        markdown = str(payload.get("markdown") or "")
        if not markdown:
            return []

        doc = fitz.open(file_path)
        page_count = doc.page_count
        doc.close()

        pages = []
        for page_no in range(1, page_count + 1):
            if page_filter and (page_no - 1) not in page_filter:
                continue
            image_path = self._render_page_image(file_path, document_id, page_no)
            pages.append(
                {
                    "page_no": page_no,
                    "image_path": str(image_path),
                    "source_kind": "pdf_scan",
                    "blocks": [
                        {
                            "block_id": f"{page_no}-1",
                            "type": "paragraph",
                            "reading_order": 1,
                            "raw_text": _strip_markdown(markdown),
                            "bbox": None,
                            "confidence": 0.90,
                            "flags": ["landingai_parse"],
                            "meta": {
                                "landingai": {
                                    "source": "landingai",
                                    "model": get_settings().landingai_parse_model,
                                },
                                "layout": {
                                    "bbox": None,
                                    "reading_order": 1,
                                    "alignment": None,
                                    "indent_left": None,
                                    "indent_first_line": None,
                                    "indent_hanging": None,
                                    "tabs": [],
                                },
                            },
                        }
                    ],
                }
            )
        return pages

    def _chunk_to_block(self, chunk: dict, page_no: int, index: int, page_dims: dict) -> dict:
        text = _strip_markdown(str(chunk.get("markdown") or chunk.get("text") or ""))
        chunk_type = str(chunk.get("type") or "").lower()
        block_type = "paragraph"
        if "table" in chunk_type:
            block_type = "table"
        elif "title" in chunk_type or "heading" in chunk_type:
            block_type = "title"
        elif "list" in chunk_type:
            block_type = "list_item"

        # Structural title/heading blocks are visually centred — the semantic
        # resolver uses alignment="center" to identify context-reset boundaries.
        alignment = "center" if chunk_type in _CENTERED_CHUNK_TYPES else None

        grounding = chunk.get("grounding") if isinstance(chunk.get("grounding"), dict) else {}
        raw_bbox = self._to_bbox(grounding.get("box"))
        bbox = self._scale_bbox(raw_bbox, page_dims.get(page_no))
        confidence = self._to_confidence(chunk.get("confidence"))

        # Derive indent_left from the scaled x0 so layout_inferrer can cluster
        # x-positions across the document into discrete indent levels.
        indent_left: float | None = bbox[0] if bbox is not None else None

        return {
            "block_id": f"{page_no}-{index}",
            "type": block_type,
            "reading_order": index,
            "raw_text": text,
            "bbox": bbox,
            "confidence": confidence,
            "flags": ["landingai_parse"],
            "meta": {
                "landingai": {
                    "source": "landingai",
                    "model": get_settings().landingai_parse_model,
                    "chunk_id": chunk.get("id"),
                    "chunk_type": chunk.get("type"),
                    "confidence": confidence,
                },
                "layout": {
                    "bbox": bbox,
                    "reading_order": index,
                    "alignment": alignment,
                    "indent_left": indent_left,
                    "indent_first_line": None,
                    "indent_hanging": None,
                    "tabs": [],
                },
            },
        }

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

    @staticmethod
    def _scale_bbox(bbox: list[float] | None, page_dim: tuple[float, float] | None) -> list[float] | None:
        """Scale normalised [0–1] LandingAI bbox coords to PDF points.

        LandingAI ADE returns box coordinates normalised to [0, 1] (fraction of
        page width/height).  The layout inferrer needs absolute x in points.
        If the coordinates already look like points (any value > 2.0), pass through.
        """
        if bbox is None or page_dim is None:
            return bbox
        w, h = page_dim
        x0, y0, x1, y1 = bbox
        if max(x1, y1) <= 2.0:
            return [x0 * w, y0 * h, x1 * w, y1 * h]
        return bbox

    @staticmethod
    def _to_page_no(value: object) -> int | None:
        try:
            page_no = int(value)
        except (TypeError, ValueError):
            return None
        return page_no if page_no >= 1 else None

    @staticmethod
    def _to_bbox(value: object) -> list[float] | None:
        if not isinstance(value, (list, tuple)) or len(value) < 4:
            return None
        try:
            x0 = float(value[0])
            y0 = float(value[1])
            x1 = float(value[2])
            y1 = float(value[3])
        except (TypeError, ValueError):
            return None
        return [x0, y0, x1, y1]

    @staticmethod
    def _to_confidence(value: object) -> float:
        try:
            confidence = float(value)
        except (TypeError, ValueError):
            confidence = 0.90
        return max(0.0, min(1.0, confidence))
