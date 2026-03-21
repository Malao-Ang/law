from pydantic import BaseModel, Field


class BlockModel(BaseModel):
    block_id: str
    type: str = "paragraph"
    reading_order: int = Field(ge=0)
    raw_text: str
    normalized_text: str
    ai_suggested_text: str
    approved_text: str
    confidence: float = Field(ge=0, le=1)
    needs_review: bool
    flags: list[str] = Field(default_factory=list)
    bbox: list[float] | None = None
    meta: dict = Field(default_factory=dict)
