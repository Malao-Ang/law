import re
from typing import TypedDict


class NormalizeResult(TypedDict):
    text: str
    flags: list[str]


THAI_OCR_FIXES = {
    "ํา": "ำ",
    "บญญตั ิ": "บัญญัติ",
    "ม าตรา": "มาตรา",
}


def normalize_text(text: str) -> NormalizeResult:
    flags: list[str] = []

    cleaned = text.strip()
    cleaned = cleaned.replace("\u200b", "")
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
