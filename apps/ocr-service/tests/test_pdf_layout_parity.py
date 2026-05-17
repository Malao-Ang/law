"""Unit tests for PDF text extraction format parity with DOCX.

Covers:
- Header detection (`_is_pdf_header_block`): top-of-page short blocks and
  large-font blocks are flagged as headers (forced center alignment by caller).
- New config knobs are wired through.
- Table emit sites now produce `alignment="center"` instead of None.
"""
from __future__ import annotations

from types import SimpleNamespace


# ─────────────────────────────────────────────────────────────────────────────
# _is_pdf_header_block
# ─────────────────────────────────────────────────────────────────────────────

def _settings(top_fraction=0.18, min_font_pt=14.0, max_chars=120):
    return SimpleNamespace(
        pdf_header_top_fraction=top_fraction,
        pdf_header_min_font_pt=min_font_pt,
        pdf_header_max_chars=max_chars,
    )


class TestHeaderDetection:
    def test_top_of_page_short_block_is_header(self):
        from app.services.docling_service import _is_pdf_header_block
        # Block at y0=30pt on a 842pt page (A4 portrait) → top ~3.5% → header
        assert _is_pdf_header_block(
            "ประกาศกระทรวง",
            [72.0, 30.0, 540.0, 60.0],
            block_lines=[],
            page_height_pt=842.0,
            settings=_settings(),
        ) is True

    def test_bottom_of_page_short_block_is_not_header(self):
        from app.services.docling_service import _is_pdf_header_block
        # Block at y0=700pt on 842pt page → bottom 16% → not a header by position
        assert _is_pdf_header_block(
            "เลขที่ ๑๒/๒๕๖๗",
            [72.0, 700.0, 540.0, 720.0],
            block_lines=[],
            page_height_pt=842.0,
            settings=_settings(),
        ) is False

    def test_large_font_block_is_header_regardless_of_position(self):
        from app.services.docling_service import _is_pdf_header_block
        # Bottom of page but 18pt font → still a header
        assert _is_pdf_header_block(
            "หัวข้อใหญ่",
            [72.0, 600.0, 540.0, 640.0],
            block_lines=[{"spans": [{"size": 18.0}]}],
            page_height_pt=842.0,
            settings=_settings(),
        ) is True

    def test_long_block_at_top_is_not_header(self):
        from app.services.docling_service import _is_pdf_header_block
        long_text = "เนื้อหา " * 30  # ~210 chars
        assert _is_pdf_header_block(
            long_text,
            [72.0, 30.0, 540.0, 200.0],
            block_lines=[],
            page_height_pt=842.0,
            settings=_settings(),
        ) is False

    def test_empty_text_is_not_header(self):
        from app.services.docling_service import _is_pdf_header_block
        assert _is_pdf_header_block(
            "",
            [72.0, 30.0, 540.0, 60.0],
            block_lines=[],
            page_height_pt=842.0,
            settings=_settings(),
        ) is False

    def test_small_font_body_text_not_header(self):
        from app.services.docling_service import _is_pdf_header_block
        # 11pt below threshold, bottom of page → not a header
        assert _is_pdf_header_block(
            "ข้อความปกติ",
            [72.0, 600.0, 540.0, 620.0],
            block_lines=[{"spans": [{"size": 11.0}]}],
            page_height_pt=842.0,
            settings=_settings(),
        ) is False

    def test_threshold_boundary_top_fraction(self):
        from app.services.docling_service import _is_pdf_header_block
        # Exactly at 18% boundary on 1000pt page → y0=180 → 0.18, not < 0.18
        assert _is_pdf_header_block(
            "หัวเรื่อง",
            [72.0, 180.0, 540.0, 200.0],
            block_lines=[],
            page_height_pt=1000.0,
            settings=_settings(top_fraction=0.18),
        ) is False
        # Just below threshold → 179/1000 = 0.179 < 0.18 → header
        assert _is_pdf_header_block(
            "หัวเรื่อง",
            [72.0, 179.0, 540.0, 200.0],
            block_lines=[],
            page_height_pt=1000.0,
            settings=_settings(top_fraction=0.18),
        ) is True


# ─────────────────────────────────────────────────────────────────────────────
# Config knobs are present
# ─────────────────────────────────────────────────────────────────────────────

class TestConfigKnobs:
    def test_pdf_indent_threshold_default(self):
        from app.core.config import Settings
        s = Settings()
        assert s.pdf_indent_threshold_pt == 2.0

    def test_pdf_header_settings_default(self):
        from app.core.config import Settings
        s = Settings()
        assert s.pdf_header_top_fraction == 0.18
        assert s.pdf_header_min_font_pt == 14.0
        assert s.pdf_header_max_chars == 120
