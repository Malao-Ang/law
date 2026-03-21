from collections.abc import Callable

from app.services.ai_corrector import MockAICorrector


def build_document_output(
    document_id: str,
    source_file: str,
    source_type: str,
    pages: list[dict],
    normalizer: Callable[[str], dict],
    ai_corrector: MockAICorrector,
    enable_ai_correction: bool,
    review_threshold: float,
) -> dict:
    output_pages: list[dict] = []
    block_count = 0
    review_required = 0

    for page in pages:
        transformed_blocks: list[dict] = []
        page_no = int(page.get("page_no", 1))

        for index, block in enumerate(page.get("blocks", []), start=1):
            raw_text = str(block.get("raw_text", "")).strip()
            if not raw_text:
                continue

            normalized = normalizer(raw_text)
            confidence = float(block.get("confidence", 1.0))
            flags = list(set(block.get("flags", []) + normalized.get("flags", [])))

            needs_review = confidence < review_threshold or len(flags) > 0
            ai_text = normalized["text"]

            if enable_ai_correction and (needs_review or block.get("type") in {"table", "section_header"}):
                ai_result = ai_corrector.suggest(normalized["text"])
                ai_text = ai_result["suggested_text"]
                confidence = min(confidence, ai_result["confidence"])
                flags = list(set(flags + ai_result["reason"]))

            approved_text = ai_text
            bbox = block.get("bbox")
            reviewed_html = build_reviewed_html(block.get("type", "paragraph"), approved_text)
            table = build_table_payload(raw_text, block.get("type", "paragraph"))
            if table is not None:
                reviewed_html = table["html"]

            transformed_blocks.append(
                {
                    "block_id": block.get("block_id", f"{page_no}-{index}"),
                    "type": block.get("type", "paragraph"),
                    "bbox": bbox,
                    "reading_order": int(block.get("reading_order", index)),
                    "raw_text": raw_text,
                    "normalized_text": normalized["text"],
                    "ai_suggested_text": ai_text,
                    "approved_text": approved_text,
                    "confidence": max(0.0, min(1.0, confidence)),
                    "needs_review": needs_review,
                    "flags": sorted(set(flags)),
                    "meta": {
                        "section_path": None,
                        "table_html": table["html"] if table is not None else None,
                        "reviewed_html": reviewed_html,
                        "layout": {
                            "bbox": bbox,
                            "reading_order": int(block.get("reading_order", index)),
                        },
                        "table": table,
                    },
                }
            )

            block_count += 1
            if needs_review:
                review_required += 1

        output_pages.append(
            {
                "page_no": page_no,
                "image_path": page.get("image_path"),
                "blocks": transformed_blocks,
            }
        )

    return {
        "document_id": document_id,
        "source_file": source_file,
        "source_type": source_type,
        "language": "th",
        "summary": {
            "page_count": len(output_pages),
            "block_count": block_count,
            "review_required_count": review_required,
        },
        "pages": output_pages,
    }


def build_reviewed_html(block_type: str, text: str) -> str:
    safe_text = escape_html(text).replace("\n", "<br>")
    tag = {
        "title": "h1",
        "section_header": "h2",
        "list_item": "li",
        "figure_caption": "figcaption",
        "footnote": "aside",
    }.get(block_type, "p")
    return f"<{tag}>{safe_text}</{tag}>"


def build_table_payload(raw_text: str, block_type: str) -> dict | None:
    if block_type != "table":
        return None

    rows = [row.strip() for row in raw_text.splitlines() if row.strip()]
    parsed_rows = [[cell.strip() for cell in row.split("|")] for row in rows]
    headers = parsed_rows[0] if parsed_rows else []
    body = parsed_rows[1:] if len(parsed_rows) > 1 else []
    html_headers = "".join(f"<th>{escape_html(header)}</th>" for header in headers)
    html_rows = "".join(
        "<tr>" + "".join(f"<td>{escape_html(cell)}</td>" for cell in row) + "</tr>"
        for row in body
    )

    return {
        "headers": headers,
        "rows": body,
        "html": f"<table>{'<thead><tr>' + html_headers + '</tr></thead>' if html_headers else ''}<tbody>{html_rows}</tbody></table>",
    }


def escape_html(value: str) -> str:
    return (
        value.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
        .replace("'", "&#39;")
    )
