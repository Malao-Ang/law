import re
from typing import TypedDict


class NormalizeResult(TypedDict):
    text: str
    flags: list[str]


THAI_OCR_FIXES = {
    "ํา": "ำ",
    "บญญตั ิ": "บัญญัติ",
    "ม าตรา": "มาตรา",
    "ส า": "สำ",
    "ก า": "กำ",
    "ท า": "ทำ",
    "น า": "นำ",
    "ย า": "ยำ",
    "ร า": "รำ",
    "ล า": "ลำ",
    "ว า": "วำ",
}


def normalize_thai_vowels(text: str) -> str:
    """
    Fix common Thai OCR errors where compound vowels are split.
    Example: "ส า" -> "สำ"
    """
    # Fix the specific "ส า" pattern and other common consonant + space + า
    # This specifically targets the cases where ำ was split into space + า
    text = re.sub(r"([ก-ฮ])\s+า", r"\1ำ", text)
    
    # Fix cases where the small circle (nnikkhahit) might be present but separated
    # Unicode for nnikkhahit is \u0e4d, า is \u0e32. Together they form ำ (\u0e33)
    text = text.replace("\u0e4d\u0e32", "\u0e33")
    text = text.replace("\u0e4d \u0e32", "\u0e33")
    
    return text


def normalize_text(text: str) -> NormalizeResult:
    flags: list[str] = []

    cleaned = text.strip()
    cleaned = cleaned.replace("\u200b", "")
    
    # Apply vowel normalization before whitespace collapsing to catch "ส า"
    normalized_vowels = normalize_thai_vowels(cleaned)
    if normalized_vowels != cleaned:
        flags.append("thai_vowel_fix")
        cleaned = normalized_vowels

    cleaned = re.sub(r"\s+", " ", cleaned)

    fixed = cleaned
    for source, target in THAI_OCR_FIXES.items():
        if source in fixed:
            fixed = fixed.replace(source, target)
            flags.append("thai_pattern_fix")

    if "ํา" in cleaned:
        flags.append("fix_am_sequence")

    if fixed != text:
        flags.append("normalized")

    return {
        "text": fixed,
        "flags": sorted(set(flags)),
    }
