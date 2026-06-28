from pathlib import Path


# Pages with fewer characters than this threshold are treated as scanned.
# Thai characters are information-dense so 20 is a reasonable lower bound.
_TEXT_CHARS_THRESHOLD = 20
_ZIP_MAGIC = b"PK\x03\x04"
_CFBF_MAGIC = bytes.fromhex("D0CF11E0A1B11AE1")


def detect_file_type(file_path: Path) -> dict:
    """Return per-page classification for the given file.

    For DOCX files the result is always {"mode": "docx", "pages": {}}.

    For PDF files each page is independently classified as "text" or "scan"
    based on extractable character count and font presence.  The top-level
    "mode" key summarises the document:
      - "text"  – every page is text
      - "scan"  – every page is a scan
      - "mixed" – document contains both types

    Page indices in the "pages" dict are 0-based to match fitz page ordering.
    """
    suffix = file_path.suffix.lower()
    magic = _read_magic(file_path)

    if suffix in {".doc", ".docx"}:
        if magic.startswith(_ZIP_MAGIC):
            return {"mode": "docx", "pages": {}}
        if magic.startswith(_CFBF_MAGIC):
            return {"mode": "doc", "pages": {}}
        if suffix == ".docx":
            return {"mode": "docx", "pages": {}}
        if suffix == ".doc":
            raise ValueError("Unsupported or corrupt .doc file")

    if suffix != ".pdf":
        raise ValueError(f"Unsupported file format: {suffix!r}")

    return _classify_pdf_pages(file_path)


def _read_magic(file_path: Path) -> bytes:
    try:
        with file_path.open("rb") as handle:
            return handle.read(8)
    except OSError:
        return b""


def _classify_pdf_pages(file_path: Path) -> dict:
    import fitz

    doc = fitz.open(file_path)
    text_pages: list[int] = []
    scan_pages: list[int] = []

    for idx, page in enumerate(doc):
        if _page_is_text(page):
            text_pages.append(idx)
        else:
            scan_pages.append(idx)

    doc.close()

    if scan_pages and text_pages:
        mode = "mixed"
    elif scan_pages:
        mode = "pdf_scan"
    else:
        mode = "pdf_text"

    return {
        "mode": mode,
        "pages": {
            "text": text_pages,
            "scan": scan_pages,
        },
    }


def _page_is_text(page: object) -> bool:
    """A page is text if it has extractable fonts AND sufficient character count."""
    try:
        fonts = page.get_fonts()
        if not fonts:
            return False
        char_count = len(page.get_text("text").strip())
        return char_count >= _TEXT_CHARS_THRESHOLD
    except Exception:
        return False
