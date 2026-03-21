import re
from typing import TypedDict


class AiSuggestion(TypedDict):
    suggested_text: str
    reason: list[str]
    confidence: float


class MockAICorrector:
    def suggest(self, text: str) -> AiSuggestion:
        candidate = re.sub(r"\s{2,}", " ", text).strip()
        reasons: list[str] = []

        if candidate != text:
            reasons.append("trim_and_spacing")

        candidate = candidate.replace("พระราชบญญตั ิ", "พระราชบัญญัติ")
        if candidate != text and "fix_thai_ocr" not in reasons:
            reasons.append("fix_thai_ocr")

        confidence = 0.82 if reasons else 0.95
        return {
            "suggested_text": candidate,
            "reason": reasons,
            "confidence": confidence,
        }
