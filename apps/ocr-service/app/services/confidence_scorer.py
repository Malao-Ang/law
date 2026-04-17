"""Signal-based confidence scoring for extracted document blocks.

Each scorer takes the block dict (as returned by the extractor, before
normalisation) and returns a float in [0.0, 1.0] together with a list of
flag strings that describe which signals fired.

Signals deliberately use measurable, deterministic properties of the extracted
text — not heuristics that require a language model.  This makes them fast,
debuggable, and consistent across document types.
"""

from __future__ import annotations

import re


# U+FFFD – Unicode replacement character, inserted when a codepoint cannot be decoded.
_REPLACEMENT_CHAR = "\ufffd"

# Thai Unicode block: U+0E00 – U+0E7F
_THAI_RANGE = re.compile(r"[\u0e00-\u0e7f]")

# Hyphen at end of line, suggesting OCR split a word across lines.
_LINE_END_HYPHEN = re.compile(r"-\n")


def score_text_pdf_block(text: str) -> tuple[float, list[str]]:
    """Confidence signals for text extracted via PyMuPDF from a text PDF."""
    flags: list[str] = []
    confidence = 0.99

    if not text.strip():
        return 0.0, ["empty_block"]

    if _REPLACEMENT_CHAR in text:
        confidence -= 0.30
        flags.append("unicode_replacement_char")

    thai_chars = len(_THAI_RANGE.findall(text))
    total_chars = len(text.replace(" ", ""))
    if total_chars > 10 and thai_chars / total_chars < 0.20:
        confidence -= 0.15
        flags.append("low_thai_ratio")

    if _LINE_END_HYPHEN.search(text):
        confidence -= 0.05
        flags.append("line_end_hyphen")

    # Detect possible custom CMap encoding: Thai expected but ASCII-range codepoints dominate.
    ascii_chars = sum(1 for c in text if ord(c) < 0x0100 and c.isalpha())
    if total_chars > 10 and ascii_chars / total_chars > 0.50 and thai_chars == 0:
        confidence -= 0.20
        flags.append("encoding_fallback_suspected")

    return max(0.0, min(1.0, confidence)), flags


def score_ocr_block(easyocr_confidence: float) -> tuple[float, list[str]]:
    """Confidence for an OCR block using EasyOCR's native per-character mean."""
    flags: list[str] = []
    confidence = max(0.0, min(1.0, easyocr_confidence))

    if confidence < 0.70:
        flags.append("low_ocr_confidence")

    return confidence, flags


def score_docx_block(text: str, xml_parse_error: bool = False, has_tracked_changes: bool = False) -> tuple[float, list[str]]:
    """Confidence signals for text extracted from a DOCX paragraph."""
    flags: list[str] = []
    confidence = 0.98

    if not text.strip():
        return 0.0, ["empty_block"]

    if xml_parse_error:
        confidence -= 0.40
        flags.append("xml_parse_error")

    if has_tracked_changes:
        confidence -= 0.15
        flags.append("tracked_changes_present")

    if _REPLACEMENT_CHAR in text:
        confidence -= 0.30
        flags.append("unicode_replacement_char")

    return max(0.0, min(1.0, confidence)), flags
