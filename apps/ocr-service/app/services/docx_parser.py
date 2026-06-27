from __future__ import annotations

import re
import zipfile
from pathlib import Path
try:
    from lxml import etree as ET
except ImportError:
    from xml.etree import ElementTree as ET

from app.services.html_renderer import build_table_html, escape_html
from app.services.image_extractor import ImageExtractor

WORD_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
NAMESPACES = {"w": WORD_NS}


def _local_name(tag: str) -> str:
    return tag.split("}", 1)[-1]


def _word_attr(node: ET.Element, name: str) -> str | None:
    return node.get(f"{{{WORD_NS}}}{name}")


def _parse_int_attr(node: ET.Element, name: str) -> int | None:
    v = _word_attr(node, name)
    return None if not v else int(v)


class DocxParser:
    def __init__(self, image_extractor: ImageExtractor) -> None:
        self._images = image_extractor

    def extract(self, file_path: Path, document_id: str) -> list[dict]:
        with zipfile.ZipFile(file_path) as archive:
            rel_map = self._build_rel_map(archive)
            numbering_context = self._parse_numbering_xml(archive)

            with archive.open("word/document.xml") as fh:
                root = ET.parse(fh).getroot()

            body = root.find("w:body", NAMESPACES)
            if body is None:
                return []

            blocks: list[dict] = []
            reading_order = 1

            for child in body:
                tag = _local_name(child.tag)
                block: dict | None = None

                if tag == "p":
                    block = self._parse_image(child, archive, rel_map, document_id, reading_order)
                    if block is None:
                        block = self._parse_paragraph(child, reading_order, numbering_context)
                elif tag == "tbl":
                    block = self._parse_table(child, reading_order)

                if block is None:
                    continue
                blocks.append(block)
                reading_order += 1

        return blocks

    # ── image ─────────────────────────────────────────────────────────────────

    def _parse_image(
        self,
        paragraph: ET.Element,
        archive: zipfile.ZipFile,
        rel_map: dict[str, str],
        document_id: str,
        reading_order: int,
    ) -> dict | None:
        REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
        rid: str | None = None
        for node in paragraph.iter():
            rid = node.get(f"{{{REL_NS}}}embed")
            if rid:
                break
        if not rid or rid not in rel_map:
            return None
        zip_path = rel_map[rid]
        if zip_path not in archive.namelist():
            return None
        img_data = archive.read(zip_path)
        ext = Path(zip_path).suffix
        img_name = f"img-{reading_order:04d}"
        img_path = self._images.save(img_data, ext, document_id, img_name)
        data_uri = self._images.to_data_uri(img_data, ext)
        return {
            "block_id": f"1-{reading_order}",
            "type": "image",
            "reading_order": reading_order,
            "raw_text": "",
            "bbox": None,
            "confidence": 1.0,
            "flags": [],
            "meta": {
                "image_path": str(img_path),
                "image_data_uri": data_uri,
                "source": "docx_embedded",
            },
        }

    # ── paragraph ─────────────────────────────────────────────────────────────

    def _parse_paragraph(
        self, paragraph: ET.Element, reading_order: int, numbering_context: dict
    ) -> dict | None:
        text = self._extract_paragraph_text(paragraph)
        layout = self._extract_paragraph_layout(paragraph)

        numbering_info = self._resolve_numbering(paragraph, numbering_context, layout)
        if numbering_info:
            text = numbering_info["prefix"] + " " + text
            layout = numbering_info["layout"]

        if text.strip() == "":
            return None

        # Infer first-line indent from a leading TAB when w:firstLine is absent.
        # Thai legal DOCX files routinely use a literal tab at the start of body
        # paragraphs instead of setting w:ind/@firstLine.
        # Skip for numbered list items — their text already starts with
        # `prefix + "\t"` as a structural marker separator, not a body indent.
        layout = layout.copy()
        if numbering_info is None and layout.get("indent_first_line") is None and text.startswith("\t"):
            from app.core.config import get_settings
            text = text.lstrip("\t")
            layout["indent_first_line"] = get_settings().first_line_indent_default_twips
            layout["first_line_inferred"] = "leading_tab"

        meta: dict = {"layout": layout}
        if numbering_info:
            meta["numbering"] = numbering_info["meta"]

        return {
            "block_id": f"1-{reading_order}",
            "type": self._classify_paragraph(
                text=text,
                layout=layout,
                reading_order=reading_order,
                has_numbering=numbering_info is not None,
            ),
            "reading_order": reading_order,
            "raw_text": text,
            "bbox": None,
            "confidence": 0.98,
            "flags": [],
            "meta": meta,
        }

    def _extract_paragraph_text(self, paragraph: ET.Element) -> str:
        parts: list[str] = []
        for node in paragraph.iter():
            tag = _local_name(node.tag)
            if tag == "t" and node.text:
                parts.append(node.text)
            elif tag == "tab":
                parts.append("\t")
            elif tag in {"br", "cr"}:
                parts.append("\n")
        return "".join(parts).replace("\u00a0", " ").strip("\n")

    def _extract_paragraph_layout(self, paragraph: ET.Element) -> dict:
        pPr = paragraph.find("w:pPr", NAMESPACES)
        alignment = indent_left = indent_first_line = indent_hanging = None
        spacing_before = spacing_after = line_spacing = None
        tabs: list[dict] = []
        if pPr is not None:
            jc = pPr.find("w:jc", NAMESPACES)
            ind = pPr.find("w:ind", NAMESPACES)
            spacing = pPr.find("w:spacing", NAMESPACES)
            if jc is not None:
                alignment = _word_attr(jc, "val")
            if ind is not None:
                indent_left = _parse_int_attr(ind, "left")
                indent_first_line = _parse_int_attr(ind, "firstLine")
                indent_hanging = _parse_int_attr(ind, "hanging")
            if spacing is not None:
                spacing_before = _parse_int_attr(spacing, "before")
                spacing_after = _parse_int_attr(spacing, "after")
                line_spacing = _parse_int_attr(spacing, "line")
            for tab in pPr.findall("w:tabs/w:tab", NAMESPACES):
                pos = _parse_int_attr(tab, "pos")
                if pos is not None:
                    tabs.append({"align": _word_attr(tab, "val") or "left", "position": pos})
        return {
            "bbox": None, "reading_order": None, "alignment": alignment,
            "indent_left": indent_left, "indent_first_line": indent_first_line,
            "indent_hanging": indent_hanging, "tabs": tabs,
            "spacing_before": spacing_before, "spacing_after": spacing_after,
            "line_spacing": line_spacing,
        }

    @staticmethod
    def _classify_paragraph(
        text: str, layout: dict, reading_order: int, has_numbering: bool = False
    ) -> str:
        stripped = text.strip()
        if has_numbering:
            return "list_item"
        if layout.get("alignment") == "center":
            return "title" if reading_order <= 4 else "section_header"
        # Top-level legal section headers: มาตรา / ข้อ
        if re.match(r"^(มาตรา\s+[๐-๙0-9]+(?:/[๐-๙0-9]+)?)\b", stripped):
            return "section_header"
        if re.match(r"^ข้อ\s*[๐-๙0-9]+(?:\.[๐-๙0-9]+)*", stripped):
            return "section_header"
        # List items: parenthesised markers (numbers OR Thai/Latin letters),
        # multi-level numerics, single-level numerics, dashes, bullets.
        if re.match(r"^(\(([๐-๙0-9]+|[ก-ฮ]|[a-zA-Z])\)|[๐-๙0-9]+(?:\.[๐-๙0-9]+)+\.?\s|[๐-๙0-9]+\.\s|-|•)", stripped):
            return "list_item"
        return "paragraph"

    # ── table ─────────────────────────────────────────────────────────────────

    def _parse_table(self, table: ET.Element, reading_order: int) -> dict | None:
        rows: list[list[dict]] = []
        active_vertical_merges: dict[int, dict] = {}
        flattened_rows: list[list[str]] = []

        for row in table.findall("w:tr", NAMESPACES):
            parsed_row: list[dict] = []
            flat_row: list[str] = []
            next_merges: dict[int, dict] = {}
            col = 0

            for cell in row.findall("w:tc", NAMESPACES):
                parsed_cell = self._parse_table_cell(cell)
                colspan = parsed_cell["colspan"]
                v_merge_state = parsed_cell.pop("v_merge_state")

                if v_merge_state == "continue":
                    mc = active_vertical_merges.get(col)
                    if mc is not None:
                        mc["rowspan"] += 1
                        for off in range(colspan):
                            next_merges[col + off] = mc
                    col += colspan
                    continue

                parsed_row.append(parsed_cell)
                flat_row.append(parsed_cell["text"])

                if v_merge_state == "restart":
                    for off in range(colspan):
                        next_merges[col + off] = parsed_cell

                col += colspan

            if parsed_row:
                rows.append(parsed_row)
                flattened_rows.append(flat_row)

            active_vertical_merges = next_merges

        if not rows:
            return None

        headers = flattened_rows[0] if flattened_rows else []
        body = flattened_rows[1:]
        html = build_table_html(rows)
        raw_text = "\n".join("\t".join(c for c in r if c) for r in flattened_rows)

        return {
            "block_id": f"1-{reading_order}",
            "type": "table",
            "reading_order": reading_order,
            "raw_text": raw_text,
            "bbox": None,
            "confidence": 0.99,
            "flags": [],
            "meta": {
                "table": {"headers": headers, "rows": body, "cells": rows, "html": html, "text": raw_text},
                "layout": {
                    "bbox": None, "reading_order": reading_order, "alignment": None,
                    "indent_left": None, "indent_first_line": None, "indent_hanging": None, "tabs": [],
                },
            },
        }

    def _parse_table_cell(self, cell: ET.Element) -> dict:
        tcPr = cell.find("w:tcPr", NAMESPACES)
        colspan = 1
        v_merge_state = None
        if tcPr is not None:
            gs = tcPr.find("w:gridSpan", NAMESPACES)
            vm = tcPr.find("w:vMerge", NAMESPACES)
            if gs is not None:
                colspan = int(_word_attr(gs, "val") or "1")
            if vm is not None:
                v_merge_state = _word_attr(vm, "val") or "continue"
        text = "\n".join(
            part for part in (
                self._extract_paragraph_text(p).strip()
                for p in cell.findall("w:p", NAMESPACES)
            ) if part
        )
        return {
            "text": text, "colspan": colspan, "rowspan": 1,
            "alignment": self._extract_cell_alignment(cell),
            "v_merge_state": v_merge_state,
        }

    def _extract_cell_alignment(self, cell: ET.Element) -> str | None:
        for p in cell.findall("w:p", NAMESPACES):
            al = self._extract_paragraph_layout(p).get("alignment")
            if al is not None:
                return str(al)
        return None

    # ── numbering ─────────────────────────────────────────────────────────────

    def _parse_numbering_xml(self, archive: zipfile.ZipFile) -> dict:
        numbering_path = "word/numbering.xml"
        if numbering_path not in archive.namelist():
            return {"abstract_nums": {}, "num_map": {}, "counters": {}}

        with archive.open(numbering_path) as fh:
            root = ET.parse(fh).getroot()

        abstract_nums: dict = {}
        num_map: dict = {}

        for abstract_num in root.findall("w:abstractNum", NAMESPACES):
            abstract_num_id = _word_attr(abstract_num, "abstractNumId")
            if abstract_num_id is None:
                continue
            levels: dict = {}
            for lvl in abstract_num.findall("w:lvl", NAMESPACES):
                ilvl = _word_attr(lvl, "ilvl")
                if ilvl is None:
                    continue
                start_elem = lvl.find("w:start", NAMESPACES)
                start = int(_word_attr(start_elem, "val") or "1")
                num_fmt_elem = lvl.find("w:numFmt", NAMESPACES)
                num_fmt = _word_attr(num_fmt_elem, "val") or "decimal"
                lvl_text_elem = lvl.find("w:lvlText", NAMESPACES)
                lvl_text = _word_attr(lvl_text_elem, "val") or "%1."
                indent_left = indent_hanging = indent_first_line = None
                ind = lvl.find("w:pPr/w:ind", NAMESPACES)
                if ind is not None:
                    indent_left = _parse_int_attr(ind, "left")
                    indent_hanging = _parse_int_attr(ind, "hanging")
                    indent_first_line = _parse_int_attr(ind, "firstLine")
                levels[int(ilvl)] = {
                    "numFmt": num_fmt, "lvlText": lvl_text, "start": start,
                    "indent_left": indent_left, "indent_hanging": indent_hanging,
                    "indent_first_line": indent_first_line,
                }
            abstract_nums[int(abstract_num_id)] = {"levels": levels}

        for num in root.findall("w:num", NAMESPACES):
            num_id = _word_attr(num, "numId")
            abstract_num_ref = num.find("w:abstractNumId", NAMESPACES)
            abstract_num_id_ref = _word_attr(abstract_num_ref, "val") if abstract_num_ref is not None else None
            if num_id is None or abstract_num_id_ref is None:
                continue
            overrides: dict = {}
            for lvl_override in num.findall("w:lvlOverride", NAMESPACES):
                ilvl = _word_attr(lvl_override, "ilvl")
                if ilvl is None:
                    continue
                override_data: dict = {}
                start_elem = lvl_override.find("w:startOverride", NAMESPACES)
                if start_elem is not None:
                    override_data["start"] = int(_word_attr(start_elem, "val") or "1")
                num_fmt_elem = lvl_override.find("w:numFmt", NAMESPACES)
                if num_fmt_elem is not None:
                    override_data["numFmt"] = _word_attr(num_fmt_elem, "val")
                lvl_text_elem = lvl_override.find("w:lvlText", NAMESPACES)
                if lvl_text_elem is not None:
                    override_data["lvlText"] = _word_attr(lvl_text_elem, "val")
                if override_data:
                    overrides[int(ilvl)] = override_data
            num_map[int(num_id)] = {"abstractNumId": int(abstract_num_id_ref), "overrides": overrides}

        return {"abstract_nums": abstract_nums, "num_map": num_map, "counters": {}}

    def _resolve_numbering(
        self, paragraph: ET.Element, numbering_context: dict, layout: dict
    ) -> dict | None:
        pPr = paragraph.find("w:pPr", NAMESPACES)
        if pPr is None:
            return None
        numPr = pPr.find("w:numPr", NAMESPACES)
        if numPr is None:
            return None
        numId_elem = numPr.find("w:numId", NAMESPACES)
        ilvl_elem = numPr.find("w:ilvl", NAMESPACES)
        if numId_elem is None or ilvl_elem is None:
            return None
        numId = int(_word_attr(numId_elem, "val") or "0")
        ilvl = int(_word_attr(ilvl_elem, "val") or "0")

        num_map = numbering_context.get("num_map", {})
        abstract_nums = numbering_context.get("abstract_nums", {})
        if numId not in num_map:
            return None
        num_def = num_map[numId]
        abstract_num_id = num_def["abstractNumId"]
        if abstract_num_id not in abstract_nums:
            return None
        levels = abstract_nums[abstract_num_id]["levels"]
        if ilvl not in levels:
            return None
        level_info = {**levels[ilvl], **num_def.get("overrides", {}).get(ilvl, {})}

        counters = numbering_context.get("counters", {})
        counter_key = (numId, ilvl)
        if ilvl > 0:
            for key in list(counters.keys()):
                if key[0] == numId and key[1] < ilvl:
                    del counters[key]
        current_num = counters.get(counter_key, level_info["start"] - 1) + 1
        counters[counter_key] = current_num
        numbering_context["counters"] = counters

        prefix = self._format_numbering_prefix(
            current_num, level_info["numFmt"], level_info["lvlText"],
            numId, ilvl, numbering_context
        )

        updated_layout = layout.copy()
        for key in ("indent_left", "indent_hanging", "indent_first_line"):
            if updated_layout.get(key) is None and level_info.get(key) is not None:
                updated_layout[key] = level_info[key]

        return {
            "prefix": prefix,
            "layout": updated_layout,
            "meta": {
                "numId": numId, "ilvl": ilvl, "numFmt": level_info["numFmt"],
                "generated_prefix": prefix, "current_num": current_num,
            },
        }

    def _format_numbering_prefix(
        self, num: int, num_fmt: str, lvl_text: str, numId: int, ilvl: int, ctx: dict
    ) -> str:
        if num_fmt == "decimal":
            num_str = str(num)
        elif num_fmt == "thaiNumbers":
            thai_digits = "๐๑๒๓๔๕๖๗๘๙"
            num_str = "".join(thai_digits[int(d)] for d in str(num))
        elif num_fmt == "thaiLetters":
            thai_letters = "กขคงจฉชซฌญฎฏฐฑฒณดตถทธนบปผพภมยรลวศษสหฬอฮ"
            num_str = thai_letters[num - 1] if 1 <= num <= len(thai_letters) else str(num)
        elif num_fmt == "bullet":
            bullets = ["•", "◦", "▪", "▫", "■", "□", "▪", "▫"]
            num_str = bullets[min(ilvl, len(bullets) - 1)]
        elif num_fmt == "lowerLetter":
            num_str = chr(ord("a") + num - 1) if 1 <= num <= 26 else str(num)
        elif num_fmt == "upperLetter":
            num_str = chr(ord("A") + num - 1) if 1 <= num <= 26 else str(num)
        elif num_fmt == "lowerRoman":
            roman = ["i","ii","iii","iv","v","vi","vii","viii","ix","x",
                     "xi","xii","xiii","xiv","xv","xvi","xvii","xviii","xix","xx"]
            num_str = roman[num - 1] if 1 <= num <= len(roman) else str(num)
        elif num_fmt == "upperRoman":
            roman = ["I","II","III","IV","V","VI","VII","VIII","IX","X",
                     "XI","XII","XIII","XIV","XV","XVI","XVII","XVIII","XIX","XX"]
            num_str = roman[num - 1] if 1 <= num <= len(roman) else str(num)
        else:
            num_str = str(num)

        result = lvl_text.replace("%1", num_str)
        for level in range(2, 10):
            placeholder = f"%{level}"
            if placeholder not in result:
                continue
            parent_ilvl = ilvl - (level - 1)
            if parent_ilvl < 0:
                continue
            parent_key = (numId, parent_ilvl)
            parent_num = ctx.get("counters", {}).get(parent_key, 1)
            parent_fmt = (
                ctx.get("abstract_nums", {})
                   .get(ctx.get("num_map", {}).get(numId, {}).get("abstractNumId", 0), {})
                   .get("levels", {}).get(parent_ilvl, {}).get("numFmt", "decimal")
            )
            parent_str = self._format_numbering_prefix(parent_num, parent_fmt, "%1", numId, parent_ilvl, ctx)
            result = result.replace(placeholder, parent_str)

        return result

    # ── helpers ───────────────────────────────────────────────────────────────

    @staticmethod
    def _build_rel_map(archive: zipfile.ZipFile) -> dict[str, str]:
        rel_map: dict[str, str] = {}
        rel_path = "word/_rels/document.xml.rels"
        if rel_path not in archive.namelist():
            return rel_map
        with archive.open(rel_path) as fh:
            for rel in ET.parse(fh).getroot():
                if rel.get("Type", "").endswith("/image"):
                    rid = rel.get("Id", "")
                    target = rel.get("Target", "").lstrip("./")
                    if not target.startswith("word/"):
                        target = "word/" + target
                    rel_map[rid] = target
        return rel_map
