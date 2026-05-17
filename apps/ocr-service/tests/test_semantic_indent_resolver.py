"""
Unit tests for the Thai Legal Semantic Indent Rule Engine.

Each test calls resolve_semantic_indents on a crafted page list and checks
the resulting indent_level values and/or trace fields on the blocks.
"""
from __future__ import annotations


# ─────────────────────────────────────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────────────────────────────────────

def _block(
    raw_text: str,
    btype: str = "paragraph",
    marker: dict | None = None,
    alignment: str | None = None,
    indent_level: int = 0,
) -> dict:
    layout: dict = {"indent_level": indent_level}
    if alignment:
        layout["alignment"] = alignment
    meta: dict = {"layout": layout}
    if marker:
        meta["list_marker"] = marker
    return {"type": btype, "raw_text": raw_text, "meta": meta}


def _marker(mtype: str, level: int = 1) -> dict:
    return {"type": mtype, "level": level, "text": "", "raw_match": ""}


def _run(blocks: list[dict]) -> list[int]:
    """Run the resolver and return the resulting indent_levels."""
    from app.services.semantic_indent_resolver import resolve_semantic_indents
    resolve_semantic_indents([{"blocks": blocks}])
    return [b["meta"]["layout"]["indent_level"] for b in blocks]


def _layout(block: dict) -> dict:
    return block["meta"]["layout"]


# ─────────────────────────────────────────────────────────────────────────────
# Marker rules (5–7)
# ─────────────────────────────────────────────────────────────────────────────

class TestMarkerRules:
    def test_kho_gives_indent_1(self):
        blocks = [_block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1))]
        assert _run(blocks) == [1]

    def test_mattra_gives_indent_1(self):
        blocks = [_block("มาตรา ๑", marker=_marker("legal-มาตรา", level=1))]
        assert _run(blocks) == [1]

    def test_kho_deep_level_2(self):
        blocks = [_block("ข้อ ๑.๒", marker=_marker("legal-ข้อ", level=2))]
        assert _run(blocks) == [2]

    def test_paren_gives_indent_1(self):
        blocks = [_block("(๑) text", marker=_marker("paren", level=3))]
        assert _run(blocks) == [1]

    def test_single_gives_indent_1(self):
        blocks = [_block("๑. item", marker=_marker("single", level=1))]
        assert _run(blocks) == [1]

    def test_thai_numeral_gives_indent_1(self):
        blocks = [_block("๑. item", marker=_marker("thai-numeral", level=1))]
        assert _run(blocks) == [1]

    def test_arabic_gives_indent_1(self):
        blocks = [_block("1. item", marker=_marker("arabic", level=1))]
        assert _run(blocks) == [1]

    def test_bullet_gives_indent_1(self):
        blocks = [_block("• item", marker=_marker("bullet", level=1))]
        assert _run(blocks) == [1]

    def test_legal_warrak_gives_indent_1(self):
        blocks = [_block("วรรค ๑", marker=_marker("legal-วรรค", level=2))]
        assert _run(blocks) == [1]

    def test_multi_level_2(self):
        blocks = [_block("๑.๑", marker=_marker("multi", level=2))]
        assert _run(blocks) == [2]

    def test_multi_level_3(self):
        blocks = [_block("๑.๑.๑", marker=_marker("multi", level=3))]
        assert _run(blocks) == [3]

    def test_indent_capped_at_4(self):
        blocks = [_block("๑.๑.๑.๑.๑", marker=_marker("multi", level=10))]
        assert _run(blocks) == [4]

    def test_kho_capped_at_4(self):
        blocks = [_block("ข้อ ๑.๒.๓.๔.๕", marker=_marker("legal-ข้อ", level=8))]
        assert _run(blocks) == [4]


# ─────────────────────────────────────────────────────────────────────────────
# Continuation rule (8)
# ─────────────────────────────────────────────────────────────────────────────

class TestContinuationRule:
    def test_kho_then_text(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("body text"),
        ]
        assert _run(blocks) == [1, 2]

    def test_kho_then_paren(self):
        """(๑) after ข้อ ๑ stays at 1 — marker rule (6) wins over continuation."""
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("(๑) sub-item", marker=_marker("paren", level=1)),
        ]
        assert _run(blocks) == [1, 1]

    def test_paren_then_text(self):
        blocks = [
            _block("(๑) item", marker=_marker("paren", level=1)),
            _block("continuation text"),
        ]
        assert _run(blocks) == [1, 2]

    def test_multi_level_then_text(self):
        blocks = [
            _block("๑.", marker=_marker("single", level=1)),
            _block("๑.๑", marker=_marker("multi", level=2)),
            _block("body text"),
        ]
        assert _run(blocks) == [1, 2, 3]

    def test_continuation_capped_at_4(self):
        """Continuation after level-4 marker stays at 4, not 5."""
        blocks = [
            _block("๑.๑.๑.๑", marker=_marker("multi", level=4)),
            _block("body text"),
        ]
        assert _run(blocks) == [4, 4]

    def test_multiple_continuation_lines(self):
        """All text lines after a marker share the same continuation level."""
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("line 1"),
            _block("line 2"),
            _block("line 3"),
        ]
        assert _run(blocks) == [1, 2, 2, 2]

    def test_new_marker_resets_continuation(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("body text"),
            _block("ข้อ ๒", marker=_marker("legal-ข้อ", level=1)),
            _block("body text 2"),
        ]
        assert _run(blocks) == [1, 2, 1, 2]


# ─────────────────────────────────────────────────────────────────────────────
# Context reset rules (1–2)
# ─────────────────────────────────────────────────────────────────────────────

class TestContextReset:
    def test_table_resets_parent(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("table content", btype="table"),
            _block("plain text after table"),
        ]
        levels = _run(blocks)
        assert levels[0] == 1
        assert levels[2] == 0  # no continuation after table

    def test_center_resets_parent(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("TITLE", alignment="center", indent_level=2),
            _block("plain text after center"),
        ]
        levels = _run(blocks)
        assert levels[0] == 1
        assert levels[1] == 2  # center block's geometry unchanged
        assert levels[2] == 0  # no continuation after center

    def test_right_resets_parent(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("Page 1", alignment="right", indent_level=0),
            _block("plain text"),
        ]
        levels = _run(blocks)
        assert levels[0] == 1
        assert levels[2] == 0


# ─────────────────────────────────────────────────────────────────────────────
# Divider and โดย rules (3–4)
# ─────────────────────────────────────────────────────────────────────────────

class TestDividerAndBy:
    def test_divider_then_by(self):
        blocks = [
            _block("-----"),
            _block("โดยที่ประชุม..."),
        ]
        _run(blocks)
        by_layout = _layout(blocks[1])
        assert by_layout["indent_level"] == 0
        assert by_layout.get("indent_first_line") == 720
        assert by_layout.get("indent_source") == "semantic"
        assert by_layout.get("indent_reason") == "by_after_divider"

    def test_divider_then_non_by_no_continuation(self):
        blocks = [
            _block("─────"),
            _block("other text"),
        ]
        levels = _run(blocks)
        # Divider itself is skipped (has no indent_level key set or it stays 0)
        assert levels[1] == 0  # no parent → no continuation

    def test_divider_resets_parent(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("-----"),
            _block("plain text"),
        ]
        levels = _run(blocks)
        assert levels[0] == 1
        assert levels[2] == 0  # parent was reset by divider

    def test_short_dash_not_divider(self):
        """Less than 5 dashes must NOT trigger the divider rule."""
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("----"),   # only 4 dashes
            _block("plain text"),
        ]
        levels = _run(blocks)
        assert levels[2] == 2  # continuation still active — short dash not a divider


# ─────────────────────────────────────────────────────────────────────────────
# Geometry fallback (9)
# ─────────────────────────────────────────────────────────────────────────────

class TestGeometryFallback:
    def test_geometry_level_preserved_when_low(self):
        blocks = [_block("text", indent_level=2)]
        assert _run(blocks) == [2]

    def test_geometry_fallback_capped_at_4(self):
        blocks = [_block("text", indent_level=7)]
        assert _run(blocks) == [4]

    def test_zero_geometry_stays_zero(self):
        blocks = [_block("text", indent_level=0)]
        assert _run(blocks) == [0]


# ─────────────────────────────────────────────────────────────────────────────
# Trace field and safety checks
# ─────────────────────────────────────────────────────────────────────────────

class TestTraceAndSafety:
    def test_indent_source_set_on_marker(self):
        blocks = [_block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1))]
        _run(blocks)
        assert _layout(blocks[0]).get("indent_source") == "semantic"

    def test_indent_reason_includes_type(self):
        blocks = [_block("(๑)", marker=_marker("paren", level=1))]
        _run(blocks)
        assert _layout(blocks[0]).get("indent_reason") == "marker:paren"

    def test_indent_source_on_continuation(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("body text"),
        ]
        _run(blocks)
        assert _layout(blocks[1]).get("indent_source") == "semantic"
        assert _layout(blocks[1]).get("indent_reason") == "continuation"

    def test_raw_text_unchanged(self):
        blocks = [
            _block("ข้อ ๑ some body text", marker=_marker("legal-ข้อ", level=1)),
            _block("continuation body"),
        ]
        _run(blocks)
        assert blocks[0]["raw_text"] == "ข้อ ๑ some body text"
        assert blocks[1]["raw_text"] == "continuation body"

    def test_empty_pages_no_error(self):
        from app.services.semantic_indent_resolver import resolve_semantic_indents
        resolve_semantic_indents([])   # must not raise
        resolve_semantic_indents([{"blocks": []}])   # must not raise

    def test_block_without_meta_layout_no_error(self):
        """Blocks with missing meta/layout must not crash the resolver."""
        from app.services.semantic_indent_resolver import resolve_semantic_indents
        blocks = [
            {"type": "paragraph", "raw_text": "text", "meta": {}},
            {"type": "paragraph", "raw_text": "text2"},
        ]
        resolve_semantic_indents([{"blocks": blocks}])   # must not raise


# ─────────────────────────────────────────────────────────────────────────────
# Continuation override (rule 2.5) — quotation / Thai connector
# ─────────────────────────────────────────────────────────────────────────────

class TestContinuationOverride:
    def test_centered_quote_after_clause_keeps_continuation(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("สำนักงาน ก. มีหน้าที่ดังต่อไปนี้"),
            _block('"ตามที่กำหนดในกฎกระทรวง"', alignment="center"),
        ]
        assert _run(blocks) == [1, 2, 2]

    def test_thai_connector_after_clause_keeps_continuation(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("เนื้อหา"),
            _block("ซึ่งหมายความว่า ...", alignment="center"),
        ]
        assert _run(blocks) == [1, 2, 2]

    def test_centered_title_without_continuation_still_resets(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("ประกาศกระทรวง", alignment="center"),
            _block("เนื้อหาใหม่"),
        ]
        levels = _run(blocks)
        assert levels[0] == 1
        assert levels[2] == 0

    def test_indent_reason_set_for_override(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block('"คำพูด"', alignment="center"),
        ]
        _run(blocks)
        assert _layout(blocks[1]).get("indent_reason") == "continuation_override"

    def test_thai_curly_quote_opener(self):
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("“ข้อความ”", alignment="center"),
        ]
        assert _run(blocks) == [1, 2]


# ─────────────────────────────────────────────────────────────────────────────
# Parent-child verification (rule 10)
# ─────────────────────────────────────────────────────────────────────────────

class TestParentChildVerification:
    def test_continuation_above_parent_rebound(self):
        """Verification is narrowed to continuation blocks: a continuation
        already matches parent+1 by construction, so verification is a
        no-op in the normal path but provides a safety net for future rules."""
        blocks = [
            _block("ข้อ ๑", marker=_marker("legal-ข้อ", level=1)),
            _block("stray", indent_level=4),
        ]
        # Rule 8 (continuation) sets stray to parent+1 = 2; verification leaves it.
        assert _run(blocks) == [1, 2]

    def test_geometry_block_not_demoted_by_verification(self):
        """Verification must NOT touch geometry-fallback blocks — they have
        no parent contract."""
        blocks = [_block("text", indent_level=3)]
        assert _run(blocks) == [3]

    def test_marker_block_not_demoted_by_verification(self):
        """Markers know their own depth — verification must not demote them."""
        blocks = [_block("ข้อ ๑.๒", marker=_marker("legal-ข้อ", level=2))]
        assert _run(blocks) == [2]
