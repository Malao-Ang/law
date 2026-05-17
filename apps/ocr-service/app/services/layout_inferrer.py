"""Document-wide indent-level inference.

The extractors emit per-block geometry — for PDFs that's ``bbox`` on every
block, for DOCX it's ``meta.layout.indent_left`` in twips.  Translating those
raw measurements into a small, discrete set of "indent levels" (0..N) is what
makes the review UI and the exported HTML clean: every block at the same
nesting depth gets the same CSS class, regardless of micro-variations in
font metrics or scan skew.

Strategy:

* Collect every block's left-edge x-position in points.
* Cluster the union of positions for the whole document using the existing
  ``utils.indent_detector.cluster_x_positions`` helper.  Clustering across
  the document (not per-page) means a level-2 paragraph on page 3 maps to
  the same bucket as a level-2 paragraph on page 1, which is what reviewers
  expect.
* Assign each block its bucket index as ``meta.layout.indent_level``.

DOCX blocks already carry ``indent_left`` in twips; convert to points before
clustering so we share one coordinate space with PDFs.
"""

from __future__ import annotations

from app.core.config import get_settings
from app.utils.indent_detector import cluster_x_positions, detect_indent_level


_TWIPS_PER_POINT = 20.0


def _x_for_block(block: dict, default_margin_pt: float) -> float | None:
    """Return the block's left-edge x in points, or ``None`` if unknown."""
    meta = block.get("meta") or {}
    layout = meta.get("layout") or {}

    bbox = block.get("bbox") or layout.get("bbox")
    if isinstance(bbox, (list, tuple)) and len(bbox) >= 1:
        try:
            return float(bbox[0])
        except (TypeError, ValueError):
            pass

    indent_left = layout.get("indent_left")
    if indent_left is not None:
        try:
            # DOCX indent_left is in twips and is *relative to the page margin*.
            return default_margin_pt + (float(indent_left) / _TWIPS_PER_POINT)
        except (TypeError, ValueError):
            pass

    return None


def infer_indent_levels(pages: list[dict]) -> None:
    """Annotate every block in ``pages`` with ``meta.layout.indent_level`` and
    ``meta.layout.indent_unit_pt``.

    Modifies blocks in-place.  Blocks without any usable x-position are left
    unchanged (no ``indent_level`` field is added).
    """
    settings = get_settings()
    step_pt = float(settings.indent_cluster_step)
    margin_pt = float(settings.page_margin_x)

    # Pass 1 — collect.
    block_xs: list[tuple[dict, float]] = []
    for page in pages:
        for block in page.get("blocks", []) or []:
            x = _x_for_block(block, default_margin_pt=margin_pt)
            if x is None:
                continue
            block_xs.append((block, x))

    if not block_xs:
        return

    # Pass 2 — cluster the union of all x-positions across the document.
    clusters = cluster_x_positions(
        x_positions=[x for _, x in block_xs],
        step=step_pt,
        margin_x=margin_pt,
    )

    # Pass 3 — annotate.
    for block, x in block_xs:
        level = detect_indent_level(x, clusters)
        layout = block.setdefault("meta", {}).setdefault("layout", {})
        layout["indent_level"] = max(0, min(10, int(level)))
        layout["indent_unit_pt"] = step_pt
