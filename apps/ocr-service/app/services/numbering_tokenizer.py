"""Regex-based numbering tokenizer for Thai legal documents.

Detects leading list/section markers in extracted block text — Word numbering
(`w:numPr`) covers most DOCX cases, but Thai legal documents very often type
the marker as literal text (e.g. ``มาตรา ๑``, ``ข้อ ๑.๑``, ``(ก)``).  This
tokenizer recognises those literal markers and emits a structured
``list_marker`` annotation so downstream renderers can indent/classify them
as list items.

Design rules:

* The marker text is **never removed** from the block's ``raw_text``.  Legal
  wording must round-trip verbatim through the pipeline.  ``list_marker``
  carries the *parsed* marker as a sibling annotation, including the exact
  ``raw_match`` slice for the UI to highlight.
* Patterns are tried in priority order; the first match wins.  Longer/more
  specific patterns come first so ``มาตรา ๑`` is not misclassified as a bare
  Thai numeral.
* Pure-stdlib ``re`` — no third-party dependency.
"""

from __future__ import annotations

import re
from typing import TypedDict


class ListMarker(TypedDict):
    text: str
    type: str
    level: int
    raw_match: str


# Thai digit range ๐-๙ (U+0E50–U+0E59) and ASCII 0-9 in one charset.
_DIGIT = r"[๐-๙0-9]"
_THAI_LETTER = r"[ก-ฮ]"

# Priority-ordered (most specific first).
_PATTERNS: list[tuple[re.Pattern[str], str]] = [
    # 1. มาตรา N or มาตรา N/M  (highest specificity — legal "section")
    (re.compile(rf"^(มาตรา\s+{_DIGIT}+(?:/{_DIGIT}+)?)\b"), "legal-มาตรา"),
    # 2. ข้อ N.N.N
    (re.compile(rf"^(ข้อ\s+{_DIGIT}+(?:\.{_DIGIT}+)*)\b"), "legal-ข้อ"),
    # 3. วรรค X (X may be a Thai number word like หนึ่ง/สอง/etc., a digit, or a Thai letter)
    (re.compile(rf"^(วรรค\s+\S+)"), "legal-วรรค"),
    # 4. Parenthesised marker: (๑) (1) (ก) (a)
    (re.compile(rf"^(\(({_DIGIT}+|{_THAI_LETTER}|[a-zA-Z])\))"), "paren"),
    # 5. Multi-level numeric: ๑.๑ or ๑.๑.๑ (must have at least one dot)
    (re.compile(rf"^({_DIGIT}+(?:\.{_DIGIT}+)+)\.?(?=\s|$)"), "multi"),
    # 6. Single-level numeric followed by a dot: ๑.  1.
    (re.compile(rf"^({_DIGIT}+)\.(?=\s|$)"), "single"),
    # 7. Bullet
    (re.compile(r"^([•●○◦▪▫■□◆▶►])"), "bullet"),
]


def _marker_type_and_level(raw_match: str, kind: str) -> tuple[str, int]:
    """Resolve final ``type`` and ``level`` from the matched kind."""
    if kind in {"legal-มาตรา"}:
        return "legal-มาตรา", 1
    if kind == "legal-ข้อ":
        # Dot count + 1 = depth (ข้อ ๑ → 1, ข้อ ๑.๑ → 2)
        number_part = raw_match.split(maxsplit=1)[1] if " " in raw_match else ""
        depth = number_part.count(".") + 1
        return "legal-ข้อ", max(1, min(6, depth))
    if kind == "legal-วรรค":
        return "legal-วรรค", 2
    if kind == "paren":
        return "paren", 3
    if kind in {"multi", "single"}:
        # Detect Thai vs ASCII digits to set type.
        is_thai = any("๐" <= ch <= "๙" for ch in raw_match)
        marker_type = "thai-numeral" if is_thai else "arabic"
        depth = raw_match.count(".") + 1 if kind == "multi" else 1
        return marker_type, max(1, min(6, depth))
    if kind == "bullet":
        return "bullet", 1
    return "arabic", 1


def detect_list_marker(text: str) -> ListMarker | None:
    """Return the parsed ``ListMarker`` for ``text`` or ``None`` if no marker
    is recognised at the start of the string.

    The match is anchored to the start; trailing/whitespace is ignored.
    """
    if not text:
        return None
    candidate = text.lstrip()
    if not candidate:
        return None

    for pattern, kind in _PATTERNS:
        m = pattern.match(candidate)
        if m is None:
            continue
        raw_match = m.group(1)
        marker_type, level = _marker_type_and_level(raw_match, kind)
        return {
            "text": raw_match,
            "type": marker_type,
            "level": level,
            "raw_match": raw_match,
        }
    return None


# Marker types that are top-level legal section headers — never retype a
# block to ``list_item`` based on these.  ``วรรค`` ("paragraph" in legal
# parlance) is a sub-unit; keep its existing type.
_SECTION_HEADER_MARKERS = {"legal-มาตรา", "legal-ข้อ"}


def annotate_blocks(blocks: list[dict]) -> None:
    """In-place: attach ``meta.list_marker`` to every block whose text starts
    with a recognised numbering marker.

    Re-typing rules:
    * Blocks already typed as ``list_item``, ``section_header``, ``title``,
      ``table``, ``image``, ``figure_caption`` keep their type.
    * Bare ``paragraph`` blocks with a numeric / paren / bullet marker are
      retyped to ``list_item``.
    * Bare ``paragraph`` blocks with a ``legal-มาตรา`` / ``legal-ข้อ`` marker
      are promoted to ``section_header`` so they render with heading styling
      and are skipped by list/indent rules.
    """
    for block in blocks:
        text = str(block.get("raw_text") or "")
        marker = detect_list_marker(text)
        if marker is None:
            continue
        meta = block.setdefault("meta", {})
        meta["list_marker"] = marker

        if block.get("type") != "paragraph":
            continue
        if marker["type"] in _SECTION_HEADER_MARKERS:
            block["type"] = "section_header"
        elif marker["type"] == "legal-วรรค":
            # Keep paragraph; the marker is informational only.
            continue
        else:
            block["type"] = "list_item"
