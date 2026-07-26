from __future__ import annotations

import json
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from fastapi.testclient import TestClient

from app.main import app
from app.services.doc_converter import ConversionResult


FIXTURES_DIR = Path(__file__).parent / "fixtures"
DOCUMENT_ID = "doc-e2e-001"


def test_extract_doc_fixture_writes_intermediate_output(tmp_path: Path) -> None:
    source = FIXTURES_DIR / "sample.doc"
    assert source.exists(), "sample.doc fixture is missing; run scripts/regenerate-doc-fixtures.sh"

    converted = tmp_path / "converted.docx"
    converted.write_bytes(b"PK\x03\x04converted")

    settings = SimpleNamespace(
        data_root=tmp_path,
        soffice_binary="soffice",
        doc_conversion_timeout_seconds=60,
        thai_review_threshold=0.90,
        landingai_api_key=None,
    )
    payload = {
        "document_id": DOCUMENT_ID,
        "file_path": str(source),
        "enable_ai_correction": False,
    }
    pages = [
        {
            "page_no": 1,
            "blocks": [
                {
                    "block_id": "1-1",
                    "type": "paragraph",
                    "reading_order": 1,
                    "raw_text": "ทดสอบเอกสาร .doc",
                    "confidence": 0.99,
                    "flags": [],
                }
            ],
        }
    ]

    with (
        patch("app.api.routes.get_settings", return_value=settings),
        patch(
            "app.api.routes.DocConverter.convert",
            return_value=ConversionResult(
                output_path=converted,
                duration_ms=1750,
                exit_code=0,
                soffice_version="LibreOffice 24.2.3.2",
            ),
        ) as mock_convert,
        patch("app.api.routes.DoclingService.extract", return_value=pages) as mock_extract,
    ):
        client = TestClient(app, raise_server_exceptions=False)
        response = client.post("/pipeline/extract", json=payload)

    assert response.status_code == 202
    mock_convert.assert_called_once_with(source, DOCUMENT_ID)
    mock_extract.assert_called_once_with(file_path=converted, source_type="docx", document_id=DOCUMENT_ID)

    output_path = tmp_path / "intermediate" / f"{DOCUMENT_ID}.review.json"
    assert output_path.exists()

    output = json.loads(output_path.read_text(encoding="utf-8"))
    assert output["document_id"] == DOCUMENT_ID
    assert output["source_file"] == "sample.doc"
    assert output["source_type"] == "docx"
    assert output["summary"]["page_count"] == 1
    assert output["summary"]["block_count"] == 1
    assert output["extraction"]["path"] == ["doc_to_docx_conversion", "docling_docx"]
    assert output["extraction"]["conversion"] == {
        "tool": "libreoffice",
        "duration_ms": 1750,
        "exit_code": 0,
        "soffice_version": "LibreOffice 24.2.3.2",
    }
