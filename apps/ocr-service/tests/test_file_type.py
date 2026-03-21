from pathlib import Path

from app.utils.file_type import detect_file_type


def test_detect_file_type_docx() -> None:
    assert detect_file_type(Path("example.docx")) == "docx"
