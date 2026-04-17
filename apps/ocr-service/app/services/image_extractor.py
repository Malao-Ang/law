from __future__ import annotations

import base64
from pathlib import Path

_EXT_MIME: dict[str, str] = {
    "png": "image/png",
    "jpg": "image/jpeg",
    "jpeg": "image/jpeg",
    "jfif": "image/jpeg",
    "gif": "image/gif",
    "webp": "image/webp",
    "bmp": "image/bmp",
    "tiff": "image/tiff",
    "tif": "image/tiff",
    "jpx": "image/jp2",
}

# Images larger than this are saved to disk only; smaller ones get an inline data-URI.
_INLINE_MAX_BYTES = 200 * 1024


class ImageExtractor:
    def __init__(self, data_root: Path | None = None) -> None:
        self._data_root = data_root or Path("/tmp")

    def image_dir(self, document_id: str) -> Path:
        path = self._data_root / "images" / document_id
        path.mkdir(parents=True, exist_ok=True)
        return path

    def save(self, data: bytes, ext: str, document_id: str, name: str) -> Path:
        safe = self._normalize_ext(ext)
        out = self.image_dir(document_id) / f"{name}.{safe}"
        out.write_bytes(data)
        return out

    def to_data_uri(self, data: bytes, ext: str) -> str | None:
        if len(data) > _INLINE_MAX_BYTES:
            return None
        safe = self._normalize_ext(ext)
        mime = _EXT_MIME.get(safe, "image/png")
        return f"data:{mime};base64,{base64.b64encode(data).decode()}"

    @staticmethod
    def _normalize_ext(ext: str) -> str:
        safe = ext.lstrip(".").lower()
        return "jpeg" if safe in {"jpg", "jfif"} else safe
