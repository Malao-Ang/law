"""
Unit tests for Phase 2: PDF ghost tone-mark recovery.

Covers:
- Narrow empty spans between text spans don't produce a word space.
- Wide empty spans (real word gaps) still produce a word space.
- Dictionary recovery of "ชัวโมง" → "ชั่วโมง".
"""

from __future__ import annotations


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _span(text: str, x0: float, x1: float) -> dict:
    return {
        "text": text,
        "flags": 0,
        "font": "THSarabunNew",
        "origin": [x0, 100.0],
        "bbox": [x0, 90.0, x1, 106.0],
    }


def _line(spans: list[dict]) -> dict:
    return {"spans": spans, "bbox": [50.0, 90.0, 450.0, 106.0]}


def _extract(line: dict) -> str:
    from app.services.docling_service import _extract_line_text_with_tabs
    text, _ = _extract_line_text_with_tabs(line, page_margin_x=50.0)
    return text


# ---------------------------------------------------------------------------
# Ghost-sentinel insertion
# ---------------------------------------------------------------------------

class TestGhostToneMark:
    def test_narrow_empty_span_no_space(self):
        """A ≤6pt empty span between two text spans must NOT produce a space."""
        # Simulates ชั [empty 4pt tone-mark span] วโมง
        line = _line([
            _span("ชั", x0=72.0, x1=84.0),
            _span("", x0=84.0, x1=88.0),   # 4pt — ghost tone mark
            _span("วโมง", x0=88.0, x1=120.0),
        ])
        result = _extract(line)
        # No space between ชั and วโมง
        assert " " not in result, f"Unexpected space in {result!r}"
        assert "วโมง" in result

    def test_narrow_empty_span_at_start_ignored(self):
        """A ghost-sentinel at the start of a line (no preceding part) is ignored."""
        line = _line([
            _span("", x0=72.0, x1=75.0),   # leading ghost — no prior parts
            _span("ชั่วโมง", x0=75.0, x1=120.0),
        ])
        result = _extract(line)
        assert "ชั่วโมง" in result

    def test_wide_empty_span_becomes_space(self):
        """An empty span wider than GHOST_TONE_MAX_WIDTH_PT is a real word gap."""
        line = _line([
            _span("คำ", x0=72.0, x1=90.0),
            _span("", x0=90.0, x1=130.0),   # 40pt gap — real inter-word space
            _span("ว่า", x0=130.0, x1=150.0),
        ])
        result = _extract(line)
        # The 40pt gap should trigger the TAB rule (>12pt), not the ghost-tone path.
        # Either way the two words must not be run together.
        assert "คำ" in result and "ว่า" in result
        # They should NOT be concatenated without any separator.
        assert result != "คำว่า"

    def test_normal_text_span_unchanged(self):
        """Spans with non-empty text are joined normally."""
        line = _line([
            _span("สวัสดี", x0=72.0, x1=120.0),
            _span("ครับ", x0=125.0, x1=155.0),
        ])
        result = _extract(line)
        assert "สวัสดี" in result
        assert "ครับ" in result


# ---------------------------------------------------------------------------
# Dictionary recovery
# ---------------------------------------------------------------------------

class TestDictionaryToneRecovery:
    def _normalize(self, text: str) -> dict:
        from app.services.thai_normalizer import normalize_text
        return normalize_text(text)

    def test_chuamong_recovered(self):
        """'ชัวโมง' (missing mai ek) is recovered to 'ชั่วโมง'."""
        result = self._normalize("ชัวโมง")
        assert result["text"] == "ชั่วโมง", f"Got {result['text']!r}"

    def test_naliga_recovered(self):
        result = self._normalize("นาฬกา")
        assert result["text"] == "นาฬิกา", f"Got {result['text']!r}"

    def test_patchuban_recovered(self):
        result = self._normalize("ปจจุบน")
        assert result["text"] == "ปัจจุบัน", f"Got {result['text']!r}"

    def test_correct_word_unchanged(self):
        """Already-correct 'ชั่วโมง' must pass through without change."""
        result = self._normalize("ชั่วโมง")
        assert result["text"] == "ชั่วโมง"

    def test_ocr_fix_flag_set_on_correction(self):
        """A corrected word should carry 'thai_pattern_fix' in flags."""
        result = self._normalize("ชัวโมง")
        assert "thai_pattern_fix" in result.get("flags", []), f"Flags: {result.get('flags')}"
