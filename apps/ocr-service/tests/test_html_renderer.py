"""Tests for html_renderer — the single canonical table renderer."""
from app.services.html_renderer import build_table_html


def _cell(text: str = "", colspan: int = 1, rowspan: int = 1, alignment: str | None = None, has_image: bool = False) -> dict:
    return {"text": text, "colspan": colspan, "rowspan": rowspan, "alignment": alignment, "has_image": has_image}


def test_basic_header_row():
    html = build_table_html([[_cell("Name")]])
    assert "<th>Name</th>" in html
    assert 'class="doc-table"' in html


def test_colspan_emitted_when_greater_than_one():
    html = build_table_html([[_cell("H", colspan=2)], [_cell("A"), _cell("B")]])
    assert 'colspan="2"' in html
    assert 'colspan="1"' not in html


def test_colspan_one_not_emitted():
    html = build_table_html([[_cell("X")]])
    assert "colspan" not in html


def test_rowspan_emitted_when_greater_than_one():
    html = build_table_html([[_cell("A", rowspan=2)], [_cell("B")]])
    assert 'rowspan="2"' in html


def test_has_image_cell_renders_image_placeholder():
    html = build_table_html([[_cell(has_image=True)]])
    assert "doc-cell-image" in html
    assert "[image]" in html


def test_alignment_emits_data_cell_align_and_style():
    html = build_table_html([[_cell("centered", alignment="center")]])
    assert 'data-cell-align="center"' in html
    assert 'style="text-align:center;"' in html


def test_html_special_chars_escaped():
    html = build_table_html([[_cell("<b>bold</b>")]])
    assert "&lt;b&gt;" in html
    assert "<b>" not in html
