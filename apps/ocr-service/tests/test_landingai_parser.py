from __future__ import annotations

from pathlib import Path
from unittest.mock import MagicMock, patch

import fitz
import httpx

from app.services.landingai_parser import LandingAiAdeParser


def _make_pdf(tmp_path: Path, page_count: int = 2) -> Path:
    pdf_path = tmp_path / "sample.pdf"
    doc = fitz.open()
    for _ in range(page_count):
        doc.new_page()
    doc.save(pdf_path)
    doc.close()
    return pdf_path


def test_call_ade_parse_accepts_206_and_uses_document_form(tmp_path: Path) -> None:
    pdf_path = _make_pdf(tmp_path, page_count=1)
    parser = LandingAiAdeParser(data_root=tmp_path / "data")

    response = MagicMock()
    response.status_code = 206
    response.json.return_value = {"markdown": "", "chunks": [], "splits": [], "metadata": {"job_id": "job-1"}}
    response.raise_for_status = MagicMock()

    client = MagicMock()
    client.post.return_value = response

    cm = MagicMock()
    cm.__enter__.return_value = client
    cm.__exit__.return_value = False

    with patch("app.services.landingai_parser.httpx.Client", return_value=cm):
        payload, status = parser._call_ade_parse(pdf_path)

    assert status == 206
    assert payload["metadata"]["job_id"] == "job-1"
    client.post.assert_called_once()
    kwargs = client.post.call_args.kwargs
    assert kwargs["data"]["split"] == "page"
    assert kwargs["files"]["document"][0] == pdf_path.name
    assert "Authorization" in kwargs["headers"]


def test_parse_pdf_uses_split_fallback_and_zero_based_pages(tmp_path: Path) -> None:
    pdf_path = _make_pdf(tmp_path, page_count=2)
    parser = LandingAiAdeParser(data_root=tmp_path / "data")

    payload = {
        "markdown": "ignored",
        "chunks": [
            {"id": "c1", "type": "heading", "markdown": "# First page", "confidence": 0.91},
            {"id": "c2", "type": "table", "markdown": "A | B", "confidence": 0.88},
        ],
        "splits": [
            {"class": "page", "identifier": "page-1", "pages": [0], "markdown": "# First page", "chunks": ["c1"]},
            {"class": "page", "identifier": "page-2", "pages": [1], "markdown": "A | B", "chunks": ["c2"]},
        ],
        "metadata": {
            "filename": "sample.pdf",
            "page_count": 2,
            "duration_ms": 450,
            "job_id": "job-123",
            "failed_pages": [1],
        },
        "grounding": {},
    }

    with patch.object(parser, "_call_ade_parse", return_value=(payload, 206)):
        pages = parser.parse_pdf(pdf_path, "doc-1")

    assert parser.last_metadata is not None
    assert parser.last_metadata["status_code"] == 206
    assert parser.last_metadata["job_id"] == "job-123"
    assert parser.last_metadata["failed_pages"] == [1]

    assert [page["page_no"] for page in pages] == [1, 2]
    assert pages[0]["blocks"][0]["type"] == "title"
    assert pages[0]["blocks"][0]["meta"]["layout"]["alignment"] == "center"
    assert pages[0]["blocks"][0]["meta"]["landingai"]["response_status"] == 206
    assert pages[0]["blocks"][0]["meta"]["landingai"]["page_no"] == 1
    assert pages[1]["blocks"][0]["type"] == "table"
    assert pages[1]["blocks"][0]["meta"]["layout"]["alignment"] == "center"
    assert pages[1]["blocks"][0]["meta"]["landingai"]["chunk_id"] == "c2"


def test_landingai_timeout_in_auto_mode_falls_back_to_ocr(tmp_path):
    """auto mode: LandingAI timeout -> EasyOCR fallback, no exception raised."""
    from app.api.routes import _extract_scan_pages

    with patch("app.api.routes.LandingAiAdeParser") as mock_parser_cls, \
         patch("app.api.routes.get_ocr_pipeline") as mock_pipeline_fn, \
         patch("app.api.routes._scan_quality_good", return_value=False), \
         patch("app.api.routes.get_settings") as mock_settings:

        mock_settings.return_value.landingai_api_key = "test-key"

        mock_parser = MagicMock()
        mock_parser.parse_pdf.side_effect = httpx.TimeoutException("timeout")
        mock_parser_cls.return_value = mock_parser

        mock_pipeline = MagicMock()
        mock_pipeline.extract_scanned_pdf.return_value = [{"page_no": 1, "blocks": [{"type": "paragraph", "raw_text": "ocr fallback", "confidence": 0.95, "flags": []}]}]
        mock_pipeline_fn.return_value = mock_pipeline

        pages, mode, landingai_meta, gemini_meta = _extract_scan_pages(
            file_path=tmp_path / "fake.pdf",
            document_id="test-doc",
            requested_mode="auto",
            data_root=tmp_path,
        )

    assert mode == "local"
    assert pages[0]["blocks"][0]["raw_text"] == "ocr fallback"
    assert landingai_meta is None
    assert gemini_meta is None


def test_landingai_http_error_in_landingai_mode_falls_back_to_ocr(tmp_path):
    """landingai mode: HTTP 500 -> EasyOCR fallback, no exception raised."""
    from app.api.routes import _extract_scan_pages

    with patch("app.api.routes.LandingAiAdeParser") as mock_parser_cls, \
         patch("app.api.routes.get_ocr_pipeline") as mock_pipeline_fn, \
         patch("app.api.routes.get_settings") as mock_settings:

        mock_settings.return_value.landingai_api_key = "test-key"

        mock_parser = MagicMock()
        mock_response = MagicMock(status_code=500, text="Internal Server Error")
        mock_parser.parse_pdf.side_effect = httpx.HTTPStatusError(
            "500", request=MagicMock(), response=mock_response
        )
        mock_parser_cls.return_value = mock_parser

        mock_pipeline = MagicMock()
        mock_pipeline.extract_scanned_pdf.return_value = [{"page_no": 1, "blocks": [{"type": "paragraph", "raw_text": "ocr fallback", "confidence": 0.95, "flags": []}]}]
        mock_pipeline_fn.return_value = mock_pipeline

        pages, mode, landingai_meta, gemini_meta = _extract_scan_pages(
            file_path=tmp_path / "fake.pdf",
            document_id="test-doc",
            requested_mode="landingai",
            data_root=tmp_path,
        )

    assert mode == "local"
    assert pages[0]["blocks"][0]["raw_text"] == "ocr fallback"
    assert landingai_meta is None
    assert gemini_meta is None
