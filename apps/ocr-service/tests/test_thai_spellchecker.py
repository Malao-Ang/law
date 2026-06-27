from __future__ import annotations

from app.services.thai_spellchecker import ThaiSpellChecker


class FakeChecker:
    def __init__(self, suggestions: dict[str, list[str]]) -> None:
        self.suggestions = suggestions
        self.calls: list[str] = []

    def spell(self, token: str) -> list[str]:
        self.calls.append(token)
        return self.suggestions.get(token, [token])


def _make_spellchecker(checker: FakeChecker, tokenizer: object | None = None) -> ThaiSpellChecker:
    spellchecker = ThaiSpellChecker()
    spellchecker._available = True
    spellchecker._checker = checker
    spellchecker._tokenizer = tokenizer or (lambda text, engine="newmm": text.split())
    return spellchecker


def test_bulk_check_returns_results_aligned_with_input_order() -> None:
    checker = FakeChecker({"ผิดดด": ["ผิด", "ผิดด"], "ถูก": ["ถูก"]})
    spellchecker = _make_spellchecker(checker)

    results = spellchecker.bulk_check(["ผิดดด ok", "ถูก", "ผิดดด"])

    assert len(results) == 3
    assert results[0] == [
        {
            "token": "ผิดดด",
            "suggestion": "ผิด",
            "confidence": 0.5,
            "offset": 0,
        }
    ]
    assert results[1] == []
    assert results[2][0]["token"] == "ผิดดด"


def test_bulk_check_spells_duplicate_thai_tokens_once_across_batch() -> None:
    checker = FakeChecker({"ผิดดด": ["ผิด"]})
    spellchecker = _make_spellchecker(checker)

    results = spellchecker.bulk_check(["ผิดดด ผิดดด", "ผิดดด"])

    assert [len(item) for item in results] == [2, 1]
    assert checker.calls == ["ผิดดด"]


def test_bulk_check_empty_or_whitespace_text_returns_empty_suggestions() -> None:
    checker = FakeChecker({"ผิดดด": ["ผิด"]})
    tokenizer_calls: list[str] = []

    def tokenizer(text: str, engine: str = "newmm") -> list[str]:
        tokenizer_calls.append(text)
        return text.split()

    spellchecker = _make_spellchecker(checker, tokenizer)

    assert spellchecker.bulk_check(["", "   ", "\n\t"]) == [[], [], []]
    assert checker.calls == []
    assert tokenizer_calls == []


def test_check_delegates_to_bulk_check() -> None:
    checker = FakeChecker({"ผิดดด": ["ผิด"]})
    spellchecker = _make_spellchecker(checker)

    assert spellchecker.check("ผิดดด") == spellchecker.bulk_check(["ผิดดด"])[0]
