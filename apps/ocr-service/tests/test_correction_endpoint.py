"""Tests for /pipeline/correct endpoint."""
from __future__ import annotations

import json
from pathlib import Path
from unittest.mock import patch

from fastapi.testclient import TestClient

from app.api.schemas import intermediate_output_path
from app.main import app


def _write_fast_review(data_root: Path, document_id: str) -> Path:
    output = {
        "document_id": document_id,
        "source_file": "fixture.docx",
        "source_type": "docx",
        "language": "th",
        "summary": {"page_count": 1, "block_count": 2, "review_required_count": 0},
        "pages": [{
            "page_no": 1,
            "image_path": None,
            "image_url": None,
            "source_kind": "docx",
            "blocks": [
                {
                    "block_id": "p1-b0001",
                    "type": "paragraph",
                    "bbox": None,
                    "reading_order": 1,
                    "raw_text": "มาตรา ๑ ระเบียบนี้เรียกว่า",
                    "normalized_text": "มาตรา ๑ ระเบียบนี้เรียกว่า",
                    "ai_suggested_text": "มาตรา ๑ ระเบียบนี้เรียกว่า",
                    "approved_text": "มาตรา ๑ ระเบียบนี้เรียกว่า",
                    "confidence": 1.0,
                    "needs_review": False,
                    "flags": ["fast_extracted"],
                    "meta": {
                        "layout": {
                            "alignment": None,
                            "indent_left": None,
                            "indent_first_line": None,
                            "indent_hanging": None,
                            "indent_level": None,
                            "tabs": [],
                            "reading_order": 1,
                        },
                        "list_marker": None,
                        "formatting": {"bold": False, "italic": False, "underline": False},
                    },
                },
                {
                    "block_id": "p1-b0002",
                    "type": "paragraph",
                    "bbox": None,
                    "reading_order": 2,
                    "raw_text": "เปนข้อความที่ตองนอรมัลไลซ",
                    "normalized_text": "เปนข้อความที่ตองนอรมัลไลซ",
                    "ai_suggested_text": "เปนข้อความที่ตองนอรมัลไลซ",
                    "approved_text": "เปนข้อความที่ตองนอรมัลไลซ",
                    "confidence": 1.0,
                    "needs_review": False,
                    "flags": ["fast_extracted"],
                    "meta": {
                        "layout": {
                            "alignment": None,
                            "indent_level": None,
                            "tabs": [],
                            "reading_order": 2,
                        },
                        "list_marker": None,
                        "formatting": {"bold": False, "italic": False, "underline": False},
                    },
                },
            ],
        }],
    }

    path = intermediate_output_path(data_root, document_id)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(output, ensure_ascii=False), encoding="utf-8")
    return path


def test_correct_endpoint_accepts_request(tmp_path):
    from app.core.config import Settings

    fake_settings = Settings(data_root=str(tmp_path), thai_review_threshold=0.90)
    document_id = "doc-correct-001"
    _write_fast_review(tmp_path, document_id)

    with patch("app.api.routes.get_settings", return_value=fake_settings):
        client = TestClient(app)
        response = client.post("/pipeline/correct", json={
            "document_id": document_id,
            "callback_url": None,
        })

    assert response.status_code == 202
    assert response.json()["status"] == "accepted"


def test_correct_service_runs_normalization_inline(tmp_path):
    from app.services.correction_service import run_correction

    document_id = "doc-correct-002"
    review_path = _write_fast_review(tmp_path, document_id)

    run_correction(
        document_id=document_id,
        data_root=str(tmp_path),
        enable_ai_correction=False,
        review_threshold=0.90,
        callback_url=None,
    )

    updated = json.loads(review_path.read_text(encoding="utf-8"))
    block = updated["pages"][0]["blocks"][0]
    assert block["meta"]["list_marker"] is not None
    assert block["meta"]["list_marker"]["type"] == "legal-มาตรา"
    assert "normalized" in block["flags"] or "fast_extracted" in block["flags"]


def test_correct_service_posts_callback(tmp_path):
    from app.services.correction_service import run_correction

    document_id = "doc-correct-003"
    _write_fast_review(tmp_path, document_id)

    captured = {}

    def fake_post(url, document_id, payload, logger):
        captured["url"] = url
        captured["payload"] = payload

    with patch("app.services.correction_service._post_callback", side_effect=fake_post):
        run_correction(
            document_id=document_id,
            data_root=str(tmp_path),
            enable_ai_correction=False,
            review_threshold=0.90,
            callback_url="http://laravel-app:8000/api/internal/pipeline-callback",
        )

    assert captured["url"] == "http://laravel-app:8000/api/internal/pipeline-callback"
    assert captured["payload"]["status"] == "correction_done"
    assert "output" in captured["payload"]
