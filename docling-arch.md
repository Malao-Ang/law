# สรุป: วิธีที่ดีที่สุดสำหรับอ่านเอกสารภาษาไทย → HTML

## 🎯 Architecture รวม

```
PDF / DOCX / PDF Scan
         │
         ▼
┌────────────────────────────────────────────┐
│  ตรวจประเภทไฟล์ + ตรวจว่ามี text layer ไหม │
└────────────────────────────────────────────┘
         │
    ┌────┴─────┐
    │          │
PDF text    PDF Scan / DOCX
    │          │
    ▼          ▼
docling-   EasyOCR (th,en)
parse      + docling-parse
    │          │
    └────┬─────┘
         │
         ▼
┌─────────────────────────────┐
│  docling (TableFormer only) │  ← เฉพาะตาราง
└─────────────────────────────┘
         │
         ▼
┌─────────────────────────────┐
│  Merge by BBox Coordinate   │
│  x → indent level           │
│  gap → tab detection         │
│  font size → heading level   │
└─────────────────────────────┘
         │
         ▼
      HTML Output
```

---

## 📦 Stack ที่ใช้

| ชั้น | Tool | หน้าที่ |
|------|------|---------|
| **อ่าน text** | `docling-parse` | ได้ text + x,y coordinate แม่น |
| **OCR scan** | `EasyOCR` lang=th,en | อ่าน PDF scan ภาษาไทย |
| **ตาราง** | `docling` TableFormer | row/col structure |
| **Merge** | BBox overlap | จับคู่ Thai text เข้าตาราง |
| **HTML** | CSS indent classes | แสดงผล indent/tab/heading |

---

## 🔑 หลักการ 5 ข้อ

**1. ใช้ docling-parse อ่าน text เสมอ**
```
→ ได้ x coordinate ที่แปลงเป็น indent ได้
→ ไม่ให้ AI normalize ทำ tab/indent หาย
```

**2. ใช้ docling เฉพาะ TableFormer + ปิด OCR**
```python
pipeline_options.do_ocr = False
pipeline_options.do_table_structure = True
pipeline_options.table_structure_options.do_cell_matching = False
```

**3. Map text เข้าตารางด้วย BBox overlap ≥ 30%**
```
→ ได้ตารางที่ถูก structure + text ภาษาไทยแม่น
```

**4. แปลง x-coordinate → indent level ด้วย clustering**
```
x=72  → indent-0 (margin ปกติ)
x=90  → indent-1
x=108 → indent-2
x=126 → indent-3
```

**5. Detect tab จาก gap ระหว่าง cells**
```
gap > 10px → แทรก tab
gap > 3px  → แทรก space
```

---

## ⚠️ สิ่งที่ต้องระวัง

```
PDF scan คุณภาพต่ำ  →  EasyOCR อาจผิด เพิ่ม image preprocessing ก่อน
Font ฝัง custom     →  docling-parse อาจ decode ผิด ลอง pdfplumber แทน
ตารางซับซ้อน        →  เพิ่ม Azure Document Intelligence สำหรับ production
เอกสาร 2 คอลัมน์   →  ต้องแยก detect column ก่อน group lines
```

---

## 🚀 ถ้าจะทำ Production จริง

```
งบน้อย / on-premise  →  docling-parse + EasyOCR + docling TableFormer
งบมี / cloud ได้       →  Azure Document Intelligence (ภาษาไทยดีที่สุด)
ต้องการ RAG ด้วย      →  ต่อ output MD/JSON เข้า LlamaIndex หรือ LangChain
เอกสารเยอะมาก         →  ใช้ docling batch mode + queue (Celery/Redis)
```

> 💡 **สรุปสั้นที่สุด:** `docling-parse` อ่าน text → `docling TableFormer` จับตาราง → `BBox merge` รวมกัน → `CSS indent` แสดงผล HTML นี่คือวิธีที่ได้ทั้งภาษาไทยแม่น ตารางถูก และ format ใกล้เคียงต้นฉบับที่สุดครับ