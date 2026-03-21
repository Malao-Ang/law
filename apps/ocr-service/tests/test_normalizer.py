from app.services.thai_normalizer import normalize_text


def test_thai_normalizer_fixes_common_patterns() -> None:
    result = normalize_text("พระราชบญญตั ิ\u200b")
    assert result["text"] == "พระราชบัญญัติ"
    assert "normalized" in result["flags"]
