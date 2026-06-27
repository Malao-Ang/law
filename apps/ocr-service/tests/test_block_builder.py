"""Tests for block_builder normalization contract.

raw_text must be the pre-normalization source text. Normalization
must run exactly once, in build_document_output.
"""
from __future__ import annotations

from unittest.mock import MagicMock

from app.services.block_builder import build_document_output


def _make_page(raw_text: str) -> dict:
    return {
        "page_no": 1,
        "width": 595.0,
        "height": 842.0,
        "blocks": [
            {
                "block_id": "1-1",
                "type": "paragraph",
                "reading_order": 1,
                "raw_text": raw_text,
                "confidence": 1.0,
                "flags": [],
                "bbox": [72, 100, 500, 120],
                "meta": {},
            }
        ],
    }


def _run_build(raw_text: str) -> dict:
    """Run build_document_output and return the first output block."""
    call_count = {"n": 0}
    original_inputs: list[str] = []

    def counting_normalizer(text: str) -> dict:
        call_count["n"] += 1
        original_inputs.append(text)
        # Simulate a normalizer that changes input (e.g. vowel reordering)
        return {"text": text.replace("า", "ๅ"), "flags": []}

    ai = MagicMock()
    ai.suggest.return_value = {"suggested_text": "x", "confidence": 1.0, "reason": []}

    output = build_document_output(
        document_id="test",
        source_file="test.pdf",
        source_type="pdf_text",
        pages=[_make_page(raw_text)],
        normalizer=counting_normalizer,
        ai_corrector=ai,
        enable_ai_correction=False,
        review_threshold=0.90,
    )

    block = output["pages"][0]["blocks"][0]
    block["_call_count"] = call_count["n"]
    block["_normalizer_inputs"] = original_inputs
    return block


def test_normalizer_called_exactly_once_per_block():
    """Normalizer must be called exactly once per block, with the raw source text."""
    block = _run_build("สวัสดีครับ")
    assert block["_call_count"] == 1, (
        f"Normalizer was called {block['_call_count']} times; expected exactly 1. "
        "Check whether extractors pre-normalize before storing raw_text."
    )


def test_raw_text_preserved_in_output():
    """raw_text in the output block must equal the input raw_text unchanged."""
    raw = "สวัสดีครับ"
    block = _run_build(raw)
    assert block["raw_text"] == raw, (
        "raw_text was modified. Extractors must store verbatim source text."
    )


def test_normalized_text_differs_from_raw_when_normalizer_transforms():
    """normalized_text must reflect the normalizer output, not the raw input."""
    raw = "กรุงเทพมหานคร"
    block = _run_build(raw)
    # counting_normalizer replaces า→ๅ; raw has า so texts must differ
    assert block["normalized_text"] != raw
