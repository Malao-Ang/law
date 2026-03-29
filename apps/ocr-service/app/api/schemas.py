from pathlib import Path
from typing import Literal

from pydantic import BaseModel, Field


SourceType = Literal["docx", "pdf_text", "pdf_scan"]


class ExtractRequest(BaseModel):
    document_id: str = Field(min_length=1)
    file_path: str = Field(min_length=1)
    enable_ai_correction: bool = True


class ReprocessBlockRequest(BaseModel):
    document_id: str = Field(min_length=1)
    page_no: int = Field(ge=1)
    block_id: str = Field(min_length=1)
    mode: Literal["ai_correction"] = "ai_correction"


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


class BlockDraft(BaseModel):
    block_id: str
    type: str = "paragraph"
    reading_order: int
    raw_text: str
    bbox: list[float] | None = None
    confidence: float = 1.0
    flags: list[str] = Field(default_factory=list)
    # New fields for docling-parse architecture
    indent_level: int = 0
    x_position: float | None = None
    font_size: float | None = None


class PageDraft(BaseModel):
    page_no: int
    image_path: str | None = None
    blocks: list[BlockDraft]


def intermediate_output_path(data_root: Path, document_id: str) -> Path:
    return data_root / "intermediate" / f"{document_id}.review.json"
