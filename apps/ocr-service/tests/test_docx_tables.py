"""Tests for DOCX table extraction helpers.

Run with: pytest apps/ocr-service/tests/test_docx_tables.py -v
"""

import io
import zipfile

from app.services.block_builder import build_reviewed_html, build_table_payload
from app.services.docling_service import DoclingService
from app.services.html_renderer import build_table_html


# ─────────────────────────────────────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────────────────────────────────────

W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
NSMAP = f'xmlns:w="{W_NS}"'


def _make_docx(document_xml: str, numbering_xml: str | None = None) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr("word/document.xml", document_xml)
        if numbering_xml is not None:
            zf.writestr("word/numbering.xml", numbering_xml)
        zf.writestr(
            "[Content_Types].xml",
            '<?xml version="1.0"?>'
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        )
    buf.seek(0)
    return buf.read()


def _svc() -> DoclingService:
    return DoclingService.__new__(DoclingService)


def _make_block(table_dict: dict) -> dict:
    """Wrap a table dict in a minimal block structure for build_table_payload."""
    return {"meta": {"table": table_dict}}


# ─────────────────────────────────────────────────────────────────────────────
# Patch 1 — data-block-id must survive in reviewed_html for table blocks
# ─────────────────────────────────────────────────────────────────────────────

def test_table_reviewed_html_has_data_block_id() -> None:
    """build_reviewed_html for tables must carry data-block-id through to the output."""
    table = {"html": "<table><tbody><tr><td>A</td></tr></tbody></table>"}
    html = build_reviewed_html("table", "", {}, "block-42", table=table)
    assert 'data-block-id="block-42"' in html
    assert html.startswith("<table")


def test_table_reviewed_html_preserves_content() -> None:
    """data-block-id injection must not drop the table body."""
    table = {"html": "<table><tbody><tr><td>Hello</td></tr></tbody></table>"}
    html = build_reviewed_html("table", "", {}, "b1", table=table)
    assert "Hello" in html


# ─────────────────────────────────────────────────────────────────────────────
# Patch 2 — build_table_payload must prefer existing Python-built HTML
# ─────────────────────────────────────────────────────────────────────────────

def test_build_table_payload_prefers_existing_html() -> None:
    """When source_table already has an 'html' key, build_table_payload must use it."""
    pre_built_html = '<table data-cell-align="left"><tbody><tr><td>X</td></tr></tbody></table>'
    block = _make_block(
        {
            "html": pre_built_html,
            "cells": [[{"text": "X", "colspan": 1, "rowspan": 1, "alignment": "left"}]],
            "headers": ["X"],
            "rows": [],
        }
    )
    result = build_table_payload(block, "X", "table")
    assert result is not None
    assert result["html"] == pre_built_html


def test_build_table_payload_falls_back_when_no_html() -> None:
    """When source_table has no 'html' key, build_table_payload rebuilds from cells."""
    block = _make_block(
        {
            "cells": [[{"text": "Y", "colspan": 1, "rowspan": 1, "alignment": None}]],
            "headers": ["Y"],
            "rows": [],
        }
    )
    result = build_table_payload(block, "Y", "table")
    assert result is not None
    assert "<table" in result["html"]
    assert "Y" in result["html"]


# ─────────────────────────────────────────────────────────────────────────────
# Patch 3 — ThaiNormalizer applied to cell text
# ─────────────────────────────────────────────────────────────────────────────

def test_cell_text_verbatim_raw_text() -> None:
    """Cell text stored in raw_text must be verbatim (normalization deferred to block_builder)."""
    # ZWSP (U+200B) in source text must be preserved here; block_builder removes it later.
    zwsp = "​"
    cell_xml = (
        f'<w:tc {NSMAP}>'
        f'<w:p><w:r><w:t>ก{zwsp}ข</w:t></w:r></w:p>'
        f'</w:tc>'
    )
    from xml.etree import ElementTree as ET
    cell_elem = ET.fromstring(cell_xml)
    svc = _svc()
    result = svc._parse_table_cell(cell_elem)
    # raw_text is verbatim -- ZWSP is present; block_builder normalize pass strips it later
    assert zwsp in result["text"], "ZWSP should be preserved in raw cell text"
    assert "ก" in result["text"]
    assert "ข" in result["text"]
def test_multi_paragraph_cell_reading_order() -> None:
    """Two paragraphs in a cell must appear in document order, joined by newline."""
    cell_xml = (
        f'<w:tc {NSMAP}>'
        f'<w:p><w:r><w:t>First</w:t></w:r></w:p>'
        f'<w:p><w:r><w:t>Second</w:t></w:r></w:p>'
        f'</w:tc>'
    )
    from xml.etree import ElementTree as ET
    cell_elem = ET.fromstring(cell_xml)
    svc = _svc()
    result = svc._parse_table_cell(cell_elem)
    assert result["text"] == "First\nSecond"


# ─────────────────────────────────────────────────────────────────────────────
# Colspan / rowspan in rendered HTML
# ─────────────────────────────────────────────────────────────────────────────

def test_colspan_in_html() -> None:
    """Merged cells with colspan > 1 must emit colspan attribute in rendered HTML."""
    rows = [
        [
            {"text": "Merged", "colspan": 2, "rowspan": 1, "alignment": None, "has_image": False},
            {"text": "Normal", "colspan": 1, "rowspan": 1, "alignment": None, "has_image": False},
        ]
    ]
    html = build_table_html(rows)
    assert 'colspan="2"' in html
    assert 'colspan="1"' not in html  # colspan=1 is the default, must not be emitted


def test_rowspan_in_html() -> None:
    """Merged cells with rowspan > 1 must emit rowspan attribute in rendered HTML."""
    rows = [
        [{"text": "Spans two", "colspan": 1, "rowspan": 2, "alignment": None, "has_image": False}],
        [{"text": "Other", "colspan": 1, "rowspan": 1, "alignment": None, "has_image": False}],
    ]
    html = build_table_html(rows)
    assert 'rowspan="2"' in html


# ─────────────────────────────────────────────────────────────────────────────
# Patch 5 — has_image flag and placeholder rendering
# ─────────────────────────────────────────────────────────────────────────────

def test_cell_with_drawing_sets_has_image_flag() -> None:
    """A cell containing w:drawing with r:embed must set has_image=True."""
    REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    cell_xml = (
        f'<w:tc {NSMAP} xmlns:r="{REL_NS}">'
        f'<w:p><w:r>'
        f'<w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
        f'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        f'<a:graphicData>'
        f'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        f'<pic:blipFill><a:blip r:embed="rId1"/></pic:blipFill>'
        f'</pic:pic></a:graphicData></a:graphic>'
        f'</wp:inline></w:drawing>'
        f'</w:r></w:p>'
        f'</w:tc>'
    )
    from xml.etree import ElementTree as ET
    cell_elem = ET.fromstring(cell_xml)
    svc = _svc()
    result = svc._parse_table_cell(cell_elem)
    assert result["has_image"] is True
    assert result["text"] == ""


def test_cell_with_image_renders_placeholder() -> None:
    """A cell with has_image=True and empty text must render [image] placeholder."""
    rows = [
        [{"text": "", "colspan": 1, "rowspan": 1, "alignment": None, "has_image": True}]
    ]
    html = build_table_html(rows)
    assert "doc-cell-image" in html
    assert "[image]" in html


def test_cell_without_drawing_has_image_false() -> None:
    """A plain text cell must set has_image=False."""
    cell_xml = (
        f'<w:tc {NSMAP}>'
        f'<w:p><w:r><w:t>plain text</w:t></w:r></w:p>'
        f'</w:tc>'
    )
    from xml.etree import ElementTree as ET
    cell_elem = ET.fromstring(cell_xml)
    svc = _svc()
    result = svc._parse_table_cell(cell_elem)
    assert result["has_image"] is False
