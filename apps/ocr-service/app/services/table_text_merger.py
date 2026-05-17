"""Merge word-level text cells into TableFormer cell bounding boxes.

When docling TableFormer provides structural layout (row/col/colspan/rowspan)
but the text inside cells needs Thai-aware joining, this module assigns
word-level cells (from PyMuPDF or docling-parse) to each TableFormer cell
by centroid containment and then joins the matched words with ``_join_thai_spans``.

Algorithm per cell:
  1. Compute the centroid of each word-level cell: ``(cx, cy) = (x0+x1)/2, (y0+y1)/2``.
  2. A word is assigned to a TableFormer cell if its centroid falls within
     that cell's bbox (or the overlap ratio exceeds ``bbox_overlap_threshold``).
  3. Words are sorted by (y, x) and joined via ``_join_thai_spans`` so that Thai
     combining marks are correctly sequenced.

The function ``merge_text_into_cells`` is the public entry point.
"""

from __future__ import annotations

from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from app.services.table_extractor import CellRect, TableResult


def _centroid(bbox: tuple[float, float, float, float]) -> tuple[float, float]:
    return (bbox[0] + bbox[2]) / 2.0, (bbox[1] + bbox[3]) / 2.0


def _bbox_contains_centroid(
    cell_bbox: tuple[float, float, float, float],
    cx: float,
    cy: float,
) -> bool:
    x0, y0, x1, y1 = cell_bbox
    return x0 <= cx <= x1 and y0 <= cy <= y1


def _overlap_ratio(
    cell_bbox: tuple[float, float, float, float],
    word_bbox: tuple[float, float, float, float],
) -> float:
    """Return intersection area / word area — how much of the word is inside the cell."""
    cx0 = max(cell_bbox[0], word_bbox[0])
    cy0 = max(cell_bbox[1], word_bbox[1])
    cx1 = min(cell_bbox[2], word_bbox[2])
    cy1 = min(cell_bbox[3], word_bbox[3])
    if cx1 <= cx0 or cy1 <= cy0:
        return 0.0
    inter = (cx1 - cx0) * (cy1 - cy0)
    word_area = max((word_bbox[2] - word_bbox[0]) * (word_bbox[3] - word_bbox[1]), 1e-6)
    return inter / word_area


def merge_text_into_cells(
    table: "TableResult",
    word_cells: list[dict],
    bbox_overlap_threshold: float = 0.15,
) -> None:
    """In-place: populate ``CellRect.text`` for every cell in ``table`` from
    the word-level ``word_cells`` list.

    ``word_cells`` is a list of dicts with ``bbox`` (4-tuple) and ``text`` keys,
    as produced by PyMuPDF ``page.get_text("words")`` or docling-parse.

    Cells that already have non-empty text (from TableFormer cell-matching)
    are not overwritten.
    """
    # Defer _join_thai_spans import to avoid circular dependency.
    try:
        from app.services.docling_service import _join_thai_spans  # type: ignore
    except ImportError:
        def _join_thai_spans(spans: list[str]) -> str:  # type: ignore[misc]
            return " ".join(spans)

    for row in table.rows:
        for cell in row:
            if cell.text.strip():
                continue  # already has text from docling cell-matching

            matched: list[dict] = []
            for w in word_cells:
                w_bbox = tuple(w.get("bbox") or (0, 0, 0, 0))
                if len(w_bbox) < 4:
                    continue
                w_bbox = (float(w_bbox[0]), float(w_bbox[1]), float(w_bbox[2]), float(w_bbox[3]))
                cx, cy = _centroid(w_bbox)
                if _bbox_contains_centroid(cell.bbox, cx, cy):
                    matched.append(w)
                elif _overlap_ratio(cell.bbox, w_bbox) >= bbox_overlap_threshold:
                    matched.append(w)

            if not matched:
                continue

            # Sort top-to-bottom then left-to-right before joining.
            matched.sort(key=lambda w: (float((w.get("bbox") or [0])[1]), float((w.get("bbox") or [0, 0])[0])))
            cell.text = _join_thai_spans([str(w.get("text") or "") for w in matched])


def build_cell_grid(table: "TableResult") -> list[list[dict]]:
    """Convert a ``TableResult`` into the canonical ``cells`` grid used by the
    block schema (list of rows, each a list of cell dicts with text/colspan/rowspan).
    """
    rows: list[list[dict]] = []
    for row in table.rows:
        row_out: list[dict] = []
        for cell in row:
            row_out.append({
                "text": cell.text,
                "colspan": max(1, cell.colspan),
                "rowspan": max(1, cell.rowspan),
                "alignment": None,
            })
        if row_out:
            rows.append(row_out)
    return rows
