import threading

from fastapi import FastAPI

from app.api.routes import router
from app.core.config import get_settings


def create_app() -> FastAPI:
    settings = get_settings()

    application = FastAPI(
        title="Thai Legal OCR Pipeline",
        version="0.1.0",
        docs_url="/docs",
        redoc_url="/redoc",
    )
    application.include_router(router)
    application.state.settings = settings

    @application.on_event("startup")
    def _warmup_ocr() -> None:
        # Load the EasyOCR model in a background thread so the service becomes
        # reachable immediately while the ~2 GB model weights are loading.
        from app.services.ocr_pipeline import get_ocr_pipeline, warmup_ocr

        data_root = settings.data_root
        get_ocr_pipeline(data_root=data_root)

        thread = threading.Thread(target=warmup_ocr, daemon=True, name="ocr-warmup")
        thread.start()

    return application


app = create_app()
