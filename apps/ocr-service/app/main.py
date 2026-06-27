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
        from app.services.thai_spellchecker import get_spell_checker
        import logging

        data_root = settings.data_root
        pipeline = get_ocr_pipeline(data_root=data_root)
        logger = logging.getLogger("uvicorn")

        def _warmup_and_log() -> None:
            warmup_ocr()
            logger.info("OCR model ready — device: %s", pipeline.device)

        def _warmup_spellchecker() -> None:
            try:
                get_spell_checker().is_available()
            except Exception:
                logger.exception("Thai spellchecker warmup failed")

        thread = threading.Thread(target=_warmup_and_log, daemon=True, name="ocr-warmup")
        thread.start()
        spell_thread = threading.Thread(target=_warmup_spellchecker, daemon=True, name="spellchecker-warmup")
        spell_thread.start()

    return application


app = create_app()
