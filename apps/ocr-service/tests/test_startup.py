from __future__ import annotations

import importlib
import threading
from types import SimpleNamespace

from fastapi.testclient import TestClient


def test_startup_prewarms_spellchecker(monkeypatch) -> None:
    called = threading.Event()

    def fake_is_available(self: object) -> bool:
        called.set()
        return True

    monkeypatch.setattr(
        "app.services.thai_spellchecker.ThaiSpellChecker.is_available",
        fake_is_available,
    )
    monkeypatch.setattr(
        "app.services.ocr_pipeline.get_ocr_pipeline",
        lambda data_root=None: SimpleNamespace(device="cpu"),
    )
    monkeypatch.setattr("app.services.ocr_pipeline.warmup_ocr", lambda: None)

    import app.main as main

    reloaded_main = importlib.reload(main)
    with TestClient(reloaded_main.app):
        assert called.wait(timeout=2.0)
