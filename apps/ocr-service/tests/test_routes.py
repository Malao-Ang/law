"""Tests for the /pipeline/extract route.

Focuses on the failure-callback path: when extraction raises an exception,
the route must POST a failure payload to the callback_url with:
  { status: "failed", error: <message>, error_code: "EXTRACTION_FAILED" }
"""

import json
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest
from fastapi.testclient import TestClient

from app.main import app


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

CALLBACK_URL = "http://laravel-app:8000/api/internal/pipeline-callback"
DOCUMENT_ID = "test-doc-001"


def _make_payload(tmp_path: Path) -> dict:
    """Return a valid ExtractRequest payload with a real (empty) file."""
    dummy_file = tmp_path / "sample.pdf"
    dummy_file.write_bytes(b"%PDF-1.4 dummy")
    return {
        "document_id": DOCUMENT_ID,
        "file_path": str(dummy_file),
        "enable_ai_correction": False,
        "callback_url": CALLBACK_URL,
    }


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------


def test_failure_callback_sent_when_extraction_raises(tmp_path: Path) -> None:
    """When _run_extraction raises, a failure callback must be POSTed.

    The callback body must contain:
      - document_id matching the request
      - status == "failed"
      - error_code == "EXTRACTION_FAILED"
      - a non-empty error message
    """
    payload = _make_payload(tmp_path)

    # Capture payload passed to callback helper.
    captured_calls: list[tuple[str, str, dict]] = []

    def _fake_callback(url: str, document_id: str, callback_payload: dict, _logger: object) -> None:
        captured_calls.append((url, document_id, callback_payload))

    boom = RuntimeError("simulated extraction failure")

    with (
        patch("app.api.routes.detect_file_type", side_effect=boom),
        patch("app.api.routes._post_callback", side_effect=_fake_callback),
    ):
        # TestClient runs background tasks synchronously before the response
        # is returned when raise_server_exceptions=False (background tasks
        # still execute; only unhandled *request* exceptions are suppressed).
        # We use raise_server_exceptions=False so the re-raise inside
        # _run_extraction does not bubble up through the test client.
        client = TestClient(app, raise_server_exceptions=False)
        response = client.post("/pipeline/extract", json=payload)

    # The endpoint itself should return 202 Accepted.
    assert response.status_code == 202

    # Exactly one callback must have been fired.
    assert len(captured_calls) == 1, (
        f"Expected 1 callback call, got {len(captured_calls)}: {captured_calls}"
    )

    cb_url, cb_document_id, cb_payload = captured_calls[0]
    assert cb_url == CALLBACK_URL
    assert cb_document_id == DOCUMENT_ID
    assert cb_payload["status"] == "failed"
    assert cb_payload["error_code"] == "EXTRACTION_FAILED"
    assert cb_payload["error"], "error field must be a non-empty string"
    assert "simulated extraction failure" in cb_payload["error"]


def test_no_callback_when_callback_url_is_omitted(tmp_path: Path) -> None:
    """When callback_url is None, no HTTP call should be made on failure."""
    dummy_file = tmp_path / "sample.pdf"
    dummy_file.write_bytes(b"%PDF-1.4 dummy")
    payload = {
        "document_id": DOCUMENT_ID,
        "file_path": str(dummy_file),
        "enable_ai_correction": False,
        # no callback_url
    }

    post_calls: list = []

    def _fake_callback(url: str, _document_id: str, _callback_payload: dict, _logger: object) -> None:
        post_calls.append(url)

    boom = RuntimeError("simulated extraction failure")

    with (
        patch("app.api.routes.detect_file_type", side_effect=boom),
        patch("app.api.routes._post_callback", side_effect=_fake_callback),
    ):
        client = TestClient(app, raise_server_exceptions=False)
        response = client.post("/pipeline/extract", json=payload)

    assert response.status_code == 202
    assert post_calls == [], "No HTTP callback should be made when callback_url is absent"


def test_extract_returns_404_when_file_missing() -> None:
    """POST /pipeline/extract must return 404 when the file does not exist."""
    payload = {
        "document_id": DOCUMENT_ID,
        "file_path": "/nonexistent/path/to/file.pdf",
        "enable_ai_correction": False,
        "callback_url": CALLBACK_URL,
    }
    client = TestClient(app)
    response = client.post("/pipeline/extract", json=payload)
    assert response.status_code == 404


def test_landingai_mode_skips_local_ocr_pipeline_for_scan_pdf(tmp_path: Path) -> None:
    payload = _make_payload(tmp_path) | {"scan_extraction_mode": "landingai"}

    with (
        patch("app.api.routes.detect_file_type", return_value={"mode": "pdf_scan", "pages": {}}),
        patch("app.api.routes.get_ocr_pipeline") as mock_get_ocr_pipeline,
        patch("app.api.routes.LandingAiAdeParser.parse_pdf", return_value=[]),
        patch("app.api.routes.build_document_output", return_value={"document_id": DOCUMENT_ID, "pages": []}),
        patch("app.api.routes._post_callback"),
    ):
        client = TestClient(app, raise_server_exceptions=False)
        response = client.post("/pipeline/extract", json=payload)

    assert response.status_code == 202
    mock_get_ocr_pipeline.assert_not_called()
