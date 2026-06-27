"""
Regenerate golden fixture files for accuracy regression tests.

Usage:
    python scripts/regenerate-goldens.py
    python scripts/regenerate-goldens.py --fixture prakat_1.pdf.golden.json
    python scripts/regenerate-goldens.py --fixture prakat_1.pdf.golden.json --fixture prakat_3.docx.golden.json

Then hand-edit the generated files to verify correctness before committing.

Run inside the container:
    docker compose exec ocr-service python scripts/regenerate-goldens.py
"""

import argparse
import json
import sys
from pathlib import Path

# Allow running from repo root or from ocr-service dir
_HERE = Path(__file__).resolve().parent
_OCR_SERVICE = _HERE.parent
sys.path.insert(0, str(_OCR_SERVICE))

GOLDEN_DIR = _OCR_SERVICE / "tests" / "fixtures" / "golden"

# Map fixture name → source file (relative to repo root)
FIXTURE_MAP: dict[str, str] = {
    "prakat_1.pdf.golden.json": "ประกาศ (1).pdf",
    "prakat_3.docx.golden.json": "ประกาศ (3).docx",
    "prakat_scan.pdf.golden.json": "ประกาศ (1).pdf",   # rasterised version
    "multi_level_list.docx.golden.json": None,          # synthetic — must be created manually
    "table_with_merges.docx.golden.json": None,         # synthetic — must be created manually
}

# Repo root is five levels up from this script (scripts/ → ocr-service/ → apps/ → repo)
_REPO_ROOT = _OCR_SERVICE.parent.parent


def _find_source(filename: str) -> Path | None:
    if filename is None:
        return None
    candidate = _REPO_ROOT / filename
    return candidate if candidate.exists() else None


def _run_extraction(source_file: Path, golden_name: str, tmp_path: Path) -> dict:
    from app.services.ai_corrector import MockAICorrector
    from app.services.block_builder import build_document_output
    from app.services.docling_service import DoclingService
    from app.services.ocr_pipeline import get_ocr_pipeline
    from app.services.thai_normalizer import normalize_text
    from app.utils.file_type import detect_file_type

    document_id = f"regen_{Path(golden_name).stem}"
    classification = detect_file_type(source_file)
    mode = classification["mode"]

    # Force scan mode for the prakat_scan fixture
    if "scan" in golden_name:
        mode = "pdf_scan"

    docling_service = DoclingService(data_root=tmp_path)
    ocr_pipeline = get_ocr_pipeline(data_root=tmp_path)

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


def regenerate(fixture_names: list[str]) -> None:
    import tempfile

    GOLDEN_DIR.mkdir(parents=True, exist_ok=True)
    results: list[tuple[str, str]] = []   # (name, status)

    for name in fixture_names:
        source_name = FIXTURE_MAP.get(name)
        if source_name is None:
            print(f"  SKIP  {name}  (synthetic fixture — create manually)")
            results.append((name, "skipped"))
            continue

        source_file = _find_source(source_name)
        if source_file is None:
            print(f"  MISS  {name}  (source '{source_name}' not found in repo root)")
            results.append((name, "source_missing"))
            continue

        print(f"  RUN   {name}  ← {source_file.name}")
        try:
            with tempfile.TemporaryDirectory() as tmp:
                output = _run_extraction(source_file, name, Path(tmp))
            out_path = GOLDEN_DIR / name
            out_path.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding="utf-8")
            print(f"  OK    {name}  ({len([b for p in output.get('pages',[]) for b in p.get('blocks',[])])} blocks)")
            results.append((name, "ok"))
        except Exception as exc:
            print(f"  FAIL  {name}  {exc}")
            results.append((name, f"error: {exc}"))

    print()
    ok = sum(1 for _, s in results if s == "ok")
    skipped = sum(1 for _, s in results if s == "skipped")
    failed = sum(1 for _, s in results if s not in {"ok", "skipped", "source_missing"})
    missing = sum(1 for _, s in results if s == "source_missing")
    print(f"Generated: {ok}  Skipped: {skipped}  Missing source: {missing}  Failed: {failed}")
    if ok:
        print(f"\nGolden files written to: {GOLDEN_DIR}")
        print("Review and hand-edit them before committing.")


def main() -> None:
    parser = argparse.ArgumentParser(description="Regenerate golden fixture files.")
    parser.add_argument(
        "--fixture",
        action="append",
        dest="fixtures",
        metavar="NAME",
        help="Fixture file name (e.g. prakat_1.pdf.golden.json). Repeatable. Default: all.",
    )
    args = parser.parse_args()

    targets = args.fixtures or list(FIXTURE_MAP.keys())
    unknown = [f for f in targets if f not in FIXTURE_MAP]
    if unknown:
        parser.error(f"Unknown fixture(s): {', '.join(unknown)}. Known: {', '.join(FIXTURE_MAP)}")

    print(f"Regenerating {len(targets)} fixture(s)...\n")
    regenerate(targets)


if __name__ == "__main__":
    main()
