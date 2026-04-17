from contextlib import asynccontextmanager

from fastapi import FastAPI

from app.api.routes import router
from app.core.config import get_settings
from app.services.docling_service import DoclingService
from app.services.ocr_pipeline import OcrPipeline


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()
    app.state.docling_service = DoclingService(data_root=settings.data_root)
    app.state.ocr_pipeline = OcrPipeline(
        data_root=settings.data_root,
        row_group_threshold_px=settings.ocr_row_group_threshold_px,
    )
    # Pre-warm EasyOCR model so the first request is not slow
    app.state.ocr_pipeline._ensure_reader()
    yield


def create_app() -> FastAPI:
    application = FastAPI(
        title="Thai Legal OCR Pipeline",
        version="0.1.0",
        docs_url="/docs",
        redoc_url="/redoc",
        lifespan=lifespan,
    )
    application.include_router(router)
    return application


app = create_app()
