"""Tests for DoclingService XML parsing helpers.

Run with: pytest apps/ocr-service/tests/test_docling_service.py -v
"""

import io
import zipfile
from pathlib import Path

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
        result = svc._parse_numbering_xml(archive, frozenset(archive.namelist()))
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
        result = svc._parse_numbering_xml(archive, frozenset(archive.namelist()))

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
        result = svc._parse_numbering_xml(archive, frozenset(archive.namelist()))

    assert 1 in result["num_map"]
    # No override data recorded when all optional override fields are absent
    assert result["num_map"][1]["overrides"] == {}


# ─────────────────────────────────────────────────────────────────────────────
# namelist() must be cached per-document (Task 1: DOCX speedup)
# ─────────────────────────────────────────────────────────────────────────────

def _make_docx_with_image(tmp_path: Path) -> Path:
    """Build an in-memory DOCX with several paragraphs and one embedded image."""
    REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    R_NS = REL_NS

    document_xml = (
        f'<?xml version="1.0"?><w:document {NSMAP} xmlns:r="{R_NS}">'
        "<w:body>"
        # 3 plain paragraphs
        "<w:p><w:r><w:t>first</w:t></w:r></w:p>"
        "<w:p><w:r><w:t>second</w:t></w:r></w:p>"
        "<w:p><w:r><w:t>third</w:t></w:r></w:p>"
        # 1 paragraph with an embedded image reference (rId1)
        "<w:p><w:r><w:drawing>"
        f'<a:blip xmlns:a="x" r:embed="rId1"/>'
        "</w:drawing></w:r></w:p>"
        # 2 more plain paragraphs
        "<w:p><w:r><w:t>fifth</w:t></w:r></w:p>"
        "<w:p><w:r><w:t>sixth</w:t></w:r></w:p>"
        "</w:body></w:document>"
    )

    rels_xml = (
        '<?xml version="1.0"?>'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" '
        'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
        'Target="media/image1.png"/>'
        "</Relationships>"
    )

    # Minimal valid 1x1 PNG
    png_bytes = (
        b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01"
        b"\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xcf"
        b"\xc0\x00\x00\x00\x03\x00\x01\x9a\xc0\x9a&\x00\x00\x00\x00IEND\xaeB`\x82"
    )

    out = tmp_path / "with_image.docx"
    with zipfile.ZipFile(out, "w") as zf:
        zf.writestr("word/document.xml", document_xml)
        zf.writestr("word/_rels/document.xml.rels", rels_xml)
        zf.writestr("word/media/image1.png", png_bytes)
        zf.writestr(
            "[Content_Types].xml",
            '<?xml version="1.0"?>'
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        )
    return out


def test_extract_docx_blocks_caches_namelist(tmp_path: Path, monkeypatch) -> None:
    """_extract_docx_blocks must call archive.namelist() at most once per document.

    Regression guard: _parse_docx_image previously called namelist() on every
    paragraph, causing O(N*M) zip directory scans for documents with many
    paragraphs. The fix caches the name set once and threads it down.
    """
    docx_path = _make_docx_with_image(tmp_path)

    call_count = {"n": 0}
    real_namelist = zipfile.ZipFile.namelist

    def counting_namelist(self):
        call_count["n"] += 1
        return real_namelist(self)

    monkeypatch.setattr(zipfile.ZipFile, "namelist", counting_namelist)

    svc = _svc()
    # _save_image needs a writable image dir; redirect to tmp_path.
    svc._data_root = tmp_path

    blocks = svc._extract_docx_blocks(docx_path, document_id="doc-namelist-test")

    # Sanity check: we produced something (6 paragraphs → 6 blocks, one is image)
    assert len(blocks) == 6
    assert any(b["type"] == "image" for b in blocks)

    # The whole point: namelist() must be called at most once (the cache build).
    assert call_count["n"] <= 1, (
        f"archive.namelist() was called {call_count['n']} times; expected ≤ 1. "
        "_extract_docx_blocks must cache the name set instead of calling "
        "namelist() per paragraph."
    )
