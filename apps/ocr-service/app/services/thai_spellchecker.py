"""Thai spell-checking for OCR-extracted legal documents.

Wraps ``pythainlp.spell.NorvigSpellChecker`` with a custom dictionary of
legal/government vocabulary so that specialist terms are never flagged as
misspellings.

Design rules:
* **Advisory only** — suggestions are written to ``meta.spell_suggestions``;
  they are never auto-applied.  Legal text must round-trip verbatim.
* **Gated by confidence** — only called for blocks that already need review
  (low OCR confidence or existing flags).  Do not slow down high-confidence
  blocks.
* **Legal terms preserved** — terms in ``legal_terms.txt`` are added to the
  checker's dictionary and will never appear as suggestions.
"""

from __future__ import annotations

import re
from pathlib import Path

_LEGAL_TERMS_PATH = Path(__file__).parent.parent / "resources" / "legal_terms.txt"

_THAI_WORD_RE = re.compile(r"[฀-๿]+")


def _load_legal_terms() -> frozenset[str]:
    """Load terms from ``legal_terms.txt``, skipping comment lines."""
    if not _LEGAL_TERMS_PATH.exists():
        return frozenset()
    terms: set[str] = set()
    for line in _LEGAL_TERMS_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            terms.add(line)
    return frozenset(terms)


_LEGAL_TERMS: frozenset[str] = _load_legal_terms()


class ThaiSpellChecker:
    """Lazy-initialised wrapper around ``pythainlp.spell.NorvigSpellChecker``.

    Instantiation is deferred to the first call so that import errors or missing
    model data do not crash the service at startup.
    """

    def __init__(self) -> None:
        self._checker = None
        self._tokenizer = None
        self._available: bool | None = None

    def is_available(self) -> bool:
        if self._available is None:
            self._available = self._try_init()
        return self._available

    def _try_init(self) -> bool:
        try:
            from pythainlp.spell import NorvigSpellChecker  # type: ignore
            from pythainlp.tokenize import word_tokenize  # type: ignore
            self._checker = NorvigSpellChecker(custom_dict=list(_LEGAL_TERMS))
            self._tokenizer = word_tokenize
            return True
        except Exception:
            return False

    def check(self, text: str) -> list[dict]:
        """Return a list of spell-suggestion dicts for ``text``.

        Each dict has ``token``, ``suggestion``, ``confidence``, and ``offset``
        — matching the ``meta.spell_suggestions`` schema.

        Returns ``[]`` when spellcheck is unavailable or the text is clean.
        """
        return self.bulk_check([text])[0]

    def bulk_check(self, texts: list[str]) -> list[list[dict]]:
        """Return spell suggestions for each input text, preserving order."""
        results: list[list[dict]] = [[] for _ in texts]
        if not texts or not self.is_available():
            return results

        assert self._checker is not None
        assert self._tokenizer is not None

        candidate_cache: dict[str, list[str]] = {}

        for text_index, text in enumerate(texts):
            if not text.strip():
                continue

            suggestions: list[dict] = []
            offset = 0
            tokens: list[str] = self._tokenizer(text, engine="newmm")

            for token in tokens:
                token_start = text.find(token, offset)
                if token_start < 0:
                    token_start = offset
                token_offset = token_start

                # Only check Thai-script tokens; skip punctuation, numbers, spaces.
                if _THAI_WORD_RE.fullmatch(token) and token not in _LEGAL_TERMS:
                    if token not in candidate_cache:
                        try:
                            candidate_cache[token] = list(self._checker.spell(token))
                        except Exception:
                            candidate_cache[token] = []

                    candidates = candidate_cache[token]
                    if candidates and candidates[0] != token:
                        suggestions.append({
                            "token": token,
                            "suggestion": candidates[0],
                            "confidence": round(1.0 / max(len(candidates), 1), 3),
                            "offset": token_offset,
                        })

                offset = token_start + len(token)

            results[text_index] = suggestions

        return results


# Module-level singleton — shared across requests.
_checker_instance: ThaiSpellChecker | None = None


def get_spell_checker() -> ThaiSpellChecker:
    global _checker_instance
    if _checker_instance is None:
        _checker_instance = ThaiSpellChecker()
    return _checker_instance
