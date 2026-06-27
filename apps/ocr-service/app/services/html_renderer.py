from __future__ import annotations

TAB_FALLBACK_PT = 48.0


def escape_html(value: str) -> str:
    return (
        value.replace("&", "&amp;")
             .replace("<", "&lt;")
             .replace(">", "&gt;")
             .replace('"', "&quot;")
             .replace("'", "&#39;")
    )


def build_table_html(rows: list[list[dict]]) -> str:
    html_rows: list[str] = []

    for row_index, row in enumerate(rows):
        rendered_cells: list[str] = []
        cell_tag = "th" if row_index == 0 else "td"
        for cell in row:
            attrs: list[str] = []
            colspan = max(1, int(cell.get("colspan") or 1))
            rowspan = max(1, int(cell.get("rowspan") or 1))
            alignment = cell.get("alignment")
            if colspan > 1:
                attrs.append(f' colspan="{colspan}"')
            if rowspan > 1:
                attrs.append(f' rowspan="{rowspan}"')
            if alignment:
                al = escape_html(str(alignment))
                attrs.append(f' data-cell-align="{al}" style="text-align:{al};"')
            text = escape_html(str(cell.get("text") or "")).replace("\n", "<br>")
            if not text and cell.get("has_image"):
                text = '<span class="doc-cell-image">[image]</span>'
            rendered_cells.append(f'<{cell_tag}{"".join(attrs)}>{text}</{cell_tag}>')
        html_rows.append("<tr>" + "".join(rendered_cells) + "</tr>")

    return '<table class="doc-table" border="1" cellspacing="0" cellpadding="4"><tbody>' + "".join(html_rows) + "</tbody></table>"


def build_layout_style(layout: dict) -> str:
    styles: list[str] = []

    alignment = layout.get("alignment")
    if alignment:
        styles.append(f"text-align:{alignment}")

    indent_left = _to_int(layout.get("indent_left"))
    indent_first_line = _to_int(layout.get("indent_first_line"))
    indent_hanging = _to_int(layout.get("indent_hanging"))
    spacing_before = _to_int(layout.get("spacing_before"))
    spacing_after = _to_int(layout.get("spacing_after"))
    line_spacing = _to_int(layout.get("line_spacing"))

    if indent_left is not None:
        styles.append(f"margin-left:{indent_left / 20:.1f}pt")

    if indent_first_line is not None:
        styles.append(f"text-indent:{indent_first_line / 20:.1f}pt")
    elif indent_hanging is not None:
        styles.append(f"text-indent:-{indent_hanging / 20:.1f}pt")

    if spacing_before is not None:
        styles.append(f"margin-top:{spacing_before / 20:.1f}pt")
    if spacing_after is not None:
        styles.append(f"margin-bottom:{spacing_after / 20:.1f}pt")

    if line_spacing is not None and line_spacing > 0:
        styles.append(f"line-height:{line_spacing / 240.0:.2f}")

    return "; ".join(styles)


def render_text_with_layout(text: str, layout: dict) -> str:
    if "\t" not in text:
        return escape_html(text).replace("\n", "<br>")

    tab_positions = [
        max(float(tab.get("position") or 0) / 20.0, TAB_FALLBACK_PT)
        for tab in layout.get("tabs", [])
        if isinstance(tab, dict)
    ]
    segments = text.split("\t")
    rendered: list[str] = []

    for index, segment in enumerate(segments):
        rendered.append(escape_html(segment).replace("\n", "<br>"))
        if index >= len(segments) - 1:
            continue
        width = tab_positions[index] if index < len(tab_positions) else TAB_FALLBACK_PT
        rendered.append(f'<span class="doc-tab" style="display:inline-block; width:{width:.1f}pt;"></span>')

    return "".join(rendered)


def build_reviewed_html(
    block_type: str,
    text: str,
    layout: dict,
    block_id: str,
    table: dict | None = None,
    source_meta: dict | None = None,
) -> str:
    if block_type == "table" and table is not None:
        html = str(table["html"])
        return html.replace("<table", f'<table data-block-id="{block_id}"', 1)

    if block_type == "image":
        meta = source_meta or {}
        img_src = meta.get("image_data_uri") or meta.get("image_path") or ""
        if img_src:
            return (
                f'<figure data-block-id="{block_id}" class="doc-image" style="text-align:center; margin:1rem 0;">'
                f'<img src="{img_src}" alt="embedded image" style="max-width:100%; height:auto; display:block; margin:0 auto;"/>'
                f'</figure>'
            )
        return f'<figure data-block-id="{block_id}" class="doc-image doc-image--missing"></figure>'

    classes = ["doc-paragraph"]
    if block_type == "list_item":
        classes.append("doc-list-item")
    elif block_type == "title":
        classes.append("doc-title")
    elif block_type == "section_header":
        classes.append("doc-section-header")
    elif block_type == "figure_caption":
        classes.append("doc-figure-caption")
    elif block_type == "footnote":
        classes.append("doc-footnote")

    style = build_layout_style(layout)
    text_html = render_text_with_layout(text, layout)
    class_attr = f' class="{" ".join(classes)}"'
    style_attr = f' style="{style}"' if style else ""
    id_attr = f' data-block-id="{block_id}"'

    return f"<p{id_attr}{class_attr}{style_attr}>{text_html}</p>"


def _to_int(value: object) -> int | None:
    if value is None or value == "":
        return None
    return int(value)
