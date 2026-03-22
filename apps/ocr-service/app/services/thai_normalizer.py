import re
import unicodedata
from typing import TypedDict

try:
    from pythainlp.util import normalize as pythainlp_normalize
except Exception:  # pragma: no cover - optional dependency guard
    pythainlp_normalize = None


class NormalizeResult(TypedDict):
    text: str
    flags: list[str]


# ---------------------------------------------------------------------------
# OCR fix table — sorted longest-first so longer patterns win over sub-patterns
# ---------------------------------------------------------------------------
_THAI_OCR_FIXES_RAW: dict[str, str] = {
    "ํา": "ำ",
    "บญญตั ิ": "บัญญัติ",
    "ม าตรา": "มาตรา",

    # ── sara am (ำ) — explicit common words ─────────────────────────────────
    # Longer compound patterns first
    "ส านักงาน": "สำนักงาน",
    "ส านักหอสมุด": "สำนักหอสมุด",
    "ส านัก": "สำนัก",
    "ก าหนด": "กำหนด",
    "อ านวย": "อำนวย",
    "ด าเนิน": "ดำเนิน",
    "ด าริ": "ดำริ",
    "ช าระ": "ชำระ",
    "จ านวน": "จำนวน",
    "จ าเป็น": "จำเป็น",
    "จ าลอง": "จำลอง",
    "จ าก": "จำก",    # จากัด ฯลฯ
    "ท าการ": "ทำการ",
    "ท างาน": "ทำงาน",
    "ท า": "ทำ",
    "น าเสนอ": "นำเสนอ",
    "น า": "นำ",
    "ถ่ายท า": "ถ่ายทำ",
    "ส า": "สำ",
    "ก า": "กำ",
    "อ า": "อำ",
    "ด า": "ดำ",
    "ช า": "ชำ",
    "จ า": "จำ",
    "ค า": "คำ",
    "ต า": "ตำ",
    "ม า": "มำ",
    "ห า": "หำ",
    "พ า": "พำ",
    "บ า": "บำ",
    "ย า": "ยำ",
    "ร า": "รำ",
    "ล า": "ลำ",
    "ว า": "วำ",

    # ── misplaced combining-vowel sequences (not valid Thai → safe) ──────────
    "รปู": "รูป",
    "ปฏิบตั งิ": "ปฏิบัติง",   # MUST precede "ปฏิบตั"
    "ปฏิบตั": "ปฏิบัต",
    "อตั ราค่า": "อัตราค่า",
    "อตั รา": "อัตรา",
    "อตั": "อัต",
    "นกั": "นัก",
    "มกั": "มัก",

    # ── PDF-specific glyph-split fixes ───────────────────────────────────────
    "ร้อู อน": "รู้ออน",   # เรียนรู้ออนไลน์ encoded as เรียนร้อู อนไลน์
    "ชวั่": "ชั่ว",

    # ── sara-aa split in safe compound words ─────────────────────────────────
    "ชั่ว โมง": "ชั่วโมง",
    "รูป แบบ": "รูปแบบ",
    "รปแบบ": "รูปแบบ",
    "ง าน": "งาน",          # งาน — งำน doesn't exist
    "การ ": "การ ",          # avoid sara-aa→sara-am on การ
    "บรกิ าร": "บริการ",
}

THAI_OCR_FIXES: dict[str, str] = dict(
    sorted(_THAI_OCR_FIXES_RAW.items(), key=lambda kv: -len(kv[0]))
)


def _apply_ocr_fixes(text: str) -> str:
    for source, target in THAI_OCR_FIXES.items():
        text = text.replace(source, target)
    return text


COMBINING_MARKS = "ิีึืุูั็่้๊๋์ํฺ๎"

ZERO_WIDTH_CHARS = {
    "\u200b", "\u200c", "\u200d", "\u2060", "\ufeff",
}

# _AFTER_BASE_PATTERN: consonant + space(s) + combining mark → remove space
_AFTER_BASE_PATTERN = re.compile(rf"([ก-ฮ])\s+([{COMBINING_MARKS}])")

_MARK_BEFORE_BASE_PATTERN = re.compile(
    rf"(?<![ก-ฮ])([{COMBINING_MARKS}])\s+([ก-ฮ])"
)

_MARK_MARK_PATTERN = re.compile(rf"([{COMBINING_MARKS}])\s+([{COMBINING_MARKS}])")
_LEADING_MARK_PATTERN = re.compile(rf"^[{COMBINING_MARKS}]+")
_INTERNAL_DANGLING_PATTERN = re.compile(rf"(\s)[{COMBINING_MARKS}]+(?=\s)")


def _remove_zero_width(text: str) -> str:
    return "".join(ch for ch in text if ch not in ZERO_WIDTH_CHARS)


def _collapse_combining_whitespace(text: str) -> str:
    previous = None
    while previous != text:
        previous = text
        text = _AFTER_BASE_PATTERN.sub(r"\1\2", text)
        text = _MARK_BEFORE_BASE_PATTERN.sub(r"\1\2", text)
        text = _MARK_MARK_PATTERN.sub(r"\1\2", text)
    text = _LEADING_MARK_PATTERN.sub("", text)
    text = _INTERNAL_DANGLING_PATTERN.sub(r"\1", text)
    return text


def normalize_thai_vowels(text: str) -> str:
    """Fix Thai vowel/tone-mark encoding errors from PDF span splitting.

    Fix A: Tone marks (่ ้ ๊ ๋) cannot precede a space before a Thai *consonant*
           (ก-ฮ only — leading vowels เ แ โ ใ ไ are excluded because they
           start a new word and the preceding space is a legitimate boundary).
    Fix B: Same rule for ั and ็.
    Fix C: Nikhahit (ํ) + sara-aa (า) → sara-am (ำ).

    Note: The generic "consonant + space + า → ำ" regex is ABSENT because it
    creates false positives on sara-aa words like "งาน", "การ", "ราย".
    Those patterns are handled by explicit entries in THAI_OCR_FIXES.
    """
    # A: tone marks before a consonant (NOT leading vowels)
    text = re.sub(r"([่้๊๋])\s+([ก-ฮ])", r"\1\2", text)
    # B: ั / ็ before a consonant (NOT leading vowels)
    text = re.sub(r"([ั็])\s+([ก-ฮ])", r"\1\2", text)
    # C: nikhahit + sara-aa
    text = text.replace("\u0e4d\u0e32", "\u0e33")
    text = text.replace("\u0e4d \u0e32", "\u0e33")
    return text


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------

def normalize_text(text: str) -> NormalizeResult:
    flags: set[str] = set()

    if text == "":
        return {"text": "", "flags": []}

    working = unicodedata.normalize("NFC", text)
    if working != text:
        flags.add("unicode_normalized")

    stripped = working.strip()
    if stripped != working:
        flags.add("trim_whitespace")
    working = stripped

    zero_width_cleaned = _remove_zero_width(working)
    if zero_width_cleaned != working:
        flags.add("zero_width_removed")
    working = zero_width_cleaned

    if pythainlp_normalize is not None:
        try:
            normalized = pythainlp_normalize(working)
        except Exception:
            normalized = working
        else:
            if normalized != working:
                flags.add("pythainlp_normalized")
            working = normalized

    early_fixed = _apply_ocr_fixes(working)
    if early_fixed != working:
        flags.add("thai_pattern_fix")
    working = early_fixed

    collapsed = _collapse_combining_whitespace(working)
    if collapsed != working:
        flags.add("thai_combining_fix")
    working = collapsed

    normalized_vowels = normalize_thai_vowels(working)
    if normalized_vowels != working:
        flags.add("thai_vowel_fix")
    working = normalized_vowels

    working = re.sub(r"\s+", " ", working)

    # ── Late OCR fix (catches patterns revealed by the vowel fix) ─────────────
    late_fixed = _apply_ocr_fixes(working)
    if late_fixed != working:
        flags.add("thai_pattern_fix")
    working = late_fixed

    if "ํา" in working:
        flags.add("fix_am_sequence")

    if working != text:
        flags.add("normalized")

    return {
        "text": working,
        "flags": sorted(flags),
    }
