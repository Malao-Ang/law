from pathlib import Path
from typing import Literal

from pydantic import BaseModel, Field


SourceType = Literal["docx", "pdf_text", "pdf_scan"]
ScanExtractionMode = Literal["auto", "local", "landingai", "gemini"]


class ExtractRequest(BaseModel):
    document_id: str = Field(min_length=1)
    file_path: str = Field(min_length=1)
    enable_ai_correction: bool = True
    callback_url: str | None = None
    scan_extraction_mode: ScanExtractionMode = "auto"


class CorrectRequest(BaseModel):
    document_id: str = Field(min_length=1)
    callback_url: str | None = None
    enable_ai_correction: bool = False


class ReprocessBlockRequest(BaseModel):
    document_id: str = Field(min_length=1)
    page_no: int = Field(ge=1)
    block_id: str = Field(min_length=1)
    mode: Literal["ai_correction"] = "ai_correction"


class ReprocessPageRequest(BaseModel):
    document_id: str = Field(min_length=1)
    file_path: str = Field(min_length=1)
    page_no: int = Field(ge=1)
    scan_extraction_mode: ScanExtractionMode = "gemini"
    callback_url: str | None = None


class BlockPatchResponse(BaseModel):
    document_id: str
    page_no: int
    block_id: str
    ai_suggested_text: str
    confidence: float = Field(ge=0, le=1)
    flags: list[str]


class HealthResponse(BaseModel):
    status: Literal["ok"] = "ok"
    service: Literal["ocr-service"] = "ocr-service"
    ocr_ready: bool = False
    device: str = "cpu"  # "cuda" when GPU is active, "cpu" otherwise
    ocr_gpu_concurrency: int = 1
    ocr_recognizer_batch_size: int = 1
    gemini_configured: bool = False
    landingai_configured: bool = False


class BlockDraft(BaseModel):
    block_id: str
    type: str = "paragraph"
    reading_order: int
    raw_text: str
    bbox: list[float] | None = None
    confidence: float = 1.0
    flags: list[str] = Field(default_factory=list)


class PageDraft(BaseModel):
    page_no: int
    image_path: str | None = None
    blocks: list[BlockDraft]


class NormalizeBlock(BaseModel):
    block_id: str = Field(min_length=1)
    text: str = ""


class NormalizeRequest(BaseModel):
    document_id: str = Field(min_length=1)
    blocks: list[NormalizeBlock]
    autocorrect_min_confidence: float = Field(default=1.0, ge=0, le=1)


class NormalizeBlockResult(BaseModel):
    block_id: str = Field(min_length=1)
    normalized_text: str
    approved_text: str
    auto_corrected: bool
    flags: list[str]
    spell_suggestions: list[dict]


class NormalizeResponse(BaseModel):
    document_id: str = Field(min_length=1)
    results: list[NormalizeBlockResult]


def intermediate_output_path(data_root: Path, document_id: str) -> Path:
    return data_root / "intermediate" / f"{document_id}.review.json"
