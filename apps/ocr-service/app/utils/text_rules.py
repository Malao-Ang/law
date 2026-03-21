THAI_LEGAL_TERMS = [
    "มาตรา",
    "พระราชบัญญัติ",
    "ราชกิจจานุเบกษา",
]


def has_legal_keyword(text: str) -> bool:
    return any(keyword in text for keyword in THAI_LEGAL_TERMS)
