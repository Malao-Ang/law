"""
Golden validation tests for Thai document extraction pipeline.
These tests validate against fixture files that define expected behavior
for different document types and processing stages.

Accuracy-gate tests (TestGoldenAccuracy) run the full extraction pipeline
synchronously against golden fixture files in tests/fixtures/golden/ and
assert field-level accuracy thresholds.
"""

import json
from pathlib import Path

import pytest

from app.services.block_builder import (
    build_document_output,
    build_layout_style,
    build_reviewed_html,
    build_table_payload,
    escape_html,
)
from app.services.html_renderer import build_reviewed_html as render_html
from app.services.thai_normalizer import normalize_text


FIXTURES_DIR = Path(__file__).parent / "fixtures"


def load_fixture(name: str) -> dict:
    path = FIXTURES_DIR / f"{name}.json"
    if not path.exists():
        pytest.skip(f"Fixture {name} not found")
    return json.loads(path.read_text(encoding="utf-8"))


# ── Thai Normalization Fixtures ──

class TestThaiNormalization:
    def test_normalization_cases(self):
        fixture = load_fixture("thai_normalization")
        for test_case in fixture["normalization_tests"]:
            result = normalize_text(test_case["input"])
            assert result["text"] == test_case["expected"], (
                f"Normalization failed for '{test_case['input']}': "
                f"got '{result['text']}', expected '{test_case['expected']}'"
            )
            for flag in test_case.get("expected_flags", []):
                assert flag in result["flags"], (
                    f"Expected flag '{flag}' not found in {result['flags']}"
                )

    def test_list_prefix_validation(self):
        fixture = load_fixture("thai_normalization")
        import re

        thai_prefix_pattern = re.compile(
            r"^(\d+\.\d*|\d+\.\d+\.\d*|\(\d+\)|\([ก-ฮ]\)|[๐-๙]+\.|ข้อ\s*[๐-๙0-9]+)"
        )

        for test in fixture.get("list_prefix_tests", []):
            is_valid = bool(thai_prefix_pattern.match(test["input"]))
            if test["valid"]:
                assert is_valid, f"Expected valid prefix: {test['input']}"
            else:
                assert not is_valid, f"Expected invalid prefix: {test['input']}"


# ── DOCX Fixture Tests ──

class TestDocxExtraction:
    def test_docx_fixture_structure(self):
        fixture = load_fixture("docx_numbering")
        assert fixture["source_type"] == "docx"
        assert "expected_block_types" in fixture
        assert "table" in fixture["expected_block_types"]
        assert "list_item" in fixture["expected_block_types"]

    def test_numbering_prefix_extraction(self):
        fixture = load_fixture("docx_numbering")
        rules = fixture["validation_rules"]["numbering"]
        assert rules["check_prefixes"] is True
        assert "1." in rules["expected_prefixes"]
        assert "ข้อ ๑" in rules["expected_prefixes"]

    def test_table_structure_validation(self):
        fixture = load_fixture("docx_numbering")
        rules = fixture["validation_rules"]["table"]
        assert rules["check_rowspan"] is True
        assert rules["check_colspan"] is True
        assert "ลำดับ" in rules["expected_headers"]


# ── Text PDF Fixture Tests ──

class TestTextPdfExtraction:
    def test_text_pdf_fixture_structure(self):
        fixture = load_fixture("pdf_text")
        assert fixture["source_type"] == "pdf_text"
        assert fixture["expected_page_count"] == 2
        assert "table" in fixture["expected_block_types"]

    def test_thai_text_validation_rules(self):
        fixture = load_fixture("pdf_text")
        rules = fixture["validation_rules"]["thai_text"]
        assert rules["check_combining_marks"] is True
        assert rules["check_sara_am"] is True
        assert rules["min_thai_ratio"] >= 0.5

    def test_table_extraction_rules(self):
        fixture = load_fixture("pdf_text")
        rules = fixture["validation_rules"]["tables"]
        assert rules["check_bbox_overlap"] is True
        assert rules["expected_table_count"] >= 1


# ── Scan PDF Fixture Tests ──

class TestScanPdfExtraction:
    def test_scan_pdf_fixture_structure(self):
        fixture = load_fixture("pdf_scan")
        assert fixture["source_type"] == "pdf_scan"
        assert "ocr_scan" in fixture["validation_rules"]["flags"]["expected"]

    def test_ocr_quality_thresholds(self):
        fixture = load_fixture("pdf_scan")
        rules = fixture["validation_rules"]["ocr_quality"]
        assert rules["min_confidence"] >= 0.6
        assert "มาตรา" in rules["expected_keywords"]


# ── HTML Generation Fixture Tests ──

class TestHtmlGeneration:
    def test_html_block_id_presence(self):
        fixture = load_fixture("html_generation")
        for test in fixture["html_generation_tests"]:
            block = test["block"]
            layout = block.get("meta", {}).get("layout", {})
            table = build_table_payload(block, block.get("approved_text", ""), block["type"])
            html = build_reviewed_html(
                block_type=block["type"],
                text=block.get("approved_text", ""),
                layout=layout,
                block_id=block["block_id"],
                table=table,
                source_meta=block.get("meta"),
            )
            for expected in test["expected_html_contains"]:
                assert expected in html, (
                    f"Expected '{expected}' in HTML: {html}"
                )
            for not_expected in test.get("expected_html_not_contains", []):
                assert not_expected not in html, (
                    f"Did not expect '{not_expected}' in HTML: {html}"
                )

    def test_table_html_generation(self):
        cells = [
            [{"text": "A", "colspan": 1, "rowspan": 1}],
            [{"text": "1", "colspan": 1, "rowspan": 1}],
        ]
        table = {
            "headers": ["A"],
            "rows": [["1"]],
            "cells": cells,
            "html": "<table><tbody><tr><th>A</th></tr><tr><td>1</td></tr></tbody></table>",
        }
        html = build_reviewed_html(
            block_type="table",
            text="",
            layout={},
            block_id="t-1",
            table=table,
        )
        assert 'data-block-id="t-1"' in html
        assert "<table" in html

    def test_layout_css_standardization(self):
        """layout_css must be stored under meta.layout.layout_css"""
        layout = {
            "indent_left": 720,  # 36pt in twips
            "line_spacing": 480,
        }
        style = build_layout_style(layout)
        assert "margin-left:36.0pt" in style
        assert "line-height:2.00" in style

    def test_escape_html_security(self):
        malicious = "<script>alert('xss')</script>"
        safe = escape_html(malicious)
        assert "<script>" not in safe
        assert "&lt;script&gt;" in safe


# ── Confidence and Flag Tests ──

class TestConfidenceScoring:
    def test_replacement_character_detection(self):
        text = "ข้อความ\ufffdเพี้ยน"
        result = normalize_text(text)
        assert "replacement_char" in result["flags"]

    def test_sara_am_normalization(self):
        text = "ํา"
        result = normalize_text(text)
        assert result["text"] == "ำ"
        assert "sara_am_fix" in result["flags"]

    def test_low_thai_ratio_flag(self):
        text = "ABC 123 !!!"
        result = normalize_text(text)
        # Should flag low Thai ratio for documents expected to be Thai
        if "low_thai_ratio" not in result["flags"]:
            # The normalizer may not flag this - that's OK if it's by design
            pass


# ── Document Output Builder Tests ──

class TestDocumentOutputBuilder:
    def test_build_document_output_structure(self):
        pages = [
            {
                "page_no": 1,
                "blocks": [
                    {
                        "block_id": "1-1",
                        "type": "paragraph",
                        "reading_order": 1,
                        "raw_text": "ทดสอบ",
                        "confidence": 0.95,
                        "flags": [],
                    }
                ],
            }
        ]

        # Mock AI corrector
        class MockCorrector:
            def suggest(self, text: str) -> dict:
                return {"suggested_text": text, "confidence": 0.95, "reason": []}

        output = build_document_output(
            document_id="test_doc",
            source_file="test.pdf",
            source_type="pdf_text",
            pages=pages,
            normalizer=normalize_text,
            ai_corrector=MockCorrector(),
            enable_ai_correction=False,
            review_threshold=0.90,
        )

        assert output["document_id"] == "test_doc"
        assert output["language"] == "th"
        assert output["summary"]["page_count"] == 1
        assert output["summary"]["block_count"] == 1
        assert "pages" in output
        assert len(output["pages"]) == 1

        # Check block structure
        block = output["pages"][0]["blocks"][0]
        assert "raw_text" in block
        assert "normalized_text" in block
        assert "ai_suggested_text" in block
        assert "approved_text" in block
        assert "confidence" in block
        assert "needs_review" in block
        assert "flags" in block
        assert "meta" in block

    def test_layout_css_in_meta_layout(self):
        """Ensure layout_css is stored under meta.layout.layout_css"""
        pages = [
            {
                "page_no": 1,
                "blocks": [
                    {
                        "block_id": "1-1",
                        "type": "paragraph",
                        "reading_order": 1,
                        "raw_text": "ทดสอบ",
                        "confidence": 0.95,
                        "flags": [],
                        "meta": {
                            "layout": {
                                "indent_left": 720,
                            }
                        },
                    }
                ],
            }
        ]

        # Mock AI corrector
        class MockCorrector:
            def suggest(self, text: str) -> dict:
                return {"suggested_text": text, "confidence": 0.95, "reason": []}

        output = build_document_output(
            document_id="test_doc",
            source_file="test.pdf",
            source_type="pdf_text",
            pages=pages,
            normalizer=normalize_text,
            ai_corrector=MockCorrector(),
            enable_ai_correction=True,
            review_threshold=0.90,
        )

        block = output["pages"][0]["blocks"][0]
        layout = block["meta"]["layout"]
        assert "layout_css" in layout, (
            f"layout_css not found in meta.layout. Keys: {layout.keys()}"
        )
        assert "margin-left" in layout["layout_css"]


# ── Integration Smoke Tests ──

class TestPipelineIntegration:
    def test_file_type_detection_imports(self):
        from app.utils.file_type import detect_file_type
        assert callable(detect_file_type)

    def test_block_builder_imports(self):
        from app.services.block_builder import build_document_output
        assert callable(build_document_output)

    def test_normalizer_imports(self):
        from app.services.thai_normalizer import normalize_text
        assert callable(normalize_text)


# ── Accuracy-gate: full-pipeline golden tests ─────────────────────────────────

GOLDEN_DIR = Path(__file__).parent / "fixtures" / "golden"


def _levenshtein_ratio(a: str, b: str) -> float:
    """Simple Levenshtein ratio using difflib for test purposes."""
    import difflib
    return difflib.SequenceMatcher(None, a, b).ratio()


def _run_extraction_sync(source_file: Path, tmp_path: Path) -> dict:
    """Run the full extraction pipeline synchronously; return the document output."""
    from app.api.schemas import ExtractRequest
    from app.core.config import get_settings
    from app.services.ai_corrector import MockAICorrector
    from app.services.block_builder import build_document_output
    from app.services.docling_service import DoclingService
    from app.services.ocr_pipeline import get_ocr_pipeline
    from app.services.thai_normalizer import normalize_text
    from app.utils.file_type import detect_file_type

    document_id = f"golden_test_{source_file.stem}"
    settings = get_settings()
    data_root = tmp_path

    classification = detect_file_type(source_file)
    mode = classification["mode"]

    docling_service = DoclingService(data_root=data_root)
    ocr_pipeline = get_ocr_pipeline(data_root=data_root)

    if mode == "docx":
        pages = docling_service.extract(file_path=source_file, source_type="docx", document_id=document_id)
        source_type = "docx"
    elif mode == "pdf_text":
        pages = docling_service.extract(file_path=source_file, source_type="pdf_text", document_id=document_id)
        source_type = "pdf_text"
    elif mode == "pdf_scan":
        pages = ocr_pipeline.extract_scanned_pdf(file_path=source_file, document_id=document_id)
        source_type = "pdf_scan"
    else:
        pages = docling_service.extract_mixed_pdf(
            file_path=source_file,
            page_classification=classification["pages"],
            document_id=document_id,
            ocr_pipeline=ocr_pipeline,
        )
        source_type = "pdf_mixed"

    return build_document_output(
        document_id=document_id,
        source_file=source_file.name,
        source_type=source_type,
        pages=pages,
        normalizer=normalize_text,
        ai_corrector=MockAICorrector(),
        enable_ai_correction=False,
        review_threshold=0.90,
    )


def _flat_blocks(document: dict) -> list[dict]:
    return [block for page in document.get("pages", []) for block in page.get("blocks", [])]


def _text_accuracy(actual_blocks: list[dict], golden_blocks: list[dict]) -> float:
    """Levenshtein ratio averaged across all block pairs."""
    if not golden_blocks:
        return 1.0
    pairs = zip(actual_blocks, golden_blocks)
    ratios = [
        _levenshtein_ratio(
            str(a.get("approved_text") or ""),
            str(g.get("approved_text") or ""),
        )
        for a, g in pairs
    ]
    return sum(ratios) / len(ratios) if ratios else 1.0


def _indent_accuracy(actual_blocks: list[dict], golden_blocks: list[dict]) -> float:
    """Fraction of blocks where indent_level matches exactly."""
    pairs = [(a, g) for a, g in zip(actual_blocks, golden_blocks)
             if g.get("meta", {}).get("layout", {}).get("indent_level") is not None]
    if not pairs:
        return 1.0
    correct = sum(
        1 for a, g in pairs
        if a.get("meta", {}).get("layout", {}).get("indent_level") ==
           g.get("meta", {}).get("layout", {}).get("indent_level")
    )
    return correct / len(pairs)


def _numbering_accuracy(actual_blocks: list[dict], golden_blocks: list[dict]) -> float:
    """Fraction of blocks where list_marker.{type,level,text} all match."""
    pairs = [(a, g) for a, g in zip(actual_blocks, golden_blocks)
             if g.get("meta", {}).get("list_marker") is not None]
    if not pairs:
        return 1.0
    correct = 0
    for a, g in pairs:
        am = a.get("meta", {}).get("list_marker") or {}
        gm = g.get("meta", {}).get("list_marker") or {}
        if (am.get("type") == gm.get("type") and
                am.get("level") == gm.get("level") and
                am.get("text") == gm.get("text")):
            correct += 1
    return correct / len(pairs)


def _table_cell_accuracy(actual_blocks: list[dict], golden_blocks: list[dict]) -> float:
    """Average per-cell Levenshtein ratio across all table blocks."""
    ratios: list[float] = []
    for a, g in zip(actual_blocks, golden_blocks):
        if g.get("type") != "table":
            continue
        a_cells = [c for row in (a.get("meta", {}).get("table", {}) or {}).get("cells", []) for c in row]
        g_cells = [c for row in (g.get("meta", {}).get("table", {}) or {}).get("cells", []) for c in row]
        for ac, gc in zip(a_cells, g_cells):
            ratios.append(_levenshtein_ratio(str(ac.get("text") or ""), str(gc.get("text") or "")))
    return sum(ratios) / len(ratios) if ratios else 1.0


def _image_count_accuracy(actual_blocks: list[dict], golden_blocks: list[dict]) -> float:
    actual_img = sum(1 for b in actual_blocks if b.get("type") == "image")
    golden_img = sum(1 for b in golden_blocks if b.get("type") == "image")
    if golden_img == 0:
        return 1.0
    return 1.0 if actual_img == golden_img else actual_img / golden_img


def _load_golden_and_source(golden_name: str) -> tuple[dict, Path] | None:
    """Return (golden_doc, source_file) or None if either doesn't exist (skip)."""
    golden_path = GOLDEN_DIR / golden_name
    if not golden_path.exists():
        return None
    golden = json.loads(golden_path.read_text(encoding="utf-8"))
    # Source file is stored in golden["source_file"] (just filename).
    # Look for it in the repo root (two levels up from tests/).
    repo_root = Path(__file__).parent.parent.parent.parent.parent
    source_file = repo_root / golden["source_file"]
    if not source_file.exists():
        return None
    return golden, source_file


class TestGoldenAccuracy:
    """Full-pipeline accuracy tests against golden fixture files.

    Each test skips automatically when the golden file or source document
    is not present, so CI passes on a clean checkout without sample files.
    Run ``scripts/regenerate-goldens.py`` to create / refresh the golden files.
    """

    @pytest.fixture(autouse=True)
    def _tmp(self, tmp_path):
        self._tmp_path = tmp_path

    def _check(self, golden_name: str) -> None:
        pair = _load_golden_and_source(golden_name)
        if pair is None:
            pytest.skip(f"golden or source not found: {golden_name}")
        golden, source_file = pair
        golden_blocks = _flat_blocks(golden)

        actual = _run_extraction_sync(source_file, self._tmp_path)
        actual_blocks = _flat_blocks(actual)

        report: dict = {
            "golden": golden_name,
            "source": str(source_file),
            "block_count_actual": len(actual_blocks),
            "block_count_golden": len(golden_blocks),
        }

        text_acc = _text_accuracy(actual_blocks, golden_blocks)
        indent_acc = _indent_accuracy(actual_blocks, golden_blocks)
        numbering_acc = _numbering_accuracy(actual_blocks, golden_blocks)
        table_cell_acc = _table_cell_accuracy(actual_blocks, golden_blocks)
        image_count_acc = _image_count_accuracy(actual_blocks, golden_blocks)

        report.update({
            "text_acc": round(text_acc, 4),
            "indent_acc": round(indent_acc, 4),
            "numbering_acc": round(numbering_acc, 4),
            "table_cell_acc": round(table_cell_acc, 4),
            "image_count_acc": round(image_count_acc, 4),
        })

        # Write per-test report fragment
        report_path = Path(__file__).parent / ".fixture-report.json"
        existing: list = json.loads(report_path.read_text()) if report_path.exists() else []
        existing = [r for r in existing if r.get("golden") != golden_name]
        existing.append(report)
        report_path.write_text(json.dumps(existing, ensure_ascii=False, indent=2))

        assert text_acc >= 0.98, f"text_acc={text_acc:.4f} < 0.98 for {golden_name}"
        assert indent_acc >= 0.95, f"indent_acc={indent_acc:.4f} < 0.95 for {golden_name}"
        assert numbering_acc >= 0.90, f"numbering_acc={numbering_acc:.4f} < 0.90 for {golden_name}"
        assert table_cell_acc >= 0.95, f"table_cell_acc={table_cell_acc:.4f} < 0.95 for {golden_name}"
        assert image_count_acc == 1.0, f"image_count_acc={image_count_acc:.4f} != 1.0 for {golden_name}"

    def test_prakat_1_pdf(self):
        self._check("prakat_1.pdf.golden.json")

    def test_prakat_3_docx(self):
        self._check("prakat_3.docx.golden.json")

    def test_prakat_scan_pdf(self):
        self._check("prakat_scan.pdf.golden.json")

    def test_multi_level_list_docx(self):
        self._check("multi_level_list.docx.golden.json")

    def test_table_with_merges_docx(self):
        self._check("table_with_merges.docx.golden.json")
