"""Tests for block_builder normalization contract.

raw_text must be the pre-normalization source text. Normalization
must run exactly once, in build_document_output.
"""
from __future__ import annotations

from unittest.mock import MagicMock

import pytest

from app.services.block_builder import _is_marker_only, build_document_output


class FakeSpellChecker:
    def __init__(self, results: list[list[dict]] | None = None) -> None:
        self.results = results or []
        self.bulk_calls: list[list[str]] = []
        self.check_calls: list[str] = []

    def bulk_check(self, texts: list[str]) -> list[list[dict]]:
        self.bulk_calls.append(texts)
        return self.results or [[] for _ in texts]

    def check(self, text: str) -> list[dict]:
        self.check_calls.append(text)
        raise AssertionError("build_document_output must use bulk_check")


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


def _make_multi_block_page(blocks: list[dict]) -> dict:
    return {
        "page_no": 1,
        "width": 595.0,
        "height": 842.0,
        "blocks": blocks,
    }


def _make_block(
    raw_text: str,
    block_id: str,
    reading_order: int,
    confidence: float = 1.0,
    block_type: str = "paragraph",
) -> dict:
    return {
        "block_id": block_id,
        "type": block_type,
        "reading_order": reading_order,
        "raw_text": raw_text,
        "confidence": confidence,
        "flags": [],
        "bbox": [72, 100 + reading_order * 20, 500, 120 + reading_order * 20],
        "meta": {},
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


@pytest.mark.parametrize("text", ["มาตรา ๑", "ข้อ ๒", "วรรค ๓", "(ก)", "(๑)", "1.", "๑.๑"])
def test_is_marker_only_matches_explicit_markers(text):
    assert _is_marker_only(text) is True


@pytest.mark.parametrize("text", ["คำ", "หมวด", "มาตรา ๑ บททั่วไป", "ข้อ ๒ ให้ดำเนินการ"])
def test_is_marker_only_rejects_words_and_marker_with_body_text(text):
    assert _is_marker_only(text) is False


def test_build_document_output_calls_bulk_spellcheck_once(monkeypatch):
    spellchecker = FakeSpellChecker(
        [
            [{"token": "ผิดดด", "suggestion": "ผิด", "confidence": 1.0, "offset": 0}],
            [],
        ]
    )
    monkeypatch.setattr("app.services.block_builder.get_spell_checker", lambda: spellchecker)

    page = _make_multi_block_page(
        [
            _make_block("ผิดดด", "1-1", 1, confidence=0.50),
            _make_block("ถูกต้อง", "1-2", 2, confidence=0.40),
        ]
    )
    ai = MagicMock()

    output = build_document_output(
        document_id="test",
        source_file="test.pdf",
        source_type="pdf_text",
        pages=[page],
        normalizer=lambda text: {"text": text, "flags": []},
        ai_corrector=ai,
        enable_ai_correction=False,
        review_threshold=0.90,
    )

    assert spellchecker.bulk_calls == [["ผิดดด", "ถูกต้อง"]]
    assert spellchecker.check_calls == []
    blocks = output["pages"][0]["blocks"]
    assert blocks[0]["meta"]["spell_suggestions"] == [
        {"token": "ผิดดด", "suggestion": "ผิด", "confidence": 1.0, "offset": 0}
    ]
    assert blocks[1]["meta"]["spell_suggestions"] == []


def test_marker_only_blocks_skip_normalizer():
    normalizer = MagicMock(side_effect=AssertionError("marker-only text should not be normalized"))
    ai = MagicMock()

    output = build_document_output(
        document_id="test",
        source_file="test.pdf",
        source_type="pdf_text",
        pages=[_make_page("มาตรา ๑")],
        normalizer=normalizer,
        ai_corrector=ai,
        enable_ai_correction=False,
        review_threshold=0.90,
    )

    block = output["pages"][0]["blocks"][0]
    assert normalizer.call_count == 0
    assert block["normalized_text"] == "มาตรา ๑"


def test_non_marker_thai_text_still_calls_normalizer():
    normalizer = MagicMock(return_value={"text": "normalized", "flags": []})
    ai = MagicMock()

    output = build_document_output(
        document_id="test",
        source_file="test.pdf",
        source_type="pdf_text",
        pages=[_make_page("มาตรา ๑ บททั่วไป")],
        normalizer=normalizer,
        ai_corrector=ai,
        enable_ai_correction=False,
        review_threshold=0.90,
    )

    normalizer.assert_called_once_with("มาตรา ๑ บททั่วไป")
    assert output["pages"][0]["blocks"][0]["normalized_text"] == "normalized"
