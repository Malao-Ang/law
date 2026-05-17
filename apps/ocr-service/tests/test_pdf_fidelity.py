"""
Unit tests for Phase D: PDF rendering fidelity.

Covers bold detection from font flags/name, alignment detection from line
x-positions, and spacing_before from inter-block vertical gap.
"""

from __future__ import annotations


# ─────────────────────────────────────────────────────────────────────────────
# Helpers to build fake PyMuPDF-style dicts
# ─────────────────────────────────────────────────────────────────────────────

def _span(text: str, flags: int = 0, font: str = "Sarabun", x0: float = 72.0, x1: float | None = None) -> dict:
    if x1 is None:
        x1 = x0 + len(text) * 6.0
    return {
        "text": text,
        "flags": flags,
        "font": font,
        "origin": [x0, 100.0],
        "bbox": [x0, 90.0, x1, 106.0],
    }


def _line(spans: list[dict], x0: float = 72.0, x1: float = 450.0, y0: float = 90.0, y1: float = 106.0) -> dict:
    return {"spans": spans, "bbox": [x0, y0, x1, y1]}


def _block_lines(lines: list[dict]) -> list[dict]:
    return lines


# ─────────────────────────────────────────────────────────────────────────────
# Bold detection
# ─────────────────────────────────────────────────────────────────────────────

class TestBoldDetection:
    def _is_bold(self, block_lines):
        from app.services.docling_service import _block_is_bold
        return _block_is_bold(block_lines)

    def test_bold_from_flags_bit4(self):
        lines = [_line([_span("Bold heading", flags=16)])]
        assert self._is_bold(lines) is True

    def test_bold_from_font_name(self):
        lines = [_line([_span("ชื่อเรื่อง", flags=0, font="THSarabunNewBold")])]
        assert self._is_bold(lines) is True

    def test_bold_from_font_name_bd_suffix(self):
        lines = [_line([_span("heading", flags=0, font="ArialBd")])]
        assert self._is_bold(lines) is True

    def test_not_bold_when_normal_font(self):
        lines = [_line([_span("body text", flags=0, font="Sarabun")])]
        assert self._is_bold(lines) is False

    def test_not_bold_when_less_than_half_bold(self):
        # 3 normal chars, 1 bold char → 25% bold → not bold
        lines = [_line([
            _span("abc", flags=0, font="Sarabun"),
            _span("B", flags=16, font="Sarabun"),
        ])]
        assert self._is_bold(lines) is False

    def test_bold_when_exactly_half(self):
        # 2 bold chars, 2 normal chars → 50% → bold
        lines = [_line([
            _span("ab", flags=0, font="Sarabun"),
            _span("cd", flags=16, font="Sarabun"),
        ])]
        assert self._is_bold(lines) is True


# ─────────────────────────────────────────────────────────────────────────────
# Alignment detection
# ─────────────────────────────────────────────────────────────────────────────

class _FakeRect:
    """Minimal stand-in for fitz.Rect."""
    def __init__(self, x0=0, y0=0, x1=595, y1=842):
        self._data = [x0, y0, x1, y1]

    def __getitem__(self, i):
        return self._data[i]


class TestAlignmentDetection:
    def _layout(self, lines, page_rect=None):
        from app.services.docling_service import DoclingService
        if page_rect is None:
            page_rect = _FakeRect(0, 0, 595, 842)
        return DoclingService._infer_pdf_paragraph_layout(lines, page_rect)

    def test_center_alignment(self):
        # Two symmetric lines centred on 595pt page
        # Left offset ≈ 200pt, right offset ≈ 200pt, line width ≈ 195pt
        lines = [
            _line([], x0=200.0, x1=395.0),
            _line([], x0=205.0, x1=390.0),
        ]
        # Add a dummy span so the line is not skipped
        for ln in lines:
            ln["spans"] = [_span("ข้อความ", x0=ln["bbox"][0], x1=ln["bbox"][2])]
        result = self._layout(lines)
        assert result["alignment"] == "center", f"Expected center, got {result['alignment']}"

    def test_left_alignment_returns_none(self):
        # Lines flush left, varying right edges
        lines = [
            _line([_span("short line", x0=72.0, x1=200.0)], x0=72.0, x1=200.0),
            _line([_span("a much longer line here", x0=72.0, x1=450.0)], x0=72.0, x1=450.0),
        ]
        result = self._layout(lines)
        assert result["alignment"] is None

    def test_no_spans_returns_none_alignment(self):
        result = self._layout([])
        assert result["alignment"] is None


# ─────────────────────────────────────────────────────────────────────────────
# Spacing before
# ─────────────────────────────────────────────────────────────────────────────

class TestSpacingBefore:
    def test_large_gap_sets_spacing_before(self):
        """A gap > 1.5× median line height must produce spacing_before."""
        from app.services.docling_service import DoclingService

        # Build a fake page object with two text blocks separated by 30pt
        # median line height = 12pt  → threshold = 18pt  → 30pt > threshold
        class FakePage:
            rect = _FakeRect(0, 0, 595, 842)

            def get_text(self, _fmt):
                return {
                    "blocks": [
                        {
                            "type": 0,
                            "bbox": [72, 100, 450, 112],  # y0=100, y1=112  (h=12)
                            "lines": [
                                {"bbox": [72, 100, 450, 112], "spans": [
                                    {"text": "first block", "flags": 0, "font": "Sarabun",
                                     "origin": [72, 108], "bbox": [72, 100, 450, 112]}
                                ]}
                            ],
                        },
                        {
                            "type": 0,
                            "bbox": [72, 142, 450, 154],  # gap = 142-112 = 30pt
                            "lines": [
                                {"bbox": [72, 142, 450, 154], "spans": [
                                    {"text": "second block", "flags": 0, "font": "Sarabun",
                                     "origin": [72, 150], "bbox": [72, 142, 450, 154]}
                                ]}
                            ],
                        },
                    ]
                }

            def find_tables(self, **_kw):
                return []

        svc = DoclingService.__new__(DoclingService)
        page = FakePage()
        blocks = svc._extract_pdf_page_blocks(page, page_index=1, document_id="test")

        assert len(blocks) == 2
        second = blocks[1]
        sp = second["meta"]["layout"].get("spacing_before")
        assert sp is not None and sp > 0, f"Expected spacing_before > 0, got {sp}"
        # 30pt × 20 = 600 twips
        assert sp == 600, f"Expected 600 twips, got {sp}"

    def test_small_gap_no_spacing_before(self):
        """Normal single-line gap must NOT set spacing_before."""
        from app.services.docling_service import DoclingService

        class FakePage:
            rect = _FakeRect(0, 0, 595, 842)

            def get_text(self, _fmt):
                return {
                    "blocks": [
                        {
                            "type": 0,
                            "bbox": [72, 100, 450, 112],
                            "lines": [
                                {"bbox": [72, 100, 450, 112], "spans": [
                                    {"text": "first", "flags": 0, "font": "Sarabun",
                                     "origin": [72, 108], "bbox": [72, 100, 450, 112]}
                                ]}
                            ],
                        },
                        {
                            "type": 0,
                            "bbox": [72, 114, 450, 126],  # gap = 2pt (normal)
                            "lines": [
                                {"bbox": [72, 114, 450, 126], "spans": [
                                    {"text": "second", "flags": 0, "font": "Sarabun",
                                     "origin": [72, 122], "bbox": [72, 114, 450, 126]}
                                ]}
                            ],
                        },
                    ]
                }

            def find_tables(self, **_kw):
                return []

        svc = DoclingService.__new__(DoclingService)
        blocks = svc._extract_pdf_page_blocks(FakePage(), page_index=1, document_id="test")
        assert len(blocks) == 2
        assert blocks[1]["meta"]["layout"].get("spacing_before") is None
