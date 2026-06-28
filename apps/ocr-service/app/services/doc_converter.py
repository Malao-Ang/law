from __future__ import annotations

import shutil
import subprocess
import time
from dataclasses import dataclass
from pathlib import Path
from tempfile import gettempdir


class DocConversionError(RuntimeError):
    pass


@dataclass(slots=True)
class ConversionResult:
    output_path: Path
    duration_ms: int
    exit_code: int
    soffice_version: str


class DocConverter:
    def __init__(self, soffice_binary: str, timeout_seconds: int) -> None:
        self.soffice_binary = soffice_binary
        self.timeout_seconds = timeout_seconds
        self._soffice_version: str | None = None

    def convert(self, source: Path, document_id: str) -> ConversionResult:
        profile_dir = Path(gettempdir()) / f"uno-{document_id}"
        generated_path = source.with_suffix(".docx")
        output_path = source.parent / "converted.docx"

        if output_path.exists():
            output_path.unlink()
        if generated_path.exists() and generated_path != output_path:
            generated_path.unlink()

        command = [
            self.soffice_binary,
            "--headless",
            "--norestore",
            "--nofirststartwizard",
            f"-env:UserInstallation={profile_dir.resolve().as_uri()}",
            "--convert-to",
            "docx",
            "--outdir",
            str(source.parent),
            str(source),
        ]

        started_at = time.monotonic()

        try:
            completed = subprocess.run(
                command,
                capture_output=True,
                text=True,
                timeout=self.timeout_seconds,
                check=False,
            )
        except subprocess.TimeoutExpired as exc:
            raise DocConversionError(
                f"doc conversion timed out after {self.timeout_seconds}s"
            ) from exc
        except FileNotFoundError as exc:
            raise DocConversionError(
                f"doc conversion failed: {self.soffice_binary} not found"
            ) from exc
        finally:
            shutil.rmtree(profile_dir, ignore_errors=True)

        duration_ms = round((time.monotonic() - started_at) * 1000)

        if completed.returncode != 0:
            stderr = (completed.stderr or completed.stdout or "").strip()
            tail = stderr.splitlines()[-1] if stderr else "unknown LibreOffice error"
            raise DocConversionError(f"doc conversion failed: {tail}")

        if generated_path.exists() and generated_path != output_path:
            generated_path.replace(output_path)
        elif generated_path == output_path:
            pass

        if not output_path.exists():
            raise DocConversionError("doc conversion produced no output")

        return ConversionResult(
            output_path=output_path,
            duration_ms=duration_ms,
            exit_code=completed.returncode,
            soffice_version=self._get_soffice_version(),
        )

    def _get_soffice_version(self) -> str:
        if self._soffice_version is not None:
            return self._soffice_version

        try:
            completed = subprocess.run(
                [self.soffice_binary, "--version"],
                capture_output=True,
                text=True,
                timeout=min(self.timeout_seconds, 10),
                check=False,
            )
            version = (completed.stdout or completed.stderr or "").strip()
        except Exception:
            version = ""

        self._soffice_version = version or self.soffice_binary
        return self._soffice_version
