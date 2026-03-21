from pydantic import BaseModel, Field

from app.models.block import BlockModel


class PageModel(BaseModel):
    page_no: int = Field(ge=1)
    image_path: str | None = None
    blocks: list[BlockModel]


class DocumentModel(BaseModel):
    document_id: str
    source_file: str
    source_type: str
    language: str = "th"
    summary: dict
    pages: list[PageModel]
