"""Tests for DoclingService XML parsing helpers.

Run with: pytest apps/ocr-service/tests/test_docling_service.py -v
"""

import io
import zipfile

from app.services.docling_service import DoclingService


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


# ─────────────────────────────────────────────────────────────────────────────
# _word_attr
# ─────────────────────────────────────────────────────────────────────────────

def test_word_attr_none_node_returns_none() -> None:
    """_word_attr must return None when node is None, not raise AttributeError."""
    assert _svc()._word_attr(None, "val") is None


def test_word_attr_missing_attribute_returns_none() -> None:
    from xml.etree import ElementTree as ET
    elem = ET.fromstring(f'<w:start {NSMAP}/>')
    assert _svc()._word_attr(elem, "val") is None


def test_word_attr_present_attribute_returned() -> None:
    from xml.etree import ElementTree as ET
    elem = ET.fromstring(f'<w:start {NSMAP} w:val="3"/>')
    assert _svc()._word_attr(elem, "val") == "3"


# ─────────────────────────────────────────────────────────────────────────────
# _parse_numbering_xml — optional elements missing
# ─────────────────────────────────────────────────────────────────────────────

def test_parse_numbering_xml_no_file_returns_empty() -> None:
    """Returns empty skeleton when numbering.xml is absent."""
    docx_bytes = _make_docx(
        f'<?xml version="1.0"?><w:document {NSMAP}>'
        "<w:body/></w:document>"
    )
    svc = _svc()
    with zipfile.ZipFile(io.BytesIO(docx_bytes)) as archive:
        result = svc._parse_numbering_xml(archive)
    assert result == {"abstract_nums": {}, "num_map": {}, "counters": {}}


def test_parse_numbering_xml_missing_optional_elements_uses_defaults() -> None:
    """w:start, w:numFmt, w:lvlText are optional; missing ones must use defaults."""
    numbering_xml = (
        f'<?xml version="1.0"?><w:numbering {NSMAP}>'
        '<w:abstractNum w:abstractNumId="0">'
        '<w:lvl w:ilvl="0">'
        # intentionally omit w:start, w:numFmt, w:lvlText
        "</w:lvl>"
        "</w:abstractNum>"
        '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
        "</w:numbering>"
    )
    docx_bytes = _make_docx(
        f'<?xml version="1.0"?><w:document {NSMAP}><w:body/></w:document>',
        numbering_xml,
    )
    svc = _svc()
    with zipfile.ZipFile(io.BytesIO(docx_bytes)) as archive:
        result = svc._parse_numbering_xml(archive)

    assert 0 in result["abstract_nums"]
    level = result["abstract_nums"][0]["levels"][0]
    assert level["start"] == 1
    assert level["numFmt"] == "decimal"
    assert level["lvlText"] == "%1."


def test_parse_numbering_xml_level_override_missing_optional_elements() -> None:
    """w:startOverride, w:numFmt, w:lvlText inside lvlOverride are optional too."""
    numbering_xml = (
        f'<?xml version="1.0"?><w:numbering {NSMAP}>'
        '<w:abstractNum w:abstractNumId="0">'
        '<w:lvl w:ilvl="0">'
        '<w:start w:val="1"/>'
        '<w:numFmt w:val="decimal"/>'
        '<w:lvlText w:val="%1."/>'
        "</w:lvl>"
        "</w:abstractNum>"
        '<w:num w:numId="1">'
        '<w:abstractNumId w:val="0"/>'
        '<w:lvlOverride w:ilvl="0">'
        # intentionally omit w:startOverride, w:numFmt, w:lvlText
        "</w:lvlOverride>"
        "</w:num>"
        "</w:numbering>"
    )
    docx_bytes = _make_docx(
        f'<?xml version="1.0"?><w:document {NSMAP}><w:body/></w:document>',
        numbering_xml,
    )
    svc = _svc()
    with zipfile.ZipFile(io.BytesIO(docx_bytes)) as archive:
        # Must not raise AttributeError
        result = svc._parse_numbering_xml(archive)

    assert 1 in result["num_map"]
    # No override data recorded when all optional override fields are absent
    assert result["num_map"][1]["overrides"] == {}
