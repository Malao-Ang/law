from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    app_env: str = "development"
    data_root: Path = Path("/data/poc")
    thai_review_threshold: float = 0.90
    
    # BBox merge thresholds
    bbox_overlap_threshold: float = 0.30
    
    # Indent detection settings
    indent_cluster_step: float = 18.0  # points (~6mm)
    page_margin_x: float = 72.0       # default left margin
    
    # Gap detection settings  
    tab_gap_threshold: float = 10.0   # points
    space_gap_threshold: float = 3.0  # points


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    settings = Settings()
    settings.data_root.mkdir(parents=True, exist_ok=True)
    (settings.data_root / "pages").mkdir(parents=True, exist_ok=True)
    (settings.data_root / "intermediate").mkdir(parents=True, exist_ok=True)
    return settings
