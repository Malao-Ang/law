"""Tests that reprocess-block re-normalizes from raw_text, not stale normalized_text."""
from __future__ import annotations

import json
from pathlib import Path
from unittest.mock import patch

from fastapi.testclient import TestClient

from app.main import app


def _make_review_doc(raw_text: str, stale_normalized: str) -> dict:
    return {
        "pages": [{
            "page_no": 1,
            "blocks": [{
                "block_id": "1-1",
                "type": "paragraph",
                "raw_text": raw_text,
                "normalized_text": stale_normalized,
                "ai_suggested_text": stale_normalized,
                "approved_text": stale_normalized,
                "confidence": 0.5,
                "flags": [],
                "meta": {},
            }]
        }]
    }


def test_reprocess_block_renormalizes_from_raw_text(tmp_path: Path) -> None:
    """reprocess_block must call normalize_text(raw_text), not use stored normalized_text."""
    output_file = tmp_path / "test-doc.review.json"
    output_file.write_text(
        json.dumps(_make_review_doc("สวัสดี", "STALE_VALUE")),
        encoding="utf-8",
    )

    with patch("app.api.routes.intermediate_output_path", return_value=output_file), \
         patch("app.api.routes.normalize_text", wraps=lambda t: {"text": f"NORM:{t}", "flags": []}) as mock_norm:

        client = TestClient(app)
        response = client.post("/pipeline/reprocess-block", json={
            "document_id": "test-doc",
            "page_no": 1,
            "block_id": "1-1",
        })

    assert response.status_code == 200
    # normalize_text must have been called with raw_text, not stale_normalized
    mock_norm.assert_called_once_with("สวัสดี")

    updated = json.loads(output_file.read_text(encoding="utf-8"))
    block = updated["pages"][0]["blocks"][0]
    assert block["normalized_text"] == "NORM:สวัสดี"
    assert block["normalized_text"] != "STALE_VALUE"
