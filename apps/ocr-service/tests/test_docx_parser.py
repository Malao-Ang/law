from pathlib import Path
import zipfile

from app.services.docling_service import DoclingService


DOCX_XML = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p>
      <w:pPr>
        <w:jc w:val="center" />
      </w:pPr>
      <w:r><w:t>\u0e1b\u0e23\u0e30\u0e01\u0e32\u0e28</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:tabs>
          <w:tab w:val="left" w:pos="1440" />
        </w:tabs>
      </w:pPr>
      <w:r><w:t>\u0e27\u0e31\u0e19\u0e17\u0e35\u0e48</w:t></w:r>
      <w:r><w:tab /></w:r>
      <w:r><w:t>21 \u0e21\u0e35\u0e19\u0e32\u0e04\u0e21 2569</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:ind w:left="720" w:firstLine="720" />
      </w:pPr>
      <w:r><w:t>\u0e04\u0e33\u0e19\u0e34\u0e22\u0e32\u0e21 \u0e2b\u0e21\u0e32\u0e22\u0e04\u0e27\u0e32\u0e21\u0e27\u0e48\u0e32 \u0e02\u0e49\u0e2d\u0e04\u0e27\u0e32\u0e21\u0e15\u0e31\u0e27\u0e2d\u0e22\u0e48\u0e32\u0e07</w:t></w:r>
    </w:p>
    <w:p>
      <w:r><w:t>\u0e02\u0e49\u0e2d\u0e51 \u0e02\u0e2d\u0e1a\u0e40\u0e02\u0e15</w:t></w:r>
    </w:p>
    <w:p>
      <w:r><w:t>(\u0e51) \u0e23\u0e32\u0e22\u0e01\u0e32\u0e23</w:t></w:r>
    </w:p>
    <w:tbl>
      <w:tr>
        <w:tc><w:p><w:r><w:t>H1</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>H2</w:t></w:r></w:p></w:tc>
      </w:tr>
      <w:tr>
        <w:tc><w:p><w:r><w:t>A1</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>A2</w:t></w:r></w:p></w:tc>
      </w:tr>
    </w:tbl>
    <w:tbl>
      <w:tr>
        <w:tc>
          <w:tcPr><w:vMerge w:val="restart" /></w:tcPr>
          <w:p><w:r><w:t>MERGED</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr><w:gridSpan w:val="2" /></w:tcPr>
          <w:p><w:r><w:t>HEADER</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
      <w:tr>
        <w:tc>
          <w:tcPr><w:vMerge /></w:tcPr>
          <w:p />
        </w:tc>
        <w:tc><w:p><w:r><w:t>B1</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>B2</w:t></w:r></w:p></w:tc>
      </w:tr>
    </w:tbl>
    <w:tbl>
      <w:tr>
        <w:tc>
          <w:tcPr><w:gridSpan w:val="3" /></w:tcPr>
          <w:p><w:r><w:t>FULL WIDTH</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
      <w:tr>
        <w:tc><w:p><w:r><w:t>C1</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>C2</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>C3</w:t></w:r></w:p></w:tc>
      </w:tr>
    </w:tbl>
    <w:sectPr />
  </w:body>
</w:document>
"""


def write_docx(path: Path, document_xml: str) -> None:
    with zipfile.ZipFile(path, "w") as archive:
        archive.writestr("word/document.xml", document_xml)


def test_docx_parser_preserves_tables_and_layout(tmp_path: Path) -> None:
    file_path = tmp_path / "sample.docx"
    write_docx(file_path, DOCX_XML)

    pages = DoclingService().extract(file_path, "docx")

    assert len(pages) == 1
    blocks = pages[0]["blocks"]
    assert [block["type"] for block in blocks[:5]] == [
        "title",
        "paragraph",
        "paragraph",
        "section_header",
        "list_item",
    ]

    title_block = blocks[0]
    assert title_block["meta"]["layout"]["alignment"] == "center"

    tab_block = blocks[1]
    assert "\t" in tab_block["raw_text"]
    assert tab_block["meta"]["layout"]["tabs"] == [{"align": "left", "position": 1440}]

    definition_block = blocks[2]
    assert definition_block["meta"]["layout"]["indent_left"] == 720
    assert definition_block["meta"]["layout"]["indent_first_line"] == 720

    table_blocks = [block for block in blocks if block["type"] == "table"]
    assert len(table_blocks) == 3

    merged_table = table_blocks[1]["meta"]["table"]
    assert merged_table["cells"][0][0]["text"] == "MERGED"
    assert merged_table["cells"][0][0]["rowspan"] == 2
    assert merged_table["cells"][0][1]["colspan"] == 2
    assert 'rowspan="2"' in merged_table["html"]
    assert 'colspan="2"' in merged_table["html"]

    full_width_table = table_blocks[2]["meta"]["table"]
    assert full_width_table["cells"][0][0]["colspan"] == 3
    assert 'colspan="3"' in full_width_table["html"]