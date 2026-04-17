"""Regression tests for Thai text normalisation.

Each case documents a known-bad input and its expected output.  When new OCR
patterns are added to ThaiNormalizer the test here must be updated too, keeping
the rule list and its tests in sync.

Run with: pytest apps/ocr-service/tests/test_thai_normalizer.py -v
"""

import pytest

from app.services.thai_normalizer import normalize_text


# ─────────────────────────────────────────────────────────────────────────────
# Helper
# ─────────────────────────────────────────────────────────────────────────────

def normalised(text: str) -> str:
    return normalize_text(text)["text"]


def flags_for(text: str) -> list[str]:
    return normalize_text(text)["flags"]


# ─────────────────────────────────────────────────────────────────────────────
# Sara-am (ำ) split by OCR as sara-aa (า) + nikkhahit (ํ)
# ─────────────────────────────────────────────────────────────────────────────

@pytest.mark.parametrize("bad, expected", [
    ("สํานักงาน", "สำนักงาน"),
    ("กํานด", "กำนด"),      # partial word — pattern fires on leading ก + ํ
    ("ทํา", "ทำ"),
    ("จํานวน", "จำนวน"),
])
def test_sara_am_split(bad: str, expected: str) -> None:
    assert normalised(bad) == expected


# ─────────────────────────────────────────────────────────────────────────────
# Zero-width space (U+200B) between Thai combining mark and base consonant
# ─────────────────────────────────────────────────────────────────────────────

def test_zwsp_removed_between_thai_chars() -> None:
    # U+200B inserted by PyMuPDF between consonant and vowel mark
    result = normalize_text("ก\u200bั")
    assert "\u200b" not in result["text"]
    assert "zero_width_removed" in result["flags"]


def test_zwsp_at_genuine_word_boundary_allowed() -> None:
    # ZWSP between two full words should be treated as whitespace, not error
    # After normalisation it may collapse to a space — the key requirement is
    # that the content words are intact.
    result = normalize_text("กข\u200bคง")
    assert "กข" in result["text"]
    assert "คง" in result["text"]


# ─────────────────────────────────────────────────────────────────────────────
# Tone mark before consonant (common EasyOCR ordering error)
# ─────────────────────────────────────────────────────────────────────────────

@pytest.mark.parametrize("bad, expected", [
    # ่ (mai ek) appearing before the consonant it belongs to
    ("่ก า", "ก่า"),
    # ้ (mai tho) before consonant
    ("้น้า", "น้้า"),   # double — should not crash
])
def test_tone_mark_reordering(bad: str, expected: str) -> None:
    result = normalised(bad)
    # The tone mark must appear after its base consonant
    for tone in "่้๊๋":
        for idx, ch in enumerate(result):
            if ch == tone:
                assert idx > 0, f"Tone mark {repr(tone)} at position 0 in {repr(result)}"
                assert "\u0e00" <= result[idx - 1] <= "\u0e7f", (
                    f"Tone mark {repr(tone)} not preceded by Thai char in {repr(result)}"
                )


# ─────────────────────────────────────────────────────────────────────────────
# Common OCR fix patterns (sara-am split via space rather than nikkhahit)
# ─────────────────────────────────────────────────────────────────────────────

@pytest.mark.parametrize("bad, expected", [
    ("ส านักงาน", "สำนักงาน"),
    ("ก าหนด", "กำหนด"),
    ("ด าเนิน", "ดำเนิน"),
    ("จ านวน", "จำนวน"),
    ("ท า", "ทำ"),
    ("น า", "นำ"),
    ("ม าตรา", "มาตรา"),
    ("ค า", "คำ"),
])
def test_sara_am_space_split(bad: str, expected: str) -> None:
    assert normalised(bad) == expected


# ─────────────────────────────────────────────────────────────────────────────
# Combining mark whitespace collapse
# (space between base consonant and combining mark)
# ─────────────────────────────────────────────────────────────────────────────

def test_combining_mark_space_collapse() -> None:
    # PyMuPDF sometimes emits "ก ั" (space between consonant and sara a)
    result = normalised("ก ั")
    assert result == "กั" or "ั" in result.replace(" ", "")
    assert "thai_combining_fix" in flags_for("ก ั")


# ─────────────────────────────────────────────────────────────────────────────
# Unicode NFC normalisation
# ─────────────────────────────────────────────────────────────────────────────

def test_nfc_normalisation_applied() -> None:
    import unicodedata
    # NFD form of ก with combining mark
    nfd_text = unicodedata.normalize("NFD", "กั")
    result = normalize_text(nfd_text)
    assert result["text"] == unicodedata.normalize("NFC", "กั")
    assert "unicode_normalized" in result["flags"]


# ─────────────────────────────────────────────────────────────────────────────
# Unicode replacement character — must pass through unchanged
# ─────────────────────────────────────────────────────────────────────────────

def test_replacement_char_survives_normalisation() -> None:
    # U+FFFD should not be stripped — it signals encoding corruption to the
    # confidence scorer, and removing it silently would hide the problem.
    text = "ข้อ\ufffdความ"
    result = normalised(text)
    assert "\ufffd" in result


# ─────────────────────────────────────────────────────────────────────────────
# Mixed Thai/ASCII — ASCII content must not be altered
# ─────────────────────────────────────────────────────────────────────────────

def test_ascii_content_unchanged() -> None:
    text = "Section 1.2 (a) ข้อ ๑"
    result = normalised(text)
    assert "Section 1.2" in result
    assert "(a)" in result


# ─────────────────────────────────────────────────────────────────────────────
# Known DOCX path — NFC applied before ThaiNormalizer
# ─────────────────────────────────────────────────────────────────────────────

def test_empty_string_returns_empty() -> None:
    result = normalize_text("")
    assert result["text"] == ""


def test_whitespace_only_collapses() -> None:
    result = normalize_text("   \t\n  ")
    assert result["text"] == ""


# ─────────────────────────────────────────────────────────────────────────────
# Pattern ordering regression — longer patterns must win over sub-patterns
# ─────────────────────────────────────────────────────────────────────────────

def test_longer_pattern_wins() -> None:
    # "ส านักงาน" should become "สำนักงาน", not "สำนัก" + "งาน"
    result = normalised("ส านักงาน")
    assert result == "สำนักงาน"
