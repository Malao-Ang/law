import subprocess
import zipfile
from pathlib import Path

import pytest

from app.services.doc_converter import ConversionResult, DocConversionError, DocConverter


def _write_fake_docx(path: Path) -> None:
    with zipfile.ZipFile(path, "w") as archive:
        archive.writestr("[Content_Types].xml", "<Types/>")


def test_converts_simple_doc_to_docx(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    source = tmp_path / "sample.doc"
    source.write_bytes(bytes.fromhex("D0CF11E0A1B11AE1") + b"legacy-doc")
    generated = tmp_path / "sample.docx"
    calls: list[list[str]] = []

    def _fake_run(command: list[str], **kwargs: object) -> subprocess.CompletedProcess[str]:
        calls.append(command)
        if "--version" in command:
            return subprocess.CompletedProcess(command, 0, stdout="LibreOffice 24.2.3.2\n", stderr="")

        _write_fake_docx(generated)
        return subprocess.CompletedProcess(command, 0, stdout="convert /tmp/sample.doc", stderr="")

    monkeypatch.setattr("app.services.doc_converter.subprocess.run", _fake_run)

    converter = DocConverter("soffice", 60)
    result = converter.convert(source, "doc-test-001")

    assert isinstance(result, ConversionResult)
    assert result.output_path == tmp_path / "converted.docx"
    assert result.output_path.exists()
    with zipfile.ZipFile(result.output_path) as archive:
        assert "[Content_Types].xml" in archive.namelist()
    assert result.duration_ms >= 0
    assert result.exit_code == 0
    assert result.soffice_version == "LibreOffice 24.2.3.2"
    assert any(arg.startswith("-env:UserInstallation=file:///tmp/uno-doc-test-001") for arg in calls[0])


def test_isolated_user_profile_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    first = tmp_path / "first.doc"
    second = tmp_path / "second.doc"
    first.write_bytes(bytes.fromhex("D0CF11E0A1B11AE1"))
    second.write_bytes(bytes.fromhex("D0CF11E0A1B11AE1"))
    commands: list[list[str]] = []

    def _fake_run(command: list[str], **kwargs: object) -> subprocess.CompletedProcess[str]:
        commands.append(command)
        if "--version" in command:
            return subprocess.CompletedProcess(command, 0, stdout="LibreOffice 24.2.3.2\n", stderr="")

        source = Path(command[-1])
        _write_fake_docx(source.with_suffix(".docx"))
        return subprocess.CompletedProcess(command, 0, stdout="", stderr="")

    monkeypatch.setattr("app.services.doc_converter.subprocess.run", _fake_run)

    converter = DocConverter("soffice", 60)
    converter.convert(first, "doc-a")
    converter.convert(second, "doc-b")

    convert_commands = [command for command in commands if "--convert-to" in command]
    assert any(arg.startswith("-env:UserInstallation=file:///tmp/uno-doc-a") for arg in convert_commands[0])
    assert any(arg.startswith("-env:UserInstallation=file:///tmp/uno-doc-b") for arg in convert_commands[1])


def test_timeout_kills_soffice(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    source = tmp_path / "timeout.doc"
    source.write_bytes(bytes.fromhex("D0CF11E0A1B11AE1"))

    def _fake_run(command: list[str], **kwargs: object) -> subprocess.CompletedProcess[str]:
        raise subprocess.TimeoutExpired(command, timeout=60)

    monkeypatch.setattr("app.services.doc_converter.subprocess.run", _fake_run)

    converter = DocConverter("soffice", 60)

    with pytest.raises(DocConversionError, match="timed out after 60s"):
        converter.convert(source, "timeout-doc")


def test_nonzero_exit_raises(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    source = tmp_path / "broken.doc"
    source.write_bytes(bytes.fromhex("D0CF11E0A1B11AE1"))

    def _fake_run(command: list[str], **kwargs: object) -> subprocess.CompletedProcess[str]:
        return subprocess.CompletedProcess(command, 1, stdout="", stderr="General input/output error")

    monkeypatch.setattr("app.services.doc_converter.subprocess.run", _fake_run)

    converter = DocConverter("soffice", 60)

    with pytest.raises(DocConversionError, match="General input/output error"):
        converter.convert(source, "broken-doc")
