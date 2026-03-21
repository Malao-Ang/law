from pathlib import Path


def detect_file_type(file_path: Path) -> str:
    suffix = file_path.suffix.lower()

    if suffix == ".docx":
        return "docx"

    if suffix != ".pdf":
        raise ValueError("Unsupported file format")

    import fitz

    document = fitz.open(file_path)
    page_count = max(1, document.page_count)
    text_chars = 0

    for page in document:
        text_chars += len(page.get_text("text").strip())

    document.close()

    average_chars = text_chars / page_count
    return "pdf_text" if average_chars >= 40 else "pdf_scan"
