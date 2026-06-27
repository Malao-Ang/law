import json
import time
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator

import httpx
from fastapi import APIRouter, BackgroundTasks, HTTPException

from app.api.schemas import (
    BlockPatchResponse,
    ExtractRequest,
    HealthResponse,
    ReprocessBlockRequest,
    ReprocessPageRequest,
    intermediate_output_path,
)
from app.core.config import get_settings
from app.core.logger import get_logger
from app.services.ai_corrector import MockAICorrector
from app.services.block_builder import build_document_output
from app.services.docling_service import DoclingService
from app.services.landingai_parser import LandingAiAdeParser
from app.services.ocr_pipeline import get_ocr_pipeline
from app.services.thai_normalizer import normalize_text
from app.utils.file_type import detect_file_type

router = APIRouter()


@contextmanager
def _stage_timer(document_id: str, stage: str, logger: object, timings: dict) -> Iterator[None]:
    """Emit a structured STAGE log line and record elapsed ms in *timings*."""
    t0 = time.monotonic()
    try:
        yield
    finally:
        ms = round((time.monotonic() - t0) * 1000)
        timings[stage] = ms
        logger.info("STAGE", extra={"document_id": document_id, "stage": stage, "ms": ms})


@router.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    pipeline = get_ocr_pipeline()
    settings = get_settings()
    return HealthResponse(
        ocr_ready=pipeline.is_ready(),
        device=pipeline.device,
        ocr_gpu_concurrency=settings.ocr_gpu_concurrency,
        ocr_recognizer_batch_size=settings.ocr_recognizer_batch_size,
    )


@router.post("/pipeline/extract", status_code=202)
def extract_document(payload: ExtractRequest, background_tasks: BackgroundTasks) -> dict:
    file_path = Path(payload.file_path)
    if not file_path.exists():
        raise HTTPException(status_code=404, detail="Input file does not exist")

    background_tasks.add_task(_run_extraction, payload)
    return {"status": "accepted", "document_id": payload.document_id}


def _run_extraction(payload: ExtractRequest) -> None:
    settings = get_settings()
    logger = get_logger(payload.document_id)

    file_path = Path(payload.file_path)
    output_path = intermediate_output_path(settings.data_root, payload.document_id)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    timings: dict[str, int] = {}

    try:
        classification = detect_file_type(file_path)
        mode = classification["mode"]
        logger.info("detected source type", extra={"mode": mode})

        docling_service = DoclingService(data_root=settings.data_root)
        requested_scan_mode = payload.scan_extraction_mode
        effective_scan_mode = requested_scan_mode
        extraction_path: list[str] = []
        landingai_metadata: dict[str, object] | None = None

        if mode == "docx":
            with _stage_timer(payload.document_id, "extract", logger, timings):
                pages = docling_service.extract(file_path=file_path, source_type="docx", document_id=payload.document_id)
            extraction_path.append("docling:docx")
            source_type = "docx"
        elif mode == "pdf_text":
            with _stage_timer(payload.document_id, "extract", logger, timings):
                pages = docling_service.extract(file_path=file_path, source_type="pdf_text", document_id=payload.document_id)
            extraction_path.append("docling:pdf_text")
            source_type = "pdf_text"
        elif mode == "pdf_scan":
            with _stage_timer(payload.document_id, "ocr", logger, timings):
                pages, effective_scan_mode, landingai_metadata = _extract_scan_pages(
                    file_path=file_path,
                    document_id=payload.document_id,
                    requested_mode=requested_scan_mode,
                    data_root=settings.data_root,
                )
            extraction_path.append(f"scan:{effective_scan_mode}")
            source_type = "pdf_scan"
        else:
            # mixed: route each page to the appropriate extractor
            with _stage_timer(payload.document_id, "extract", logger, timings):
                scan_indices = set(classification["pages"].get("scan", []))
                scan_results = None
                if scan_indices:
                    scan_results, effective_scan_mode, landingai_metadata = _extract_scan_pages_selective(
                        file_path=file_path,
                        document_id=payload.document_id,
                        requested_mode=requested_scan_mode,
                        page_indices=scan_indices,
                        data_root=settings.data_root,
                    )
                    extraction_path.append(f"scan:{effective_scan_mode}")
                pages = docling_service.extract_mixed_pdf(
                    file_path=file_path,
                    page_classification=classification["pages"],
                    document_id=payload.document_id,
                    ocr_pipeline=_get_ocr_pipeline_if_needed(scan_results is None and bool(scan_indices), settings.data_root),
                    scan_results=scan_results,
                )
            extraction_path.append("docling:pdf_mixed")
            source_type = "pdf_mixed"

        ai_corrector = MockAICorrector()
        with _stage_timer(payload.document_id, "build", logger, timings):
            output = build_document_output(
                document_id=payload.document_id,
                source_file=file_path.name,
                source_type=source_type,
                pages=pages,
                normalizer=normalize_text,
                ai_corrector=ai_corrector,
                enable_ai_correction=payload.enable_ai_correction,
                review_threshold=settings.thai_review_threshold,
            )

        output["timings"] = timings
        output["extraction"] = {
            "scan_extraction_mode_requested": requested_scan_mode,
            "scan_extraction_mode_effective": effective_scan_mode,
            "path": extraction_path,
        }
        if landingai_metadata is not None:
            output["extraction"]["landingai"] = landingai_metadata
        output_path.write_text(
            json.dumps(output, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

        logger.info("extraction completed", extra={"output_path": str(output_path), "timings": timings})

        if payload.callback_url:
            with _stage_timer(payload.document_id, "callback", logger, timings):
                _post_callback(payload.callback_url, payload.document_id, {"status": "success", "output": output}, logger)

    except Exception as exc:
        logger.error("extraction failed", extra={"error": str(exc)})

        failure_payload = {
            "status": "failed",
            "error": str(exc),
            "error_code": "EXTRACTION_FAILED",
        }

        # Write failure state to output path so it can be inspected locally.
        # Guard against the case where output_path itself is not yet defined
        # (e.g. the mkdir call above failed).
        try:
            output_path.write_text(
                json.dumps(failure_payload, ensure_ascii=False, indent=2),
                encoding="utf-8",
            )
        except Exception as write_exc:
            logger.error("failed to write failure payload", extra={"error": str(write_exc)})

        # Always attempt the failure callback so Laravel can mark the document
        # as failed rather than leaving it stuck in "processing".
        if payload.callback_url:
            try:
                _post_callback(payload.callback_url, payload.document_id, failure_payload, logger)
            except Exception as cb_exc:
                logger.error("failure callback also failed", extra={"error": str(cb_exc)})

        raise


def _post_callback(callback_url: str, document_id: str, payload: dict, logger: object) -> None:
    try:
        with httpx.Client(timeout=10) as client:
            response = client.post(callback_url, json={"document_id": document_id, **payload})
        response.raise_for_status()
        logger.info("callback delivered", extra={"callback_url": callback_url, "status_code": response.status_code})
    except httpx.HTTPStatusError as exc:
        logger.error(
            "callback rejected by Laravel",
            extra={"callback_url": callback_url, "status_code": exc.response.status_code, "body": exc.response.text[:500]},
        )
        raise
    except Exception as exc:
        logger.error("callback failed", extra={"callback_url": callback_url, "error": str(exc)})
        raise


def _extract_scan_pages(
    file_path: Path,
    document_id: str,
    requested_mode: str,
    data_root: Path,
) -> tuple[list[dict], str, dict[str, object] | None]:
    logger = get_logger(document_id)

    if requested_mode == "local":
        ocr_pipeline = get_ocr_pipeline(data_root=data_root)
        return ocr_pipeline.extract_scanned_pdf(file_path=file_path, document_id=document_id), "local", None

    if requested_mode == "landingai":
        if not get_settings().landingai_api_key:
            raise RuntimeError("LandingAI mode selected but VISION_AGENT_API_KEY is not configured")
        try:
            parser = LandingAiAdeParser(data_root=data_root)
            pages = parser.parse_pdf(file_path=file_path, document_id=document_id)
            return pages, "landingai", parser.last_metadata
        except Exception as exc:
            logger.warning(
                "landingai mode: API call failed, falling back to local OCR",
                extra={"error": str(exc)},
            )
            ocr_pipeline = get_ocr_pipeline(data_root=data_root)
            return ocr_pipeline.extract_scanned_pdf(file_path=file_path, document_id=document_id), "local", None

    # auto: try local first; fall back to LandingAI if quality is poor and key is configured
    ocr_pipeline = get_ocr_pipeline(data_root=data_root)
    local_pages = ocr_pipeline.extract_scanned_pdf(file_path=file_path, document_id=document_id)
    if _scan_quality_good(local_pages):
        return local_pages, "local", None
    if not get_settings().landingai_api_key:
        logger.warning("auto-mode: local OCR quality poor but VISION_AGENT_API_KEY not set; using local result")
        return local_pages, "local", None
    try:
        parser = LandingAiAdeParser(data_root=data_root)
        pages = parser.parse_pdf(file_path=file_path, document_id=document_id)
        return pages, "landingai", parser.last_metadata
    except Exception as exc:
        logger.warning(
            "auto-mode: LandingAI API failed after quality gate, falling back to local OCR",
            extra={"error": str(exc)},
        )
        return local_pages, "local", None


def _extract_scan_pages_selective(
    file_path: Path,
    document_id: str,
    requested_mode: str,
    page_indices: set[int],
    data_root: Path,
) -> tuple[list[dict], str, dict[str, object] | None]:
    logger = get_logger(document_id)

    if requested_mode == "local":
        ocr_pipeline = get_ocr_pipeline(data_root=data_root)
        pages = ocr_pipeline.extract_scanned_pdf_selective(
            file_path=file_path,
            document_id=document_id,
            page_indices=page_indices,
        )
        return pages, "local", None

    if requested_mode == "landingai":
        if not get_settings().landingai_api_key:
            raise RuntimeError("LandingAI mode selected but VISION_AGENT_API_KEY is not configured")
        try:
            parser = LandingAiAdeParser(data_root=data_root)
            pages = parser.parse_pdf(file_path=file_path, document_id=document_id, page_indices=page_indices)
            return pages, "landingai", parser.last_metadata
        except Exception as exc:
            logger.warning(
                "landingai mode: API call failed, falling back to local OCR",
                extra={"error": str(exc)},
            )
            ocr_pipeline = get_ocr_pipeline(data_root=data_root)
            pages = ocr_pipeline.extract_scanned_pdf_selective(
                file_path=file_path,
                document_id=document_id,
                page_indices=page_indices,
            )
            return pages, "local", None

    # auto: try local first; fall back to LandingAI if quality is poor and key is configured
    ocr_pipeline = get_ocr_pipeline(data_root=data_root)
    local_pages = ocr_pipeline.extract_scanned_pdf_selective(
        file_path=file_path,
        document_id=document_id,
        page_indices=page_indices,
    )
    if _scan_quality_good(local_pages):
        return local_pages, "local", None
    if not get_settings().landingai_api_key:
        logger.warning("auto-mode: local OCR quality poor but VISION_AGENT_API_KEY not set; using local result")
        return local_pages, "local", None
    try:
        parser = LandingAiAdeParser(data_root=data_root)
        pages = parser.parse_pdf(file_path=file_path, document_id=document_id, page_indices=page_indices)
        return pages, "landingai", parser.last_metadata
    except Exception as exc:
        logger.warning(
            "auto-mode: LandingAI API failed after quality gate, falling back to local OCR",
            extra={"error": str(exc)},
        )
        return local_pages, "local", None


def _get_ocr_pipeline_if_needed(needed: bool, data_root: Path) -> object | None:
    if not needed:
        return None
    return get_ocr_pipeline(data_root=data_root)


def _scan_quality_good(pages: list[dict]) -> bool:
    if not pages:
        return False

    text_blocks = 0
    confidence_sum = 0.0
    confidence_count = 0
    uncertain_count = 0

    for page in pages:
        for block in page.get("blocks", []):
            if block.get("type") in {"image"}:
                continue
            text_blocks += 1
            try:
                confidence = float(block.get("confidence", 0.0))
            except (TypeError, ValueError):
                confidence = 0.0
            confidence_sum += confidence
            confidence_count += 1
            flags = block.get("flags") or []
            if isinstance(flags, list) and any("low" in str(flag).lower() for flag in flags):
                uncertain_count += 1

    if text_blocks < 3:
        return False
    if confidence_count == 0:
        return False

    mean_confidence = confidence_sum / confidence_count
    uncertain_ratio = uncertain_count / max(1, text_blocks)
    return mean_confidence >= 0.78 and uncertain_ratio <= 0.50


@router.post("/pipeline/reprocess-page", status_code=202)
def reprocess_page(payload: ReprocessPageRequest, background_tasks: BackgroundTasks) -> dict:
    file_path = Path(payload.file_path)
    if not file_path.exists():
        raise HTTPException(status_code=404, detail="Input file does not exist")
    background_tasks.add_task(_run_page_reprocess, payload)
    return {"status": "accepted", "document_id": payload.document_id, "page_no": payload.page_no}


def _run_page_reprocess(payload: ReprocessPageRequest) -> None:
    settings = get_settings()
    logger = get_logger(payload.document_id)
    file_path = Path(payload.file_path)
    output_path = intermediate_output_path(settings.data_root, payload.document_id)

    try:
        pages, effective_mode, landingai_meta = _extract_scan_pages(
            file_path=file_path,
            document_id=payload.document_id,
            requested_mode=payload.scan_extraction_mode,
            data_root=settings.data_root,
        )

        # Filter to only the requested page.
        new_page = next((p for p in pages if p.get("page_no") == payload.page_no), None)
        if new_page is None:
            logger.warning("reprocess-page: page %d not found in new extraction", payload.page_no)
            return

        # Apply post-processors to the new page.
        from app.services.layout_inferrer import infer_indent_levels
        from app.services.numbering_tokenizer import annotate_blocks
        infer_indent_levels([new_page])
        annotate_blocks(new_page.get("blocks", []))
        for block in new_page.get("blocks", []):
            raw = block.get("raw_text", "")
            result = normalize_text(raw)
            block["normalized_text"] = result["text"]
            block["flags"] = list(set((block.get("flags") or []) + result.get("flags", [])))
            block.setdefault("ai_suggested_text", result["text"])
            block.setdefault("approved_text", result["text"])
            block.setdefault("needs_review", False)

        # Merge into existing output, replacing the old page.
        if output_path.exists():
            data = json.loads(output_path.read_text(encoding="utf-8"))
        else:
            data = {"pages": []}

        existing_pages = [p for p in data.get("pages", []) if p.get("page_no") != payload.page_no]
        existing_pages.append(new_page)
        existing_pages.sort(key=lambda p: p.get("page_no", 0))
        data["pages"] = existing_pages

        extraction = data.get("extraction") or {}
        extraction["scan_extraction_mode_effective"] = effective_mode
        if landingai_meta:
            extraction["landingai"] = landingai_meta
        data["extraction"] = extraction

        output_path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
        logger.info("page reprocessed", extra={"page_no": payload.page_no, "mode": effective_mode})

        if payload.callback_url:
            _post_callback(payload.callback_url, payload.document_id, {"status": "success", "output": data}, logger)

    except Exception as exc:
        logger.error("page reprocess failed", extra={"page_no": payload.page_no, "error": str(exc)})
        if payload.callback_url:
            try:
                _post_callback(payload.callback_url, payload.document_id, {"status": "failed", "error": str(exc)}, logger)
            except Exception:
                pass
        raise


@router.post("/pipeline/reprocess-block", response_model=BlockPatchResponse)
def reprocess_block(payload: ReprocessBlockRequest) -> BlockPatchResponse:
    settings = get_settings()
    logger = get_logger(payload.document_id)

    output_path = intermediate_output_path(settings.data_root, payload.document_id)
    if not output_path.exists():
        raise HTTPException(status_code=404, detail="Review document does not exist")

    data = json.loads(output_path.read_text(encoding="utf-8"))

    target_block: dict | None = None
    for page in data.get("pages", []):
        if int(page.get("page_no", 0)) != payload.page_no:
            continue
        for block in page.get("blocks", []):
            if block.get("block_id") == payload.block_id:
                target_block = block
                break

    if target_block is None:
        raise HTTPException(status_code=404, detail="Block not found")

    normal_text = target_block.get("normalized_text") or target_block.get("raw_text") or ""
    ai = MockAICorrector().suggest(normal_text)

    target_block["ai_suggested_text"] = ai["suggested_text"]
    target_block["confidence"] = ai["confidence"]
    target_block["flags"] = sorted(set((target_block.get("flags") or []) + ai["reason"]))
    target_block["needs_review"] = True

    output_path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")

    logger.info("block reprocessed", extra={"page_no": payload.page_no, "block_id": payload.block_id})

    return BlockPatchResponse(
        document_id=payload.document_id,
        page_no=payload.page_no,
        block_id=payload.block_id,
        ai_suggested_text=ai["suggested_text"],
        confidence=ai["confidence"],
        flags=target_block["flags"],
    )
