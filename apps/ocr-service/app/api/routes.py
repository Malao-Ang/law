import json
from pathlib import Path

from fastapi import APIRouter, HTTPException

from app.api.schemas import (
    BlockPatchResponse,
    ExtractRequest,
    HealthResponse,
    ReprocessBlockRequest,
    intermediate_output_path,
)
from app.core.config import get_settings
from app.core.logger import get_logger
from app.services.ai_corrector import MockAICorrector
from app.services.block_builder import build_document_output
from app.services.docling_service import DoclingService
from app.services.ocr_pipeline import OcrPipeline
from app.services.thai_normalizer import normalize_text
from app.utils.file_type import detect_file_type

router = APIRouter()


@router.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    return HealthResponse()


@router.post("/pipeline/extract")
def extract_document(payload: ExtractRequest) -> dict:
    settings = get_settings()
    logger = get_logger(payload.document_id)

    file_path = Path(payload.file_path)
    if not file_path.exists():
        raise HTTPException(status_code=404, detail="Input file does not exist")

    source_type = detect_file_type(file_path)
    logger.info("detected source type", extra={"source_type": source_type})

    docling_service = DoclingService(data_root=settings.data_root)
    ocr_pipeline = OcrPipeline(data_root=settings.data_root)

    if source_type == "pdf_scan":
        pages = ocr_pipeline.extract_scanned_pdf(file_path=file_path, document_id=payload.document_id)
    else:
        pages = docling_service.extract(file_path=file_path, source_type=source_type, document_id=payload.document_id)

    ai_corrector = MockAICorrector()
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

    output_path = intermediate_output_path(settings.data_root, payload.document_id)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(output, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    logger.info("extraction completed", extra={"output_path": str(output_path)})
    return output


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
