"""Re-run normalize + semantic structure passes on an existing review.json."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Optional

import httpx

from app.api.schemas import intermediate_output_path
from app.core.logger import get_logger
from app.services.numbering_tokenizer import annotate_blocks as annotate_list_markers
from app.services.semantic_indent_resolver import resolve_semantic_indents
from app.services.thai_normalizer import normalize_text
from app.services.thai_spellchecker import get_spell_checker


def run_correction(
    document_id: str,
    data_root: str | Path,
    enable_ai_correction: bool,
    review_threshold: float,
    callback_url: Optional[str],
) -> None:
    del enable_ai_correction

    logger = get_logger(document_id)
    review_path = intermediate_output_path(Path(data_root), document_id)

    if not review_path.exists():
        logger.error("review.json not found for correction", extra={"path": str(review_path)})
        if callback_url:
            _post_callback(
                callback_url,
                document_id,
                {
                    "status": "correction_failed",
                    "error": f"review.json not found at {review_path}",
                },
                logger,
            )
        return

    try:
        output = json.loads(review_path.read_text(encoding="utf-8"))
        pages = output.get("pages", []) or []

        for page in pages:
            blocks = page.get("blocks", []) or []
            for block in blocks:
                _normalize_block(block)
            annotate_list_markers(blocks)

        resolve_semantic_indents(pages)
        _attach_spell_suggestions(output, review_threshold)
        _recount_summary(output)

        review_path.write_text(
            json.dumps(output, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

        if callback_url:
            _post_callback(
                callback_url,
                document_id,
                {
                    "status": "correction_done",
                    "output": output,
                },
                logger,
            )
    except Exception as exc:
        logger.exception("correction failed")
        if callback_url:
            _post_callback(
                callback_url,
                document_id,
                {
                    "status": "correction_failed",
                    "error": str(exc),
                },
                logger,
            )


def _normalize_block(block: dict) -> None:
    raw_text = block.get("raw_text", "") or ""
    normalized = normalize_text(raw_text)
    block["normalized_text"] = normalized["text"]

    if block.get("approved_text") == raw_text:
        block["approved_text"] = normalized["text"]
    if block.get("ai_suggested_text") == raw_text:
        block["ai_suggested_text"] = normalized["text"]

    existing_flags = set(block.get("flags", []) or [])
    existing_flags.update(normalized.get("flags", []))
    block["flags"] = sorted(existing_flags)


def _attach_spell_suggestions(output: dict, review_threshold: float) -> None:
    spellchecker = get_spell_checker()
    targets: list[tuple[dict, float]] = []
    texts: list[str] = []

    for page in output.get("pages", []) or []:
        for block in page.get("blocks", []) or []:
            text = str(block.get("normalized_text") or "")
            confidence = float(block.get("confidence", 1.0))

            block.setdefault("meta", {})["spell_suggestions"] = []
            targets.append((block, confidence))
            texts.append(text)

    suggestions_by_block = spellchecker.bulk_check(texts) if texts else []
    review_required = 0

    for (block, confidence), suggestions in zip(targets, suggestions_by_block):
        needs_review = bool(block.get("needs_review", False))

        if confidence < review_threshold or suggestions:
            needs_review = True

        block.setdefault("meta", {})["spell_suggestions"] = suggestions
        block["needs_review"] = needs_review

        if needs_review:
            review_required += 1

    output.setdefault("summary", {})["review_required_count"] = review_required


def _recount_summary(output: dict) -> None:
    summary = output.setdefault("summary", {})
    pages = output.get("pages", []) or []
    summary["page_count"] = len(pages)
    summary["block_count"] = sum(len(page.get("blocks", []) or []) for page in pages)


def _post_callback(url: str, document_id: str, payload: dict, logger: object) -> None:
    try:
        with httpx.Client(timeout=10) as client:
            response = client.post(url, json={"document_id": document_id, **payload})
        response.raise_for_status()
        logger.info("correction callback delivered", extra={"status_code": response.status_code})
    except httpx.HTTPStatusError as exc:
        logger.error(
            "correction callback rejected",
            extra={"status_code": exc.response.status_code, "body": exc.response.text[:500]},
        )
        raise
