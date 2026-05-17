"""
Thai Legal Semantic Indent Rule Engine.

Runs as a document-wide pass *after* geometry-based indent clustering
(layout_inferrer.infer_indent_levels) and list-marker detection
(numbering_tokenizer.annotate_blocks).  Rewrites meta.layout.indent_level
using deterministic Thai legal conventions so the value is correct
regardless of source format (DOCX, text PDF, OCR scan).

Rule priority (highest first):
  1. table block          → preserve; reset context
  2. center / right       → preserve alignment; reset context
  3. divider line         → flag next block for โดย rule; reset context
  4. โดย after divider    → indent_level=0, indent_first_line=720 (one tab)
  5. มาตรา / ข้อ marker   → level from tokenizer (dot-depth), capped 1–4
  6. paren / single / bullet etc. → level 1
  7. multi-dot marker     → level from tokenizer, capped 1–4
  8. continuation text    → parent_indent + 1, capped at 4
  9. no marker, no parent → keep geometry level, capped at 4
"""
from __future__ import annotations

import re

_DIVIDER_RE = re.compile(r"^[-─]{5,}$")

_LEGAL_TOP = {"legal-มาตรา", "legal-ข้อ"}

# Marker types that always produce indent_level=1
_FLAT_TYPES = {"paren", "legal-วรรค", "bullet", "single", "thai-numeral", "arabic"}

# Quotation openers that signal "this line is a child of the active parent"
# even if upstream marked it as visually centred.
_QUOTE_OPENERS = (
    '"', "'",
    "“", "”",   # “ ”
    "‘", "’",   # ‘ ’
    "「", "」",   # 「 」
    "『", "』",   # 『 』
    "《", "》",   # 《 》
    "(", "（",        # ( （
)

# Thai connectors that never start a new clause — always a continuation
# of the previous parent under an active context.
_THAI_CONNECTORS = (
    "ซึ่ง", "และ", "หรือ", "เพื่อ",
    "ทั้งนี้", "รวมถึง", "ตลอดจน",
    "อันได้แก่", "ได้แก่",
)


def _is_continuation_text(text: str) -> bool:
    if not text:
        return False
    if text.startswith(_QUOTE_OPENERS):
        return True
    return any(text.startswith(prefix) for prefix in _THAI_CONNECTORS)


def _is_divider(block: dict) -> bool:
    return bool(_DIVIDER_RE.match((block.get("raw_text") or "").strip()))


def resolve_semantic_indents(pages: list[dict]) -> None:
    """Apply semantic indent rules to all blocks across all pages, in-place."""
    for page in pages:
        _resolve_page(page.get("blocks", []) or [])


def _resolve_page(blocks: list[dict]) -> None:
    current_parent_indent: int = 0
    next_is_by: bool = False

    for block in blocks:
        btype = block.get("type", "paragraph")
        meta = block.get("meta") or {}
        layout = meta.get("layout") or {}
        marker = meta.get("list_marker")
        raw_text = block.get("raw_text", "").strip()
        alignment = layout.get("alignment")

        # 1. Table — preserve table layout, reset context
        if btype == "table":
            current_parent_indent = 0
            next_is_by = False
            continue

        # 2.5  Continuation override.  A non-marker block under an active parent
        # whose text opens with a quotation mark or a Thai connector word is
        # semantically a child of the parent — never a standalone centred title —
        # so we suppress the alignment reset and apply the continuation rule.
        if (
            not marker
            and current_parent_indent > 0
            and _is_continuation_text(raw_text)
        ):
            layout["indent_level"] = min(current_parent_indent + 1, 4)
            layout["indent_source"] = "semantic"
            layout["indent_reason"] = "continuation_override"
            continue

        # 2. Centered / right-aligned — preserve geometric alignment, reset context
        if alignment in ("center", "right"):
            current_parent_indent = 0
            next_is_by = False
            continue

        # 3. Divider line (five or more dashes)
        if _DIVIDER_RE.match(raw_text):
            next_is_by = True
            current_parent_indent = 0
            continue

        # 4. โดย immediately after divider — first-line indent only
        if next_is_by and raw_text.startswith("โดย"):
            layout["indent_level"] = 0
            layout["indent_first_line"] = 720  # 36 pt in twips
            layout["indent_source"] = "semantic"
            layout["indent_reason"] = "by_after_divider"
            next_is_by = False
            current_parent_indent = 0
            continue
        next_is_by = False

        # 5–7. Structural markers
        if marker:
            mtype = marker.get("type", "")
            mlevel = int(marker.get("level", 1))

            if mtype in _LEGAL_TOP:
                sem = max(1, min(4, mlevel))
            elif mtype in _FLAT_TYPES:
                sem = 1
            elif mtype == "multi":
                sem = max(1, min(4, mlevel))
            else:
                sem = max(1, min(4, mlevel))

            layout["indent_level"] = sem
            layout["indent_source"] = "semantic"
            layout["indent_reason"] = f"marker:{mtype}"
            current_parent_indent = sem
            continue

        # 8. Continuation text after a structural marker
        if current_parent_indent > 0:
            layout["indent_level"] = min(current_parent_indent + 1, 4)
            layout["indent_source"] = "semantic"
            layout["indent_reason"] = "continuation"
            continue

        # 9. Geometry fallback — cap at 4 to honour max semantic indent
        existing = layout.get("indent_level")
        if existing is not None:
            layout["indent_level"] = min(int(existing), 4)

    # 10. Parent-child verification — repair orphans the per-block rules missed.
    _verify_parent_child(blocks)


_VERIFY_REASONS = {"continuation", "continuation_override"}


def _verify_parent_child(blocks: list[dict]) -> None:
    """Repair continuation blocks whose implied parent is missing or wrong.

    Only continuation-typed blocks are verified — markers know their own
    depth (e.g. ข้อ ๑.๒.๓ is level 3 on its own), and geometry-fallback
    blocks have no parent contract to enforce.

    For each continuation block at level N, walk upstream within the same
    scope (a table or divider breaks scope).  If no block at level < N is
    visible, demote to level 1 (root-level orphan).  If the visible parent
    is shallower than expected (parent_level + 1 < N), rebind to
    parent_level + 1.
    """
    for i, block in enumerate(blocks):
        meta = block.get("meta") or {}
        layout = meta.get("layout")
        if not layout:
            continue
        if layout.get("indent_reason") not in _VERIFY_REASONS:
            continue
        level = int(layout.get("indent_level", 0) or 0)
        if level < 2:
            continue
        # At the cap (4), parent-child level diff is squashed by clamping —
        # a parent already at 4 looks "equal" to its child, so we cannot
        # reliably detect orphans.  Leave saturated levels alone.
        if level >= 4:
            continue

        parent_level: int | None = None
        for j in range(i - 1, -1, -1):
            prev = blocks[j]
            if prev.get("type") == "table":
                break
            if _is_divider(prev):
                break
            prev_layout = (prev.get("meta") or {}).get("layout") or {}
            prev_level = int(prev_layout.get("indent_level", 0) or 0)
            if prev_level < level:
                parent_level = prev_level
                break

        if parent_level is None:
            layout["indent_level"] = 1
            layout["indent_source"] = "semantic"
            layout["indent_reason"] = "orphan_demoted"
        elif parent_level + 1 < level:
            layout["indent_level"] = min(parent_level + 1, 4)
            layout["indent_source"] = "semantic"
            layout["indent_reason"] = "orphan_rebound"
