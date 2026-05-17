from functools import lru_cache
from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    app_env: str = "development"
    data_root: Path = Path("/data/poc")
    thai_review_threshold: float = 0.90
    
    # BBox merge thresholds
    bbox_overlap_threshold: float = 0.30          # text-block vs table-region masking
    table_cell_overlap_threshold: float = 0.15    # word-to-cell matching in table_text_merger
    
    # Indent detection settings
    indent_cluster_step: float = 18.0  # points (~6mm)
    page_margin_x: float = 72.0       # default left margin
    
    # Gap detection settings
    tab_gap_threshold: float = 10.0   # points
    space_gap_threshold: float = 3.0  # points

    # First-line indent inference
    # Default indent (twips) inferred from a leading TAB character when
    # the source document doesn't define w:firstLine (common in Thai legal DOCX).
    first_line_indent_default_twips: int = 720  # 36pt ≈ standard half-inch tab

    # Scanned-PDF OCR performance
    # Set enable_ocrmypdf_prepass=true in .env to run OCRmyPDF before EasyOCR
    # (adds 5-10× latency; rarely improves accuracy for Thai legal scans).
    enable_ocrmypdf_prepass: bool = False
    enable_ocr_table_detection: bool = True  # set False to skip OCR table grouping entirely
    ocr_page_workers: int = 4       # thread-pool size for page-level concurrency
    ocr_zoom_fast: float = 1.5      # rasterise zoom for first-pass OCR
    ocr_zoom_slow: float = 2.5      # re-rasterise zoom when confidence is low

    # GPU throughput (Phase B)
    # ocr_gpu_concurrency: number of concurrent readtext() calls allowed on GPU.
    # PyTorch queues CUDA work so two concurrent calls share the GPU without
    # corrupting model state.  Set to 1 to restore the old serialised behaviour.
    ocr_gpu_concurrency: int = 2
    # EasyOCR recogniser batch size — passes N crops to the CUDA kernel per call.
    # Default 1 is conservative; 8 nearly doubles throughput on GPU.
    ocr_recognizer_batch_size: int = 8

    # Adaptive zoom heuristic (Phase C)
    # When enabled, selects zoom based on source PDF page DPI so low-quality
    # scans render at high zoom in a single pass (no confidence-check retry).
    # Set to False to revert to the two-pass fast→slow retry behaviour.
    ocr_render_zoom_heuristic_enabled: bool = True

    # PDF text format parity — force-center headers (top-of-page short blocks)
    # and lift fine first-line indents that the legacy 5pt threshold dropped.
    pdf_header_top_fraction: float = 0.18   # blocks within top 18% of page count as headers
    pdf_header_min_font_pt: float = 14.0    # ≥14pt font also counts as header
    pdf_header_max_chars: int = 120         # very long blocks are not headers
    pdf_indent_threshold_pt: float = 2.0    # min first-line/hanging diff to record (was 5.0)

    # LandingAI ADE Parse integration (optional)
    landingai_api_key: str | None = Field(default=None, validation_alias="VISION_AGENT_API_KEY")
    landingai_base_url: str = "https://api.va.landing.ai"
    landingai_parse_model: str = "dpt-2-latest"
    landingai_timeout_seconds: int = 60


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    settings = Settings()
    settings.data_root.mkdir(parents=True, exist_ok=True)
    (settings.data_root / "pages").mkdir(parents=True, exist_ok=True)
    (settings.data_root / "intermediate").mkdir(parents=True, exist_ok=True)
    return settings
