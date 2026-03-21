import logging


def get_logger(document_id: str) -> logging.LoggerAdapter:
    logger = logging.getLogger("ocr-service")
    if not logger.handlers:
        handler = logging.StreamHandler()
        formatter = logging.Formatter("%(asctime)s %(levelname)s [doc=%(document_id)s] %(message)s")
        handler.setFormatter(formatter)
        logger.addHandler(handler)
        logger.setLevel(logging.INFO)

    return logging.LoggerAdapter(logger, {"document_id": document_id})
