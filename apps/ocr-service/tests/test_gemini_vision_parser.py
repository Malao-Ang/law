from __future__ import annotations

import json
from pathlib import Path
from unittest.mock import MagicMock, patch

import fitz

from app.services.gemini_vision_parser import GeminiVisionParser


def _make_pdf(tmp_path: Path, page_count: int = 1) -> Path:
    pdf_path = tmp_path / "sample.pdf"
    doc = fitz.open()
    for _ in range(page_count):
        doc.new_page()
    doc.save(pdf_path)
    doc.close()
    return pdf_path


def test_parse_blocks_json_splits_thai_legal_blocks() -> None:
    parser = GeminiVisionParser(data_root=Path("/tmp"))
    payload = {
        "blocks": [
            {"type": "title", "text": "พระราชบัญญัติ", "confidence": 0.96, "alignment": "center"},
            {"type": "paragraph", "text": "มาตรา ๑ ให้ใช้บทบัญญัติ", "confidence": 0.93, "alignment": "left"},
        ]
    }
    blocks = parser._parse_blocks_json(json.dumps(payload), page_no=1)

    assert len(blocks) == 2
    assert blocks[0]["type"] == "title"
    assert blocks[0]["meta"]["layout"]["alignment"] == "center"
    assert blocks[0]["flags"] == ["gemini_ocr"]
    assert blocks[1]["raw_text"].startswith("มาตรา ๑")


def test_parse_pdf_calls_gemini_and_builds_pages(tmp_path: Path) -> None:
    pdf_path = _make_pdf(tmp_path, page_count=1)
    parser = GeminiVisionParser(data_root=tmp_path / "data")

    gemini_response = {
        "candidates": [
            {
                "content": {
                    "parts": [
                        {
                            "text": json.dumps(
                                {
                                    "blocks": [
                                        {
                                            "type": "paragraph",
                                            "text": "มาตรา ๑ ข้อความทดสอบ",
                                            "confidence": 0.94,
                                            "alignment": "left",
                                        }
                                    ]
                                },
                                ensure_ascii=False,
                            )
                        }
                    ]
                }
            }
        ],
        "usageMetadata": {"totalTokenCount": 120},
    }

    response = MagicMock()
    response.raise_for_status = MagicMock()
    response.json.return_value = gemini_response

    client = MagicMock()
    client.post.return_value = response

    cm = MagicMock()
    cm.__enter__.return_value = client
    cm.__exit__.return_value = False

    with patch("app.services.gemini_vision_parser.get_settings") as mock_settings, \
         patch("app.services.gemini_vision_parser.httpx.Client", return_value=cm):
        mock_settings.return_value.gemini_api_key = "test-key"
        mock_settings.return_value.gemini_model = "gemini-2.0-flash"
        mock_settings.return_value.gemini_timeout_seconds = 30
        pages = parser.parse_pdf(pdf_path, "doc-gemini")

    assert len(pages) == 1
    assert pages[0]["page_no"] == 1
    assert pages[0]["blocks"][0]["raw_text"] == "มาตรา ๑ ข้อความทดสอบ"
    assert parser.last_metadata is not None
    assert parser.last_metadata["source"] == "gemini"
    assert parser.last_metadata["model"] == "gemini-2.0-flash"

    client.post.assert_called_once()
    kwargs = client.post.call_args.kwargs
    assert kwargs["params"]["key"] == "test-key"
    assert "inline_data" in kwargs["json"]["contents"][0]["parts"][1]


def test_gemini_mode_falls_back_to_local_on_api_error(tmp_path: Path) -> None:
    from app.api.routes import _extract_scan_pages

    with patch("app.api.routes.GeminiVisionParser") as mock_parser_cls, \
         patch("app.api.routes.get_ocr_pipeline") as mock_pipeline_fn, \
         patch("app.api.routes.get_settings") as mock_settings:

        mock_settings.return_value.gemini_api_key = "test-key"

        mock_parser = MagicMock()
        mock_parser.parse_pdf.side_effect = RuntimeError("gemini down")
        mock_parser_cls.return_value = mock_parser

        mock_pipeline = MagicMock()
        mock_pipeline.extract_scanned_pdf.return_value = [
            {
                "page_no": 1,
                "blocks": [{"type": "paragraph", "raw_text": "ocr fallback", "confidence": 0.95, "flags": []}],
            }
        ]
        mock_pipeline_fn.return_value = mock_pipeline

        pages, mode, landingai_meta, gemini_meta = _extract_scan_pages(
            file_path=tmp_path / "fake.pdf",
            document_id="test-doc",
            requested_mode="gemini",
            data_root=tmp_path,
        )

    assert mode == "local"
    assert pages[0]["blocks"][0]["raw_text"] == "ocr fallback"
    assert landingai_meta is None
    assert gemini_meta is None


def test_should_force_gemini_scan_for_pdf_text_and_mixed() -> None:
    from app.api.routes import _should_force_gemini_scan

    assert _should_force_gemini_scan("pdf_text", "gemini") is True
    assert _should_force_gemini_scan("mixed", "gemini") is True
    assert _should_force_gemini_scan("pdf_scan", "gemini") is False
    assert _should_force_gemini_scan("pdf_text", "auto") is False
    assert _should_force_gemini_scan("pdf_text", "local") is False


def test_pdf_text_with_gemini_mode_uses_scan_pipeline(tmp_path: Path) -> None:
    from app.api.routes import _run_extraction
    from app.api.schemas import ExtractRequest

    pdf_path = _make_pdf(tmp_path)
    payload = ExtractRequest(
        document_id="doc-force-gemini",
        file_path=str(pdf_path),
        enable_ai_correction=False,
        callback_url=None,
        scan_extraction_mode="gemini",
    )

    scan_pages = [{"page_no": 1, "blocks": [{"type": "paragraph", "raw_text": "จาก gemini", "confidence": 0.9, "flags": ["gemini_ocr"]}]}]
    gemini_meta = {"source": "gemini", "model": "gemini-2.0-flash", "page_count": 1}

    with patch("app.api.routes.detect_file_type") as mock_detect, \
         patch("app.api.routes._extract_scan_pages") as mock_scan, \
         patch("app.api.routes.DoclingService") as mock_docling_cls, \
         patch("app.api.routes.build_document_output") as mock_build, \
         patch("app.api.routes.get_settings") as mock_settings:

        mock_detect.return_value = {"mode": "pdf_text", "pages": {"text": [0], "scan": []}}
        mock_scan.return_value = (scan_pages, "gemini", None, gemini_meta)
        mock_build.return_value = {
            "document_id": payload.document_id,
            "pages": [],
            "extraction": {},
        }
        mock_settings.return_value.data_root = tmp_path
        mock_settings.return_value.thai_review_threshold = 0.9

        _run_extraction(payload)

    mock_scan.assert_called_once()
    mock_docling_cls.return_value.extract.assert_not_called()
