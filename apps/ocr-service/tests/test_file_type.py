from pathlib import Path

from app.utils.file_type import detect_file_type


def test_detect_file_type_docx() -> None:
    result = detect_file_type(Path("example.docx"))
    assert result["mode"] == "docx"
    assert result["pages"] == {}


def test_detect_file_type_unsupported_raises() -> None:
    import pytest
    with pytest.raises(ValueError, match="Unsupported"):
        detect_file_type(Path("document.txt"))
