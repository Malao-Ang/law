from __future__ import annotations

import re
from pathlib import Path

import fitz
import httpx

from app.core.config import get_settings

# Chunk types that indicate a centered structural title, not body text.
_CENTERED_CHUNK_TYPES = {"heading", "title", "section_header", "caption"}


def _strip_markdown(text: str) -> str:
    """Remove Markdown syntax while keeping numbering markers intact."""
    text = re.sub(r"^#{1,6}\s+", "", text, flags=re.MULTILINE)
    text = re.sub(r"\*{2}([^*]+)\*{2}", r"\1", text)
    text = re.sub(r"\*([^*]+)\*", r"\1", text)
    text = re.sub(r"`([^`]+)`", r"\1", text)
    text = re.sub(r"^\s*[-*+]\s+", "", text, flags=re.MULTILINE)
    return text.strip()


class LandingAiAdeParser:
    """LandingAI ADE Parse adapter for scanned documents."""

    def __init__(self, data_root: Path) -> None:
        self.data_root = data_root
        self.last_metadata: dict[str, object] | None = None

    def parse_pdf(self, file_path: Path, document_id: str, page_indices: set[int] | None = None) -> list[dict]:
        settings = get_settings()
        if not settings.landingai_api_key:
            raise RuntimeError("LandingAI mode selected but VISION_AGENT_API_KEY is not configured")

        page_filter = page_indices or set()
        payload, status_code = self._call_ade_parse(file_path)
        self.last_metadata = self._build_response_metadata(payload, status_code)

        pages = self._build_pages_from_parse(payload, file_path, document_id, page_filter)
        if pages:
            return pages

        return self._build_plain_pages_from_markdown(payload, file_path, document_id, page_filter)

    def _call_ade_parse(self, file_path: Path) -> tuple[dict, int]:
        settings = get_settings()
        url = f"{settings.landingai_base_url.rstrip('/')}/v1/ade/parse"
        headers = {"Authorization": f"Bearer {settings.landingai_api_key}"}
        data = {
            "model": settings.landingai_parse_model,
            "split": "page",
        }

        with file_path.open("rb") as fp:
            files = {"document": (file_path.name, fp, "application/pdf")}
            with httpx.Client(timeout=settings.landingai_timeout_seconds) as client:
                response = client.post(url, headers=headers, data=data, files=files)

            if response.status_code not in (200, 206):
                response.raise_for_status()

            return response.json(), response.status_code

    def _build_response_metadata(self, payload: dict, status_code: int) -> dict[str, object]:
        metadata = payload.get("metadata") if isinstance(payload, dict) else {}
        metadata = metadata if isinstance(metadata, dict) else {}
        failed_pages = metadata.get("failed_pages")
        return {
            "status_code": status_code,
            "filename": metadata.get("filename"),
            "org_id": metadata.get("org_id"),
            "page_count": metadata.get("page_count"),
            "duration_ms": metadata.get("duration_ms"),
            "credit_usage": metadata.get("credit_usage"),
            "job_id": metadata.get("job_id"),
            "version": metadata.get("version"),
            "failed_pages": failed_pages if isinstance(failed_pages, list) else [],
        }

    def _build_pages_from_parse(
        self,
        payload: dict,
        file_path: Path,
        document_id: str,
        page_filter: set[int],
    ) -> list[dict]:
        if not isinstance(payload, dict):
            return []

        chunks = self._extract_chunks(payload)
        splits = self._extract_splits(payload)
        if not chunks and not splits:
            return []

        page_refs = self._collect_page_refs(payload)
        page_base = self._infer_page_base(page_refs)
        global_grounding = self._extract_global_grounding(payload)
        chunk_by_id = self._chunk_map(chunks)

        doc = fitz.open(file_path)
        try:
            page_dims = {i + 1: (doc[i].rect.width, doc[i].rect.height) for i in range(doc.page_count)}
        finally:
            doc.close()

        pages: dict[int, list[dict]] = {}
        for index, chunk in enumerate(chunks, start=1):
            grounding = self._chunk_grounding(chunk, global_grounding)
            page_no = self._to_page_no(grounding.get("page"), page_base)
            if page_no is None:
                continue
            if page_filter and (page_no - 1) not in page_filter:
                continue
            pages.setdefault(page_no, []).append(
                self._chunk_to_block(chunk, page_no, index, page_dims, grounding)
            )

        self._append_split_fallback_pages(
            pages=pages,
            splits=splits,
            chunk_by_id=chunk_by_id,
            page_dims=page_dims,
            global_grounding=global_grounding,
            page_filter=page_filter,
            page_base=page_base,
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
        try:
            page_count = doc.page_count
        finally:
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
                        self._plain_markdown_block(markdown, page_no, 1),
                    ],
                }
            )
        return pages

    def _append_split_fallback_pages(
        self,
        pages: dict[int, list[dict]],
        splits: list[dict],
        chunk_by_id: dict[str, dict],
        page_dims: dict[int, tuple[float, float]],
        global_grounding: dict[str, dict],
        page_filter: set[int],
        page_base: int,
    ) -> None:
        for split_index, split in enumerate(splits, start=1):
            split_pages = self._split_pages(split, page_base)
            if not split_pages:
                continue

            chunk_ids = self._split_chunk_ids(split)
            for page_no in split_pages:
                if page_filter and (page_no - 1) not in page_filter:
                    continue
                if page_no in pages:
                    continue

                split_blocks: list[dict] = []
                if chunk_ids:
                    for index, chunk_id in enumerate(chunk_ids, start=1):
                        chunk = chunk_by_id.get(chunk_id)
                        if not chunk:
                            continue
                        grounding = self._chunk_grounding(chunk, global_grounding)
                        split_blocks.append(self._chunk_to_block(chunk, page_no, index, page_dims, grounding))
                else:
                    markdown = str(split.get("markdown") or "").strip()
                    if markdown:
                        split_blocks.append(self._plain_markdown_block(markdown, page_no, split_index))

                if split_blocks:
                    pages[page_no] = split_blocks

    def _chunk_to_block(
        self,
        chunk: dict,
        page_no: int,
        index: int,
        page_dims: dict[int, tuple[float, float]],
        grounding: dict,
    ) -> dict:
        text = _strip_markdown(str(chunk.get("markdown") or chunk.get("text") or ""))
        chunk_type = str(chunk.get("type") or "").lower()
        block_type = "paragraph"
        if "table" in chunk_type:
            block_type = "table"
        elif "title" in chunk_type or "heading" in chunk_type:
            block_type = "title"
        elif "list" in chunk_type:
            block_type = "list_item"

        alignment = "center" if block_type == "table" or chunk_type in _CENTERED_CHUNK_TYPES else None

        raw_bbox = self._to_bbox(grounding.get("box"))
        bbox = self._scale_bbox(raw_bbox, page_dims.get(page_no))
        confidence = self._to_confidence(
            chunk.get("confidence") if chunk.get("confidence") is not None else grounding.get("confidence")
        )

        return {
            "block_id": f"{page_no}-{index}",
            "type": block_type,
            "reading_order": index,
            "raw_text": text,
            "bbox": bbox,
            "confidence": confidence,
            "flags": ["landingai_parse"],
            "meta": {
                "landingai": self._landingai_block_meta(
                    chunk=chunk,
                    grounding=grounding,
                    page_no=page_no,
                    chunk_index=index,
                    confidence=confidence,
                ),
                "layout": {
                    "bbox": bbox,
                    "reading_order": index,
                    "alignment": alignment,
                    "indent_left": bbox[0] if bbox is not None else None,
                    "indent_first_line": None,
                    "indent_hanging": None,
                    "tabs": [],
                },
            },
        }

    def _plain_markdown_block(self, markdown: str, page_no: int, index: int) -> dict:
        return {
            "block_id": f"{page_no}-{index}",
            "type": "paragraph",
            "reading_order": index,
            "raw_text": _strip_markdown(markdown),
            "bbox": None,
            "confidence": 0.90,
            "flags": ["landingai_parse"],
            "meta": {
                "landingai": self._landingai_block_meta(
                    chunk=None,
                    grounding={},
                    page_no=page_no,
                    chunk_index=index,
                    confidence=0.90,
                ),
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

    def _landingai_block_meta(
        self,
        chunk: dict | None,
        grounding: dict,
        page_no: int,
        chunk_index: int,
        confidence: float | None = None,
    ) -> dict:
        settings = get_settings()
        response_meta = self.last_metadata or {}
        return {
            "source": "landingai",
            "model": settings.landingai_parse_model,
            "chunk_id": (chunk or {}).get("id"),
            "chunk_type": (chunk or {}).get("type"),
            "page_no": page_no,
            "chunk_index": chunk_index,
            "confidence": confidence,
            "response_status": response_meta.get("status_code"),
            "job_id": response_meta.get("job_id"),
            "page_count": response_meta.get("page_count"),
            "duration_ms": response_meta.get("duration_ms"),
            "credit_usage": response_meta.get("credit_usage"),
            "failed_pages": response_meta.get("failed_pages", []),
            "grounding_type": grounding.get("type"),
            "low_confidence_spans": grounding.get("low_confidence_spans", []),
        }

    @staticmethod
    def _extract_chunks(payload: dict) -> list[dict]:
        chunks = payload.get("chunks")
        return [chunk for chunk in chunks if isinstance(chunk, dict)] if isinstance(chunks, list) else []

    @staticmethod
    def _extract_splits(payload: dict) -> list[dict]:
        splits = payload.get("splits")
        return [split for split in splits if isinstance(split, dict)] if isinstance(splits, list) else []

    @staticmethod
    def _extract_global_grounding(payload: dict) -> dict[str, dict]:
        grounding = payload.get("grounding")
        if not isinstance(grounding, dict):
            return {}
        return {str(key): value for key, value in grounding.items() if isinstance(value, dict)}

    @staticmethod
    def _chunk_map(chunks: list[dict]) -> dict[str, dict]:
        return {str(chunk.get("id")): chunk for chunk in chunks if chunk.get("id") is not None}

    @staticmethod
    def _chunk_grounding(chunk: dict, global_grounding: dict[str, dict]) -> dict:
        grounding = chunk.get("grounding")
        if isinstance(grounding, dict):
            return grounding
        chunk_id = str(chunk.get("id") or "")
        if chunk_id and chunk_id in global_grounding:
            return global_grounding[chunk_id]
        return {}

    @staticmethod
    def _split_pages(split: dict, page_base: int) -> list[int]:
        pages = split.get("pages")
        if not isinstance(pages, list):
            return []
        normalized: list[int] = []
        for value in pages:
            page_no = LandingAiAdeParser._to_page_no(value, page_base)
            if page_no is not None:
                normalized.append(page_no)
        return normalized

    @staticmethod
    def _split_chunk_ids(split: dict) -> list[str]:
        chunk_ids = split.get("chunks")
        if not isinstance(chunk_ids, list):
            return []
        return [str(chunk_id) for chunk_id in chunk_ids if chunk_id is not None]

    @staticmethod
    def _collect_page_refs(payload: dict) -> list[int]:
        refs: list[int] = []
        for chunk in LandingAiAdeParser._extract_chunks(payload):
            grounding = chunk.get("grounding") if isinstance(chunk.get("grounding"), dict) else {}
            page = grounding.get("page")
            if isinstance(page, int):
                refs.append(page)
        for grounding in LandingAiAdeParser._extract_global_grounding(payload).values():
            page = grounding.get("page")
            if isinstance(page, int):
                refs.append(page)
        for split in LandingAiAdeParser._extract_splits(payload):
            pages = split.get("pages")
            if not isinstance(pages, list):
                continue
            for page in pages:
                if isinstance(page, int):
                    refs.append(page)
        return refs

    @staticmethod
    def _infer_page_base(page_refs: list[int]) -> int:
        return 0 if any(page == 0 for page in page_refs) else 1

    @staticmethod
    def _to_page_no(value: object, page_base: int) -> int | None:
        try:
            page_no = int(value)
        except (TypeError, ValueError):
            return None
        if page_base == 0:
            return page_no + 1 if page_no >= 0 else None
        return page_no if page_no >= 1 else None

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
        """Scale normalised [0, 1] LandingAI bbox coords to PDF points."""
        if bbox is None or page_dim is None:
            return bbox
        w, h = page_dim
        x0, y0, x1, y1 = bbox
        if max(x1, y1) <= 2.0:
            return [x0 * w, y0 * h, x1 * w, y1 * h]
        return bbox

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
