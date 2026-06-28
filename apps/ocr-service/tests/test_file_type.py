from pathlib import Path

import pytest

from app.utils.file_type import detect_file_type


def test_detect_file_type_docx() -> None:
    result = detect_file_type(Path("example.docx"))
    assert result["mode"] == "docx"
    assert result["pages"] == {}


def test_detect_file_type_unsupported_raises() -> None:
    with pytest.raises(ValueError, match="Unsupported"):
        detect_file_type(Path("document.txt"))


def test_detect_file_type_doc_magic(tmp_path: Path) -> None:
    file_path = tmp_path / "legacy.doc"
    file_path.write_bytes(bytes.fromhex("D0CF11E0A1B11AE1") + b"payload")

    result = detect_file_type(file_path)

    assert result["mode"] == "doc"
    assert result["pages"] == {}


def test_detect_file_type_doc_extension_with_zip_magic_reclassifies_to_docx(tmp_path: Path) -> None:
    file_path = tmp_path / "renamed.doc"
    file_path.write_bytes(b"PK\x03\x04zipdata")

    result = detect_file_type(file_path)

    assert result["mode"] == "docx"
    assert result["pages"] == {}


def test_detect_file_type_docx_extension_with_doc_magic_reclassifies_to_doc(tmp_path: Path) -> None:
    file_path = tmp_path / "renamed.docx"
    file_path.write_bytes(bytes.fromhex("D0CF11E0A1B11AE1") + b"payload")

    result = detect_file_type(file_path)

    assert result["mode"] == "doc"
    assert result["pages"] == {}


def test_detect_file_type_corrupt_doc_raises(tmp_path: Path) -> None:
    file_path = tmp_path / "broken.doc"
    file_path.write_bytes(b"not-a-doc")

    with pytest.raises(ValueError, match="corrupt \\.doc"):
        detect_file_type(file_path)
