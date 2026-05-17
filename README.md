# Thai Legal Document OCR POC

POC สำหรับอ่านเอกสารราชการ/กฎหมายภาษาไทยจากไฟล์ `DOCX`, `PDF text`, และ `PDF scan` ด้วย **Docling + EasyOCR** แล้วทำ

1. Extraction
2. Thai normalization
3. AI correction
4. Human review
5. Export เป็น JSON พร้อมใช้ต่อกับ RAG

> เวอร์ชันนี้ยัง **ไม่ผูกฐานข้อมูลถาวร** และยัง **ไม่ทำ Elasticsearch / Vector DB**
> เป้าหมายคือพิสูจน์ว่า pipeline อ่านภาษาไทยได้ถูกต้อง, แก้สระ/เลขไทยได้ดี, คนตรวจแก้ง่าย, และสามารถส่งออก JSON ที่พร้อมใช้ต่อได้

---

## 1) เป้าหมายของ POC

ระบบนี้ต้องตอบคำถามต่อไปนี้ให้ได้ก่อนเริ่มทำ Production:

- Docling + EasyOCR อ่านเอกสารไทยได้ดีพอหรือไม่
- เอกสารที่มีตาราง, รูปภาพ, ย่อหน้า, เลขไทย, และเว้นวรรคแบบราชการยังรักษาโครงสร้างได้หรือไม่
- Thai normalization ช่วยลดปัญหา `สระลอย`, `วรรณยุกต์สลับ`, `ํา`/`ำ`, และการเว้นวรรคผิดได้มากแค่ไหน
- AI correction ช่วยลดภาระคนตรวจได้จริงหรือไม่
- Reviewer สามารถแก้ไขและ approve output ได้ง่ายหรือไม่
- JSON ที่ส่งออกพร้อมสำหรับ chunking / RAG หรือไม่

---

## 2) Tech Stack ของ POC

### Frontend
- **Vue 3 + Vite + TypeScript**
- หน้าที่: upload, preview, review, edit, approve, export JSON

### Backend API
- **Laravel 12 + PHP 8.3**
- หน้าที่: รับไฟล์, สร้าง job, เรียก Python service, เก็บสถานะชั่วคราว, เสิร์ฟผลให้ UI

### OCR / Extraction Service
- **Python 3.11**
- **FastAPI**
- **Docling** สำหรับ parse เอกสาร
- **EasyOCR** สำหรับ OCR ภาษาไทยใน scanned PDF / image PDF
- **PyThaiNLP** สำหรับ Thai normalization

### Queue / Async
- **Redis**
- Laravel queue ใช้กระจายงานที่ใช้เวลานาน เช่น extraction / OCR

### Storage (POC)
- Shared volume / local file storage
- เก็บไฟล์ต้นฉบับ, page images, intermediate JSON, final JSON

---

## 3) หลักการออกแบบ

### 3.1 แยกข้อความเป็น 4 ชั้นเสมอ
ทุก block ต้องเก็บข้อความ 4 แบบ:

- `raw_text` = ข้อความจาก Docling / OCR โดยตรง
- `normalized_text` = ผ่านกฎ Thai normalization แล้ว
- `ai_suggested_text` = ข้อความที่ AI เสนอแก้
- `approved_text` = ข้อความที่ reviewer ยืนยันแล้ว

เหตุผล:
- debug ได้ว่า error มาจาก OCR หรือ normalization หรือ AI
- reviewer เทียบแต่ละชั้นได้
- ต่อ RAG ได้จาก `approved_text` โดยไม่เสีย traceability

### 3.2 เก็บเป็น block ไม่ใช่ text ยาวก้อนเดียว
เอกสารต้องแตกเป็น block เช่น:
- `title`
- `section_header`
- `paragraph`
- `list_item`
- `table`
- `figure_caption`
- `footnote`

เหตุผล:
- review ง่ายกว่า
- คุมการแก้ไข format ได้
- ต่อ RAG ง่าย
- ใช้ metadata ต่อ block ได้

### 3.3 ให้ AI ช่วยเฉพาะส่วนที่เสี่ยง
AI ไม่ควร rewrite ทั้งเอกสาร เพราะเอกสารกฎหมายต้องรักษาถ้อยคำเดิมให้มากที่สุด

AI ควรทำงานเฉพาะกรณี:
- OCR confidence ต่ำ
- พบ pattern ไทยผิดปกติ
- ตารางอ่านเพี้ยน
- heading แตก
- เลขไทย / มาตรา ผิดรูป

---

## 4) Architecture ระดับ POC

```text
┌──────────────────────┐
│ Vue Review Frontend  │
│ upload / preview     │
│ edit / approve       │
└──────────┬───────────┘
           │ HTTP
           ▼
┌──────────────────────┐
│ Laravel API          │
│ upload               │
│ job orchestration    │
│ review/export API    │
└───────┬────────┬─────┘
        │        │
        │        └──────────────┐
        │                       │
        ▼                       ▼
┌──────────────────────┐   ┌──────────────────────┐
│ Redis Queue          │   │ Shared Storage       │
│ process jobs         │   │ files / json / pages │
└──────────┬───────────┘   └──────────────────────┘
           │
           ▼
┌───────────────────────────────┐
│ Python OCR Service            │
│ Docling + EasyOCR             │
│ Thai normalize + AI suggest   │
└───────────────────────────────┘
```

---

## 5) Workflow แบบ end-to-end

### Step 1: Upload
1. ผู้ใช้ upload ไฟล์ผ่าน Vue
2. Laravel รับไฟล์และสร้าง `document_id`
3. บันทึกไฟล์ลง `storage/app/poc/uploads`
4. สร้าง job `extract_document`

### Step 2: Detect file type
Python service หรือ Laravel ระบุประเภทไฟล์:
- `docx`
- `pdf_text`
- `pdf_scan`

### Step 3: Extraction
- `docx` → parse ตรงด้วย Docling
- `pdf_text` → parse ด้วย Docling โดย **ไม่เปิด OCR ก่อน**
- `pdf_scan` → parse ด้วย Docling + EasyOCR

### Step 4: Build structured blocks
แปลงผลลัพธ์จาก Docling ให้เป็น block structure มาตรฐานของระบบ

### Step 5: Thai normalization
รัน normalization ต่อ block
- reorder สระ / วรรณยุกต์
- ลบ zero-width / duplicate spaces
- แก้ pattern ไทยเพี้ยนที่พบบ่อย
- แก้ `ํา` → `ำ` ตามกฎ normalize และ custom rule เพิ่มเติม

### Step 6: AI correction
เฉพาะ block ที่:
- confidence ต่ำ
- โดน flag ว่ามี error pattern
- reviewer ขอ re-run AI

### Step 7: Human review
Vue เปิดหน้า review แล้วแสดง:
- ต้นฉบับหน้าเอกสาร
- block ที่เลือก
- raw / normalized / ai_suggested / approved

### Step 8: Export
เมื่อ approve แล้ว ระบบ export JSON final สำหรับ RAG

---

## 6) โครงสร้างโฟลเดอร์ที่แนะนำ

```text
thai-legal-ocr-poc/
├─ docker-compose.yml
├─ .env.example
├─ README.md
├─ apps/
│  ├─ api-laravel/
│  │  ├─ app/
│  │  │  ├─ Http/
│  │  │  │  ├─ Controllers/
│  │  │  │  │  ├─ UploadController.php
│  │  │  │  │  ├─ ReviewController.php
│  │  │  │  │  └─ ExportController.php
│  │  │  │  ├─ Requests/
│  │  │  │  └─ Resources/
│  │  │  ├─ Jobs/
│  │  │  │  ├─ ExtractDocumentJob.php
│  │  │  │  └─ ReprocessBlockJob.php
│  │  │  ├─ Services/
│  │  │  │  ├─ DocumentPipelineClient.php
│  │  │  │  ├─ ReviewStore.php
│  │  │  │  └─ ExportService.php
│  │  │  └─ Support/
│  │  ├─ routes/
│  │  │  └─ api.php
│  │  ├─ storage/
│  │  │  └─ app/poc/
│  │  │     ├─ uploads/
│  │  │     ├─ pages/
│  │  │     ├─ intermediate/
│  │  │     └─ exports/
│  │  └─ Dockerfile
│  │
│  ├─ ocr-service/
│  │  ├─ app/
│  │  │  ├─ main.py
│  │  │  ├─ api/
│  │  │  │  ├─ routes.py
│  │  │  │  └─ schemas.py
│  │  │  ├─ core/
│  │  │  │  ├─ config.py
│  │  │  │  └─ logger.py
│  │  │  ├─ services/
│  │  │  │  ├─ docling_service.py
│  │  │  │  ├─ ocr_pipeline.py
│  │  │  │  ├─ block_builder.py
│  │  │  │  ├─ thai_normalizer.py
│  │  │  │  ├─ ai_corrector.py
│  │  │  │  └─ exporter.py
│  │  │  ├─ models/
│  │  │  │  ├─ document.py
│  │  │  │  └─ block.py
│  │  │  └─ utils/
│  │  │     ├─ file_type.py
│  │  │     ├─ bbox.py
│  │  │     └─ text_rules.py
│  │  ├─ tests/
│  │  ├─ requirements.txt
│  │  └─ Dockerfile
│  │
│  └─ web-vue/
│     ├─ src/
│     │  ├─ api/
│     │  ├─ components/
│     │  │  ├─ UploadForm.vue
│     │  │  ├─ DocumentViewer.vue
│     │  │  ├─ BlockReviewPanel.vue
│     │  │  └─ DiffViewer.vue
│     │  ├─ pages/
│     │  │  ├─ UploadPage.vue
│     │  │  └─ ReviewPage.vue
│     │  ├─ stores/
│     │  ├─ types/
│     │  └─ utils/
│     └─ Dockerfile
│
├─ schemas/
│  ├─ document-output.schema.json
│  ├─ review-patch.schema.json
│  └─ export-rag.schema.json
│
├─ prompts/
│  ├─ codex-bootstrap.md
│  ├─ codex-backend.md
│  ├─ codex-python-pipeline.md
│  └─ codex-frontend.md
│
└─ samples/
   ├─ legal-docx/
   ├─ legal-pdf-text/
   └─ legal-pdf-scan/
```

---

## 7) docker-compose.yml

วางไฟล์นี้ไว้ที่ root ของ project

```yaml
version: "3.9"

services:
  nginx:
    image: nginx:1.27-alpine
    container_name: thai-ocr-nginx
    ports:
      - "8080:80"
    volumes:
      - ./infra/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - api-laravel
      - web-vue

  api-laravel:
    build:
      context: ./apps/api-laravel
      dockerfile: Dockerfile
    container_name: thai-ocr-api
    working_dir: /var/www/html
    volumes:
      - ./apps/api-laravel:/var/www/html
      - poc_storage:/var/www/html/storage/app/poc
    env_file:
      - .env
    depends_on:
      - redis
      - ocr-service
    ports:
      - "8000:8000"

  queue-worker:
    build:
      context: ./apps/api-laravel
      dockerfile: Dockerfile
    container_name: thai-ocr-queue-worker
    working_dir: /var/www/html
    command: php artisan queue:work --tries=1 --timeout=1800
    volumes:
      - ./apps/api-laravel:/var/www/html
      - poc_storage:/var/www/html/storage/app/poc
    env_file:
      - .env
    depends_on:
      - redis
      - api-laravel
      - ocr-service

  web-vue:
    build:
      context: ./apps/web-vue
      dockerfile: Dockerfile
    container_name: thai-ocr-web
    volumes:
      - ./apps/web-vue:/app
    ports:
      - "5173:5173"
    environment:
      - VITE_API_BASE_URL=http://localhost:8000/api

  ocr-service:
    build:
      context: ./apps/ocr-service
      dockerfile: Dockerfile
    container_name: thai-ocr-ocr-service
    volumes:
      - ./apps/ocr-service:/app
      - poc_storage:/data/poc
    env_file:
      - .env
    ports:
      - "8010:8010"

  redis:
    image: redis:7-alpine
    container_name: thai-ocr-redis
    ports:
      - "6379:6379"

volumes:
  poc_storage:
```

---

## 8) .env.example

```env
APP_NAME=ThaiLegalOcrPoc
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

QUEUE_CONNECTION=redis
CACHE_STORE=file
SESSION_DRIVER=file

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

OCR_SERVICE_BASE_URL=http://ocr-service:8010
POC_STORAGE_DISK=local

PYTHON_ENV=development
AI_CORRECTION_ENABLED=true
AI_CORRECTION_PROVIDER=mock
AI_CORRECTION_MODEL=gpt-4.1-mini
THAI_REVIEW_THRESHOLD=0.90
```

> หมายเหตุ: ถ้า AI correction ยังไม่เชื่อม provider จริง ให้ใช้ `mock` ก่อน

---

## 9) Development Optimizations

### 9.1 Fast Build & Hot Reload

The project is optimized for fast development with:

- **Multi-stage builds**: Docker dependencies cached separately
- **Vite polling**: File change detection for Docker volumes
- **Build caching**: Reuse previous builds when possible

#### Quick Start (Development)

```bash
# Start all services with hot reload
docker-compose up -d

# Frontend: http://localhost:5173 (auto-reloads on file changes)
# Backend: http://localhost:8000
# OCR Service: http://localhost:8010
```

#### Fast Rebuild Commands

```bash
# Rebuild specific service with cache
./scripts/fast-rebuild.sh ocr      # OCR service only
./scripts/fast-rebuild.sh laravel  # Laravel app only
./scripts/fast-rebuild.sh vite     # Restart Vite only

# Or on Windows
scripts\fast-rebuild.bat ocr
```

#### Hot Reload Features

- **Vue Components**: Auto-reload when saving `.vue` files
- **CSS Changes**: Instant style updates
- **PHP/Laravel**: Auto-restart on file changes
- **Python/OCR**: Auto-reload with `--reload` flag

### 9.2 Performance Tips

1. **First Build**: May take 2-3 minutes (installs all dependencies)
2. **Subsequent Builds**: 30-60 seconds (uses cached layers)
3. **File Changes**: Instant reload (1-2 seconds with polling)
4. **Windows Users**: Vite polling enabled for Docker volume compatibility

### 9.3 Build Optimization Details

- **OCR Service**: Multi-stage build with cached Python packages
- **Laravel App**: Composer dependencies cached separately
- **Vite**: File polling enabled for Docker volumes
- **Docker Compose**: Build cache and parallel builds enabled

---

## 10) Dockerfile ที่แนะนำ

### 9.1 Laravel Dockerfile

```dockerfile
FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip zip curl libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring zip pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

CMD php artisan serve --host=0.0.0.0 --port=8000
```

### 9.2 Python OCR Service Dockerfile

```dockerfile
FROM python:3.11-slim

RUN apt-get update && apt-get install -y \
    tesseract-ocr \
    tesseract-ocr-tha \
    libgl1 \
    libglib2.0-0 \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY . /app

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8010", "--reload"]
```

### 9.3 Vue Dockerfile

```dockerfile
FROM node:20-alpine

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]
```

---

## 10) Python requirements.txt

```txt
fastapi
uvicorn[standard]
pydantic
python-multipart
httpx
pydantic-settings
orjson
numpy
Pillow
PyMuPDF
pythainlp
easyocr
opencv-python-headless
docling
```

> ภายหลังค่อย lock version ด้วย `pip-tools` หรือ `uv` ได้

---

## 11) JSON Schema ที่ต้องมี

### 11.1 document-output.schema.json
ใช้เป็นผลลัพธ์หลักของ pipeline หลัง extraction + normalization + AI suggestion

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://example.local/schemas/document-output.schema.json",
  "title": "DocumentOutput",
  "type": "object",
  "required": [
    "document_id",
    "source_file",
    "source_type",
    "language",
    "pages",
    "summary"
  ],
  "properties": {
    "document_id": { "type": "string" },
    "source_file": { "type": "string" },
    "source_type": {
      "type": "string",
      "enum": ["docx", "pdf_text", "pdf_scan"]
    },
    "language": { "type": "string", "const": "th" },
    "summary": {
      "type": "object",
      "required": ["page_count", "block_count", "review_required_count"],
      "properties": {
        "page_count": { "type": "integer", "minimum": 0 },
        "block_count": { "type": "integer", "minimum": 0 },
        "review_required_count": { "type": "integer", "minimum": 0 }
      },
      "additionalProperties": false
    },
    "pages": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["page_no", "blocks"],
        "properties": {
          "page_no": { "type": "integer", "minimum": 1 },
          "image_path": { "type": ["string", "null"] },
          "blocks": {
            "type": "array",
            "items": {
              "type": "object",
              "required": [
                "block_id",
                "type",
                "reading_order",
                "raw_text",
                "normalized_text",
                "ai_suggested_text",
                "approved_text",
                "confidence",
                "needs_review"
              ],
              "properties": {
                "block_id": { "type": "string" },
                "type": {
                  "type": "string",
                  "enum": [
                    "title",
                    "section_header",
                    "paragraph",
                    "list_item",
                    "table",
                    "figure_caption",
                    "footnote",
                    "unknown"
                  ]
                },
                "bbox": {
                  "type": ["array", "null"],
                  "items": { "type": "number" },
                  "minItems": 4,
                  "maxItems": 4
                },
                "reading_order": { "type": "integer", "minimum": 0 },
                "raw_text": { "type": "string" },
                "normalized_text": { "type": "string" },
                "ai_suggested_text": { "type": "string" },
                "approved_text": { "type": "string" },
                "confidence": { "type": "number", "minimum": 0, "maximum": 1 },
                "needs_review": { "type": "boolean" },
                "flags": {
                  "type": "array",
                  "items": { "type": "string" }
                },
                "meta": {
                  "type": "object",
                  "properties": {
                    "section_path": { "type": ["string", "null"] },
                    "table_html": { "type": ["string", "null"] }
                  },
                  "additionalProperties": true
                }
              },
              "additionalProperties": false
            }
          }
        },
        "additionalProperties": false
      }
    }
  },
  "additionalProperties": false
}
```

### 11.2 review-patch.schema.json
ใช้ตอน reviewer กดแก้ไข block

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://example.local/schemas/review-patch.schema.json",
  "title": "ReviewPatch",
  "type": "object",
  "required": ["document_id", "page_no", "block_id", "approved_text"],
  "properties": {
    "document_id": { "type": "string" },
    "page_no": { "type": "integer", "minimum": 1 },
    "block_id": { "type": "string" },
    "approved_text": { "type": "string" },
    "approved_by": { "type": ["string", "null"] },
    "notes": { "type": ["string", "null"] },
    "mark_uncertain": { "type": "boolean" }
  },
  "additionalProperties": false
}
```

### 11.3 export-rag.schema.json
ใช้ตอน export ผลสุดท้ายพร้อมส่งเข้า RAG

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://example.local/schemas/export-rag.schema.json",
  "title": "RagExport",
  "type": "object",
  "required": ["document_id", "chunks"],
  "properties": {
    "document_id": { "type": "string" },
    "document_title": { "type": ["string", "null"] },
    "chunks": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["chunk_id", "page_no", "block_ids", "text"],
        "properties": {
          "chunk_id": { "type": "string" },
          "page_no": { "type": "integer", "minimum": 1 },
          "block_ids": {
            "type": "array",
            "items": { "type": "string" }
          },
          "section_path": { "type": ["string", "null"] },
          "text": { "type": "string" },
          "meta": {
            "type": "object",
            "additionalProperties": true
          }
        },
        "additionalProperties": false
      }
    }
  },
  "additionalProperties": false
}
```

---

## 12) API Flow ที่แนะนำ

### 12.1 Upload document
**POST** `/api/documents`

Request:
- multipart/form-data
- field: `file`

Response:

```json
{
  "document_id": "doc_20260321_0001",
  "status": "queued"
}
```

### 12.2 Get processing status
**GET** `/api/documents/{documentId}`

Response:

```json
{
  "document_id": "doc_20260321_0001",
  "status": "processing",
  "progress": 45,
  "current_step": "thai_normalization"
}
```

### 12.3 Get extracted document JSON
**GET** `/api/documents/{documentId}/review`

Response: ตาม `document-output.schema.json`

### 12.4 Update reviewed block
**PATCH** `/api/documents/{documentId}/blocks/{blockId}`

Body:

```json
{
  "page_no": 1,
  "approved_text": "มาตรา ๑ ให้ใช้พระราชบัญญัตินี้...",
  "approved_by": "reviewer01",
  "notes": "แก้สระและเลขไทย",
  "mark_uncertain": false
}
```

### 12.5 Re-run AI suggestion for block
**POST** `/api/documents/{documentId}/blocks/{blockId}/reprocess`

Body:

```json
{
  "page_no": 1,
  "mode": "ai_correction"
}
```

### 12.6 Export final RAG JSON
**POST** `/api/documents/{documentId}/export`

Response:

```json
{
  "document_id": "doc_20260321_0001",
  "status": "exported",
  "export_path": "storage/app/poc/exports/doc_20260321_0001.rag.json"
}
```

---

## 13) Contract ระหว่าง Laravel กับ Python OCR Service

Laravel เรียก Python service ผ่าน internal HTTP

### Endpoint ฝั่ง Python

#### `POST /pipeline/extract`
Input:

```json
{
  "document_id": "doc_20260321_0001",
  "file_path": "/data/poc/uploads/doc_20260321_0001.pdf",
  "enable_ai_correction": true
}
```

Output:
- ตาม `document-output.schema.json`

#### `POST /pipeline/reprocess-block`
Input:

```json
{
  "document_id": "doc_20260321_0001",
  "page_no": 1,
  "block_id": "1-3",
  "mode": "ai_correction"
}
```

Output:

```json
{
  "document_id": "doc_20260321_0001",
  "page_no": 1,
  "block_id": "1-3",
  "ai_suggested_text": "...",
  "confidence": 0.88,
  "flags": ["thai_vowel_fix"]
}
```

---

## 14) Python Service Design

### 14.1 FastAPI routes

- `POST /pipeline/extract`
- `POST /pipeline/reprocess-block`
- `GET /health`

### 14.2 Service responsibilities

#### `docling_service.py`
- ตรวจชนิดไฟล์
- เรียก Docling converter
- map ผลลัพธ์เป็น intermediate object

#### `ocr_pipeline.py`
- ใช้ EasyOCR กับ scanned pages
- สร้าง page image / crop ถ้าจำเป็น

#### `block_builder.py`
- แปลง output ของ Docling เป็น block structure มาตรฐาน

#### `thai_normalizer.py`
- รัน PyThaiNLP normalize
- เติม custom rules ของภาษาไทยราชการ

#### `ai_corrector.py`
- เลือก block ที่ต้องเข้า AI
- ส่ง prompt ให้ provider
- รับ structured output กลับมา

#### `exporter.py`
- รวมผลและบันทึก JSON ลง disk

---

## 15) แนวทางเขียน code ให้ clean

### 15.1 หลักของ Laravel
- Controller บาง: รับ request / return response เท่านั้น
- Logic อยู่ใน `Services` และ `Jobs`
- Validation แยกใน `FormRequest`
- API response ใช้ `Resource` หรือ response transformer
- หลีกเลี่ยง fat controller

### 15.2 หลักของ Python
- route บาง
- logic อยู่ใน service layer
- model ใช้ Pydantic ชัดเจน
- ฟังก์ชันหนึ่งทำงานเดียว
- แยก pure functions สำหรับ normalize / text rules
- ห้าม hardcode path กระจายหลายที่

### 15.3 Naming
- ใช้ชื่อแบบชัดเจน เช่น `extract_document`, `normalize_block_text`, `export_rag_json`
- อย่าใช้ชื่อสั้นเกิน เช่น `proc`, `tmp2`, `fixer2`

### 15.4 Error handling
- Python service คืน `error_code` และ `message` แบบ predictable
- Laravel แปลงเป็น HTTP response กลาง
- ไฟล์เสีย / OCR fail / unsupported format ต้องแยก status ชัด

### 15.5 Logging
ต้องมี `document_id` ใน log ทุกขั้นตอน

---

## 16) Thai Normalization Rules ที่ควรทำใน POC

ลำดับการ normalize ที่แนะนำ:

1. trim whitespace
2. remove zero-width spaces
3. remove duplicated spaces
4. reorder vowels and tone marks
5. remove repeated signs / repeated vowels
6. remove dangling non-base marks at string start
7. convert known invalid sequence เช่น `ํา` → `ำ`
8. normalize Thai digits field แยกต่างหากสำหรับ search/RAG metadata (แต่ไม่ทับข้อความ canonical)
9. custom domain rules เช่น `ม าตรา` → `มาตรา` ในกรณี OCR split แบบง่าย

> ระวัง: ห้าม aggressive replace จนถ้อยคำกฎหมายเปลี่ยนความหมาย

---

## 17) AI Correction Strategy

### 17.1 Block selection
ให้ AI ทำงานเฉพาะ block ที่มีเงื่อนไขใดเงื่อนไขหนึ่ง:
- confidence < threshold
- มี `flags` จาก normalizer
- block type เป็น `table` หรือ `section_header`
- reviewer กด reprocess

### 17.2 Prompt หลัก
AI ต้อง:
- รักษาความหมายเดิม
- ไม่สรุป
- ไม่ paraphrase
- แก้เฉพาะ OCR mistakes / formatting mistakes
- ตอบกลับเป็น JSON เท่านั้น

### 17.3 Structured output

```json
{
  "suggested_text": "...",
  "reason": ["fix_tone_mark_order", "fix_thai_vowel_combination"],
  "changed_tokens": [{"from": "บญญตั ิ", "to": "บัญญัติ"}],
  "confidence": 0.86
}
```

---

## 18) Vue Review UI ที่ควรมี

### หน้า Upload
- เลือกไฟล์
- แสดงประเภทไฟล์
- กด upload
- แสดงสถานะ queued / processing / done

### หน้า Review
ซ้าย:
- page image / PDF preview
- highlight block ที่เลือก

ขวา:
- raw text
- normalized text
- ai suggestion
- approved text (editable)
- diff view

ปุ่ม:
- Accept normalized
- Accept AI
- Save approved
- Mark uncertain
- Re-run AI
- Export JSON

---

## 19) Definition of Done สำหรับ POC

POC ถือว่าสำเร็จเมื่อ:

- upload ได้ 3 ประเภทไฟล์หลัก
- แยก `docx`, `pdf_text`, `pdf_scan` ได้ถูก
- ได้ block output ที่มี `raw_text`, `normalized_text`, `ai_suggested_text`, `approved_text`
- reviewer แก้ block ได้
- export JSON ได้ตาม schema
- มี sample เอกสารไทยอย่างน้อย 10–20 ไฟล์สำหรับ benchmark

---

## 20) ขั้นตอนการพัฒนาแบบแนะนำ

### Milestone 1: Bootstrap project
- สร้าง Laravel, Vue, FastAPI
- เปิด Docker Compose ให้รันได้ทั้งหมด
- เช็ก health endpoint

### Milestone 2: Upload flow
- upload file ผ่าน Laravel
- บันทึกไฟล์ลง storage
- dispatch queue job

### Milestone 3: Extraction pipeline
- Python service รับ file path
- detect file type
- ใช้ Docling parse เอกสาร
- สร้าง block JSON เบื้องต้น

### Milestone 4: Thai normalization
- ใส่ PyThaiNLP normalize
- เพิ่ม custom rules
- set flags / confidence

### Milestone 5: Review UI
- แสดง block และ text ทั้ง 4 ชั้น
- patch approved text ได้

### Milestone 6: AI correction
- ใช้ provider แบบ mock ก่อน
- ต่อ provider จริงภายหลัง
- เพิ่ม reprocess block endpoint

### Milestone 7: Export JSON
- สร้าง RAG-ready JSON
- validate กับ schema

---

## 21) ลำดับการให้ Codex ทำงาน

ให้ Codex ทำทีละ milestone ไม่ใช่สร้างทั้งระบบใน prompt เดียว

### Prompt 1: Bootstrap repository
ใช้ไฟล์ `prompts/codex-bootstrap.md`

### Prompt 2: Laravel API
ใช้ไฟล์ `prompts/codex-backend.md`

### Prompt 3: Python pipeline
ใช้ไฟล์ `prompts/codex-python-pipeline.md`

### Prompt 4: Vue frontend
ใช้ไฟล์ `prompts/codex-frontend.md`

---

## 22) Prompt สำหรับ Codex

### 22.1 codex-bootstrap.md

```md
You are generating a clean monorepo for a Thai legal document OCR POC.

Requirements:
- Monorepo structure exactly as described in README.md
- Services:
  - Laravel 12 API
  - Vue 3 + Vite + TypeScript frontend
  - FastAPI Python OCR service
- Docker Compose must run all services locally
- Use clean architecture principles
- Keep controllers/routes thin
- Put business logic in service classes
- Add health endpoints for every service
- Add placeholder tests
- Do not add database integration yet
- Use local/shared storage only
- Output should be production-minded, readable, and minimal

Tasks:
1. Create directory structure
2. Create Dockerfiles
3. Create docker-compose.yml
4. Create .env.example
5. Add minimal bootable apps for each service
6. Add README references where needed
```

### 22.2 codex-backend.md

```md
Implement the Laravel API for the Thai legal OCR POC.

Requirements:
- Laravel 12
- Redis queue
- No database persistence yet
- Store uploaded files and JSON artifacts under storage/app/poc
- Build endpoints:
  - POST /api/documents
  - GET /api/documents/{documentId}
  - GET /api/documents/{documentId}/review
  - PATCH /api/documents/{documentId}/blocks/{blockId}
  - POST /api/documents/{documentId}/blocks/{blockId}/reprocess
  - POST /api/documents/{documentId}/export
- Dispatch queue jobs for extraction/reprocessing
- Call Python OCR service through a dedicated client class
- Keep controllers thin
- Use request validation classes
- Return consistent JSON responses
- Implement file-based status tracking for the POC

Deliverables:
- routes/api.php
- controllers
- request classes
- jobs
- services
- health endpoint
- minimal tests
```

### 22.3 codex-python-pipeline.md

```md
Implement the Python OCR pipeline service for Thai legal documents.

Requirements:
- FastAPI
- Python 3.11
- Docling for document conversion
- EasyOCR for OCR when needed
- PyThaiNLP for Thai normalization
- Provide endpoints:
  - GET /health
  - POST /pipeline/extract
  - POST /pipeline/reprocess-block
- Detect file type: docx, pdf_text, pdf_scan
- Use Docling for parsing
- Use EasyOCR for scanned pages
- Output JSON matching document-output.schema.json
- Add a thai_normalizer module with pure functions
- Add ai_corrector module with mock provider first
- Add structured logging with document_id in every step
- Add tests where practical

Important constraints:
- Do not integrate any real LLM provider yet
- Do not use a database
- Use local file output only
- Code must be clean, typed, and modular
```

### 22.4 codex-frontend.md

```md
Implement the Vue frontend for the Thai legal OCR POC.

Requirements:
- Vue 3 + Vite + TypeScript
- Pages:
  - UploadPage
  - ReviewPage
- Features:
  - Upload document
  - Poll document status
  - Load review JSON
  - Show block list
  - Show raw_text, normalized_text, ai_suggested_text, approved_text
  - Edit approved_text
  - Save block patch
  - Trigger reprocess block
  - Trigger export
- Components:
  - UploadForm
  - DocumentViewer
  - BlockReviewPanel
  - DiffViewer
- Keep state management simple
- Use strongly typed API client
- Use clean and readable component structure
- Styling can be minimal
```

---

## 23) ตัวอย่าง JSON ที่ pipeline ควรส่งออก

```json
{
  "document_id": "doc_20260321_0001",
  "source_file": "example_scan.pdf",
  "source_type": "pdf_scan",
  "language": "th",
  "summary": {
    "page_count": 2,
    "block_count": 5,
    "review_required_count": 2
  },
  "pages": [
    {
      "page_no": 1,
      "image_path": "/data/poc/pages/doc_20260321_0001/page-1.png",
      "blocks": [
        {
          "block_id": "1-1",
          "type": "title",
          "bbox": [12, 20, 580, 90],
          "reading_order": 1,
          "raw_text": "พระราชบญญตั ิ",
          "normalized_text": "พระราชบัญญัติ",
          "ai_suggested_text": "พระราชบัญญัติ",
          "approved_text": "พระราชบัญญัติ",
          "confidence": 0.82,
          "needs_review": true,
          "flags": ["thai_vowel_fix", "low_confidence"],
          "meta": {
            "section_path": null,
            "table_html": null
          }
        }
      ]
    }
  ]
}
```

---

## 24) การทดสอบที่ควรมีตั้งแต่แรก

### Unit tests
- normalize ข้อความไทยเพี้ยน
- detect file type
- block builder map ข้อมูลได้ถูก
- schema validation

### Integration tests
- upload → queue → python extract → review JSON
- patch block → export JSON

### Manual test set
ควรมีตัวอย่างเอกสารอย่างน้อย:
- DOCX 3 ไฟล์
- PDF text 3 ไฟล์
- PDF scan 4 ไฟล์

---

## 25) ข้อควรระวัง

- อย่าทำ AI rewrite ทั้งเอกสาร
- อย่า merge raw กับ approved โดยไม่มี trace
- อย่าใช้ regex แก้ไทยแบบ aggressive เกินไป
- อย่าผูกกับ DB ตั้งแต่แรก
- อย่าทำ search ก่อน extraction quality ผ่าน

---

## 26) Roadmap หลัง POC ผ่าน

เมื่อ POC ผ่านแล้วค่อยเพิ่ม:
- MongoDB สำหรับเก็บ document/version ถาวร
- Elasticsearch สำหรับ keyword search ภาษาไทย
- vector store สำหรับ semantic retrieval
- role-based approval
- metrics dashboard
- OCR benchmark dashboard

---

## 27) คำสั่งเริ่มต้นใช้งาน

```bash
cp .env.example .env
```

เพิ่มค่า LandingAI token ใน `.env`:

```bash
VISION_AGENT_API_KEY=your_landingai_token
LANDINGAI_BASE_URL=https://api.va.landing.ai
LANDINGAI_PARSE_MODEL=dpt-2-latest
LANDINGAI_TIMEOUT_SECONDS=60
```

รันแบบ Dev (Docker compose):

```bash
docker compose --env-file .env up --build
```

รันแบบ Deploy (Docker compose detached):

```bash
docker compose --env-file .env up -d --build
docker compose --env-file .env ps
docker compose --env-file .env logs -f ocr-service
```

หยุดระบบ:

```bash
docker compose --env-file .env down
```

หลังจากระบบขึ้นแล้ว:
- Laravel API: `http://localhost:8000`
- Vue: `http://localhost:5173`
- Python OCR Service: `http://localhost:8010`

---

## 28) ลำดับการสร้างโปรเจกต์จริง

1. ใช้ Codex รัน prompt `codex-bootstrap.md`
2. ตรวจว่า docker compose ขึ้นครบ
3. ใช้ Codex รัน prompt `codex-backend.md`
4. ใช้ Codex รัน prompt `codex-python-pipeline.md`
5. ใช้ Codex รัน prompt `codex-frontend.md`
6. ทดสอบด้วย sample docs
7. ปรับ quality rules ภาษาไทยจากผลจริง

---

## 29) สรุป

POC นี้จงใจออกแบบให้เล็ก แต่ครบแกนสำคัญที่สุดของระบบจริง:
- อ่านเอกสารไทย
- แก้ความเพี้ยนของ OCR
- ให้ AI ช่วยเท่าที่จำเป็น
- ให้คนตรวจแก้ได้
- ส่งออก JSON ที่พร้อมทำ RAG

เมื่อแกนนี้ผ่านแล้วจึงค่อยต่อยอดไป storage ถาวร, search, และ production architecture
