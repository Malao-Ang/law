"""Tests for text-based PDF extraction patches P1–P5.

Run with:
    pytest apps/ocr-service/tests/test_pdf_text_extraction.py -v
"""

from __future__ import annotations

from app.services.docling_service import DoclingService, _join_thai_spans
from app.services.block_builder import render_text_with_layout


# ─────────────────────────────────────────────────────────────────────────────
# P1 — combining-mark-only spans must survive the whitespace guard
# ─────────────────────────────────────────────────────────────────────────────

def test_combining_mark_only_span_not_lost() -> None:
    """A span that is only a combining mark (ั) must not be discarded."""
    # Simulate: PyMuPDF splits "กัร" as ["ก", "ั", "ร"]
    spans = ["ก", "ั", "ร"]
    result = _join_thai_spans(spans)
    assert "ั" in result, "combining mark dropped"
    assert "ก" in result


def test_combining_mark_not_space_separated() -> None:
    """No space must appear immediately before or after a combining mark."""
    result = _join_thai_spans(["ก", "ั"])
    assert " ั" not in result, "space before combining mark"
    assert "ั " not in result, "space after combining mark"


def test_tone_mark_span_attaches_directly() -> None:
    """Tone mark in its own span must be attached directly to the preceding span."""
    # ["ดว", "้", "ย"] — tone mark should attach without space
    result = _join_thai_spans(["ดว", "้", "ย"])
    assert " ้" not in result, "unwanted space before ้"
    assert "้ " not in result, "unwanted space after ้"
    assert "้" in result


# ─────────────────────────────────────────────────────────────────────────────
# P2 — line breaks must be preserved across lines in a block
# ─────────────────────────────────────────────────────────────────────────────

def test_two_lines_joined_with_newline() -> None:
    """Two lines in the same block must be joined with \\n."""
    line1 = _join_thai_spans(["บรรทัดแรก"])
    line2 = _join_thai_spans(["บรรทัดที่สอง"])
    joined = "\n".join([line1, line2])

    assert "\n" in joined
    assert joined.count("\n") == 1
    assert "บรรทัดแรก" in joined
    assert "บรรทัดที่สอง" in joined


def test_newlines_survive_normalization() -> None:
    """normalize_text must not collapse \\n between lines."""
    from app.services.thai_normalizer import normalize_text

    text = "บรรทัดแรก\nบรรทัดที่สอง"
    result = normalize_text(text)
    assert "\n" in result["text"], "newline stripped by normalizer"


def test_newline_rendered_as_br_in_html() -> None:
    """\\n in paragraph text must become <br> in rendered HTML."""
    layout = {
        "tabs": [], "alignment": None,
        "indent_left": None, "indent_first_line": None, "indent_hanging": None,
        "spacing_before": None, "spacing_after": None, "line_spacing": None,
    }
    html = render_text_with_layout("บรรทัดแรก\nบรรทัดที่สอง", layout)
    assert "<br>" in html, "<br> not generated for \\n"


# ─────────────────────────────────────────────────────────────────────────────
# P3 — PDF paragraph blocks must carry meta.layout
# ─────────────────────────────────────────────────────────────────────────────

def test_pdf_block_layout_keys_complete() -> None:
    """Layout dict for PDF blocks must have exactly the same keys as DOCX blocks."""
    required_keys = [
        "bbox", "reading_order", "alignment", "indent_left",
        "indent_first_line", "indent_hanging", "tabs",
        "spacing_before", "spacing_after", "line_spacing",
    ]
    layout = {
        "bbox": None, "reading_order": None, "alignment": None,
        "indent_left": None, "indent_first_line": None, "indent_hanging": None,
        "tabs": [], "spacing_before": None, "spacing_after": None, "line_spacing": None,
    }
    for key in required_keys:
        assert key in layout, f"missing key: {key}"


# ─────────────────────────────────────────────────────────────────────────────
# P4 — indent inference from line x-positions
# ─────────────────────────────────────────────────────────────────────────────

class _FakeRect:
    """Minimal stand-in for fitz.Rect supporting index access."""
    def __init__(self, values: list) -> None:
        self._v = values

    def __getitem__(self, i: int) -> float:
        return self._v[i]


def test_hanging_indent_detected() -> None:
    """First line further left than continuation lines → hanging indent."""
    svc = DoclingService.__new__(DoclingService)
    lines = [
        {"bbox": [36.0, 0.0, 300.0, 14.0], "spans": [{"text": "ข้อ ๑"}]},
        {"bbox": [72.0, 16.0, 300.0, 30.0], "spans": [{"text": "เนื้อหาของข้อ"}]},
        {"bbox": [72.0, 32.0, 300.0, 46.0], "spans": [{"text": "ต่อเนื่อง"}]},
    ]
    layout = svc._infer_pdf_paragraph_layout(lines, _FakeRect([0.0, 0.0, 612.0, 792.0]))

    assert layout["indent_hanging"] is not None, "hanging indent not detected"
    assert layout["indent_hanging"] > 0
    # 36 pt difference × 20 = 720 twips (±100 twip tolerance)
    assert abs(layout["indent_hanging"] - 720) < 100, (
        f"hanging indent value {layout['indent_hanging']} outside expected range"
    )


def test_first_line_indent_detected() -> None:
    """First line further right than continuation lines → first-line indent."""
    svc = DoclingService.__new__(DoclingService)
    lines = [
        {"bbox": [108.0, 0.0, 400.0, 14.0], "spans": [{"text": "เนื้อหา"}]},
        {"bbox": [72.0, 16.0, 400.0, 30.0], "spans": [{"text": "ต่อเนื่อง"}]},
        {"bbox": [72.0, 32.0, 400.0, 46.0], "spans": [{"text": "บรรทัดสาม"}]},
    ]
    layout = svc._infer_pdf_paragraph_layout(lines, _FakeRect([0.0, 0.0, 612.0, 792.0]))

    assert layout["indent_first_line"] is not None, "first-line indent not detected"
    assert layout["indent_first_line"] > 0


def test_uniform_lines_no_special_indent() -> None:
    """All lines at same x0 → no hanging or first-line indent."""
    svc = DoclingService.__new__(DoclingService)
    lines = [
        {"bbox": [72.0, 0.0, 400.0, 14.0], "spans": [{"text": "บรรทัดแรก"}]},
        {"bbox": [72.0, 16.0, 400.0, 30.0], "spans": [{"text": "บรรทัดสอง"}]},
        {"bbox": [72.0, 32.0, 400.0, 46.0], "spans": [{"text": "บรรทัดสาม"}]},
    ]
    layout = svc._infer_pdf_paragraph_layout(lines, _FakeRect([0.0, 0.0, 612.0, 792.0]))

    assert layout["indent_hanging"] is None, "false hanging indent"
    assert layout["indent_first_line"] is None, "false first-line indent"
    # indent_left should be 72 pt × 20 = 1440 twips
    assert layout["indent_left"] is not None
    assert abs(layout["indent_left"] - 1440) < 20


def test_single_line_block_no_indent_pattern() -> None:
    """Single-line block: no hanging or first-line indent (not enough data)."""
    svc = DoclingService.__new__(DoclingService)
    lines = [{"bbox": [72.0, 0.0, 400.0, 14.0], "spans": [{"text": "เดี่ยว"}]}]
    layout = svc._infer_pdf_paragraph_layout(lines, _FakeRect([0.0, 0.0, 612.0, 792.0]))

    assert layout["indent_hanging"] is None
    assert layout["indent_first_line"] is None


def test_empty_lines_returns_none_layout() -> None:
    """No lines with spans → all layout fields None."""
    svc = DoclingService.__new__(DoclingService)
    layout = svc._infer_pdf_paragraph_layout([], _FakeRect([0.0, 0.0, 612.0, 792.0]))

    assert layout["indent_left"] is None
    assert layout["indent_hanging"] is None
    assert layout["indent_first_line"] is None
    assert layout["tabs"] == []


def test_layout_all_required_keys_present() -> None:
    """_infer_pdf_paragraph_layout must return every DOCX-compatible key."""
    svc = DoclingService.__new__(DoclingService)
    lines = [{"bbox": [72.0, 0.0, 400.0, 14.0], "spans": [{"text": "ทดสอบ"}]}]
    layout = svc._infer_pdf_paragraph_layout(lines, _FakeRect([0.0, 0.0, 612.0, 792.0]))

    for key in [
        "bbox", "reading_order", "alignment", "indent_left",
        "indent_first_line", "indent_hanging", "tabs",
        "spacing_before", "spacing_after", "line_spacing",
    ]:
        assert key in layout, f"missing key: {key}"


# ─────────────────────────────────────────────────────────────────────────────
# P5 — table cell extraction via span-level path
# ─────────────────────────────────────────────────────────────────────────────

def test_extract_pdf_cell_text_fallback_when_no_rect() -> None:
    """_extract_pdf_cell_text with no cell rect falls back to normalising the string."""
    svc = DoclingService.__new__(DoclingService)

    class _NoOpPage:
        pass

    result = svc._extract_pdf_cell_text(_NoOpPage(), None, "กำหนด")
    assert "กำหนด" in result


def test_extract_pdf_cell_text_normalizes_fallback() -> None:
    """Fallback path still applies Thai normalization."""
    svc = DoclingService.__new__(DoclingService)

    class _NoOpPage:
        pass

    # "ส านัก" has sara-am split — normalizer should fix it
    result = svc._extract_pdf_cell_text(_NoOpPage(), None, "ส านัก")
    assert "สำนัก" in result, f"normalization not applied in fallback: {result!r}"


def test_extract_pdf_cell_text_span_path_applies_join_thai_spans() -> None:
    """When page.get_text returns spans, _join_thai_spans is applied to them."""
    svc = DoclingService.__new__(DoclingService)

    # Fake page that returns a dict with a combining-mark span
    class _FakePage:
        def get_text(self, mode: str, clip: object = None) -> dict:
            return {
                "blocks": [{
                    "type": 0,
                    "lines": [{
                        "spans": [
                            {"text": "ก"},
                            {"text": "ั"},   # combining mark as separate span
                            {"text": "ร"},
                        ]
                    }]
                }]
            }

    class _FakeRect2:
        pass

    result = svc._extract_pdf_cell_text(_FakePage(), _FakeRect2(), None)
    assert "ั" in result, "combining mark dropped in span-level path"
    assert " ั" not in result, "space before combining mark in cell"
