from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    app_env: str = "development"
    data_root: Path = Path("/data/poc")
    thai_review_threshold: float = 0.90


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    settings = Settings()
    settings.data_root.mkdir(parents=True, exist_ok=True)
    (settings.data_root / "pages").mkdir(parents=True, exist_ok=True)
    (settings.data_root / "intermediate").mkdir(parents=True, exist_ok=True)
    return settings
