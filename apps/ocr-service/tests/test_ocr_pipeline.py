"""
Unit tests for OCR pipeline table detection hardening (Phase 4).

Tests verify:
- Regions with only horizontal lines are rejected (H AND V required).
- Regions covering >70% of the page are rejected as background noise.
- _create_table_block_from_lines returns None for single-column or single-row data.
"""

from __future__ import annotations

import numpy as np
import cv2
import tempfile
from pathlib import Path
from unittest.mock import patch, MagicMock


def _save_image(array: np.ndarray) -> Path:
    """Write a numpy array as a PNG to a temp file and return the path."""
    tmp = tempfile.NamedTemporaryFile(suffix=".png", delete=False)
    cv2.imwrite(tmp.name, array)
    return Path(tmp.name)


def _make_pipeline():
    from app.services.ocr_pipeline import OcrPipeline
    p = OcrPipeline.__new__(OcrPipeline)
    p._table_region_cache = {}
    return p


# ---------------------------------------------------------------------------
# _detect_table_regions
# ---------------------------------------------------------------------------

class TestDetectTableRegions:
    def test_only_horizontal_lines_returns_empty(self):
        """H-only lines (underlines/rules) must NOT produce table regions."""
        # White canvas (255) with a few dark horizontal stripes
        img = np.full((400, 300, 3), 255, dtype=np.uint8)
        for y in [100, 200, 300]:
            cv2.line(img, (10, y), (290, y), (0, 0, 0), 3)

        p = _make_pipeline()
        regions = p._detect_table_regions(_save_image(img))
        assert regions == [], f"Expected no table regions, got {regions}"

    def test_only_vertical_lines_returns_empty(self):
        """V-only lines must NOT produce table regions."""
        img = np.full((400, 300, 3), 255, dtype=np.uint8)
        for x in [75, 150, 225]:
            cv2.line(img, (x, 10), (x, 390), (0, 0, 0), 3)

        p = _make_pipeline()
        regions = p._detect_table_regions(_save_image(img))
        assert regions == [], f"Expected no table regions, got {regions}"

    def test_grid_with_both_h_and_v_detected(self):
        """A proper H+V grid should be detected as a table region."""
        img = np.full((500, 600, 3), 255, dtype=np.uint8)
        # Horizontal lines
        for y in [100, 200, 300, 400]:
            cv2.line(img, (50, y), (550, y), (0, 0, 0), 3)
        # Vertical lines
        for x in [50, 200, 350, 550]:
            cv2.line(img, (x, 100), (x, 400), (0, 0, 0), 3)

        p = _make_pipeline()
        regions = p._detect_table_regions(_save_image(img))
        assert len(regions) >= 1, "Expected at least one table region from a clear grid"

    def test_full_page_detection_rejected(self):
        """A region covering >70% of the page must be rejected (background noise)."""
        # Draw a grid that fills nearly the entire image.
        img = np.full((500, 600, 3), 255, dtype=np.uint8)
        # H lines spanning full width
        for y in range(10, 490, 50):
            cv2.line(img, (5, y), (595, y), (0, 0, 0), 3)
        # V lines spanning full height
        for x in range(10, 590, 60):
            cv2.line(img, (x, 5), (x, 495), (0, 0, 0), 3)

        p = _make_pipeline()
        regions = p._detect_table_regions(_save_image(img))
        # Any full-page contour (w*h > 0.70 * 600*500 = 210_000) must be excluded.
        for r in regions:
            area = r["width"] * r["height"]
            assert area <= 0.70 * 600 * 500, f"Full-page region should be rejected: {r}"

    def test_small_noise_region_rejected(self):
        """Regions smaller than 200×100 must be rejected."""
        img = np.full((500, 600, 3), 255, dtype=np.uint8)
        # Tiny grid in one corner
        for y in [50, 80]:
            cv2.line(img, (10, y), (60, y), (0, 0, 0), 2)
        for x in [10, 40, 60]:
            cv2.line(img, (x, 50), (x, 80), (0, 0, 0), 2)

        p = _make_pipeline()
        regions = p._detect_table_regions(_save_image(img))
        for r in regions:
            assert r["width"] >= 200 and r["height"] >= 100, f"Small noise region not rejected: {r}"

    def test_config_flag_disables_detection(self):
        """enable_ocr_table_detection=False must return [] without touching the image."""
        img = np.full((500, 600, 3), 255, dtype=np.uint8)
        for y in [100, 200, 300]:
            cv2.line(img, (50, y), (550, y), (0, 0, 0), 3)
        for x in [50, 200, 350, 550]:
            cv2.line(img, (x, 100), (x, 300), (0, 0, 0), 3)

        p = _make_pipeline()
        fake_settings = MagicMock()
        fake_settings.enable_ocr_table_detection = False

        with patch("app.services.ocr_pipeline.get_settings", return_value=fake_settings, create=True):
            # Patch inside the module's closure
            import app.services.ocr_pipeline as mod
            orig = getattr(mod, "get_settings", None)
            try:
                mod_settings_patcher = patch("app.core.config.get_settings", return_value=fake_settings)
                with mod_settings_patcher:
                    from app.core.config import get_settings as gs
                    with patch.object(gs, "__call__", return_value=fake_settings):
                        pass
            except Exception:
                pass

        # Direct approach: monkey-patch the import inside the method
        import app.core.config as cfg_mod
        orig_fn = cfg_mod.get_settings
        try:
            cfg_mod.get_settings = lambda: fake_settings  # type: ignore[assignment]
            regions = p._detect_table_regions(_save_image(img))
        finally:
            cfg_mod.get_settings = orig_fn

        assert regions == [], "With enable_ocr_table_detection=False, result must be []"


# ---------------------------------------------------------------------------
# _create_table_block_from_lines
# ---------------------------------------------------------------------------

def _make_line(y: float, x0: float, x1: float, text: str) -> dict:
    return {
        "bbox": [x0, y, x1, y + 14],
        "text": text,
        "confidence": 0.95,
    }


class TestCreateTableBlockFromLines:
    def test_returns_none_for_single_row(self):
        """Only 1 row → not a table → return None."""
        p = _make_pipeline()
        lines = [
            _make_line(100, 50, 200, "cell A"),
            _make_line(100, 210, 350, "cell B"),
        ]
        result = p._create_table_block_from_lines(lines, page_index=1, reading_order=1)
        assert result is None, f"Expected None for single-row input, got {result}"

    def test_returns_none_for_single_column(self):
        """Multiple rows but only 1 column → not a table → return None."""
        p = _make_pipeline()
        # Three rows, each with a single wide cell
        lines = [
            _make_line(100, 50, 400, "row one"),
            _make_line(130, 50, 400, "row two"),
            _make_line(160, 50, 400, "row three"),
        ]
        result = p._create_table_block_from_lines(lines, page_index=1, reading_order=1)
        assert result is None, f"Expected None for single-column table, got {result}"

    def test_returns_block_for_valid_table(self):
        """2 rows × 2 columns → should produce a table block (not None)."""
        p = _make_pipeline()
        lines = [
            _make_line(100, 50, 200, "A"),
            _make_line(100, 210, 360, "B"),
            _make_line(130, 50, 200, "C"),
            _make_line(130, 210, 360, "D"),
        ]
        result = p._create_table_block_from_lines(lines, page_index=1, reading_order=1)
        assert result is not None, "Expected a table block for a valid 2×2 grid"
        assert result.get("type") == "table"

    def test_returns_none_for_empty_lines(self):
        p = _make_pipeline()
        assert p._create_table_block_from_lines([], page_index=1, reading_order=1) is None

    def test_returns_none_for_paragraph_like_region(self):
        """Ruled regions with unstable paragraph-like rows must not become tables."""
        p = _make_pipeline()
        lines = [
            _make_line(100, 50, 540, "long paragraph row one spanning almost entire region"),
            _make_line(130, 50, 540, "long paragraph row two spanning almost entire region"),
            _make_line(160, 50, 540, "long paragraph row three spanning almost entire region"),
        ]
        result = p._create_table_block_from_lines(lines, page_index=1, reading_order=1)
        assert result is None, f"Expected None for paragraph-like region, got {result}"
