# Thai Legal Document OCR and e-Law Platform

ระบบ POC สำหรับนำเข้าเอกสารกฎหมายภาษาไทย, แยกข้อความจาก `DOCX`, `PDF text`, `PDF scan`, ตรวจทานเนื้อหา, กรอก metadata กฎหมาย, เชื่อมโยงความสัมพันธ์, export เอกสาร และเปิดค้นหาในหน้า e-Law.

ระบบหลักเป็น Laravel monolith ที่รวม API และ Vue frontend ไว้ใน `apps/app-laravel` และมี service แยกสำหรับ OCR/PDF render.

> 📑 **LawMeta field reference:** ประเภท/สถานะเอกสารมีหลายฟิลด์ที่ทับซ้อนกัน (`law_type`/`source`/`document_type`, คู่เดี่ยว↔อาเรย์, และ 4 แกนสถานะ) — ดูแหล่งความจริงและกฎการใช้งานที่ [`docs/law-metadata-reference.md`](docs/law-metadata-reference.md) ก่อนเพิ่ม logic ที่อ่าน/เขียนฟิลด์เหล่านี้.

## Tool Stack

### Runtime

- Docker Compose: รัน service ทั้งชุด
- Laravel 12 + PHP 8.2/8.3: API, queue, workflow, storage, search orchestration
- Vue 3 + Vite + TypeScript: frontend public/admin/review UI
- Vuetify 4 + Material Design Icons: UI component system
- Pinia: frontend state management
- TipTap: document review/compose rich text editor
- Redis 7: queue backend
- MongoDB: persistent document/blob/review store
- Elasticsearch 8.13: law search, facets, suggestions
- Python 3.11 + FastAPI: OCR/extraction service
- Docling, EasyOCR, PyThaiNLP: document parsing/OCR/Thai normalization
- Node.js + Express + Puppeteer: PDF export service

### Optional External Providers

- Google Gemini Vision OCR: optional scan OCR fallback
- LandingAI ADE Parse: optional scan/layout parser fallback

## Service Ports

Default host ports are controlled by `.env`.

| Service | URL |
| --- | --- |
| Laravel app | `http://localhost:8500` |
| Vite dev server | `http://localhost:5173` |
| OCR service | `http://localhost:8010/health` |
| PDF service | `http://localhost:3001` |
| Elasticsearch | `http://localhost:9200` |
| MongoDB | `localhost:27017` |
| Redis | `localhost:6379` |

## First Time Setup

Requirements:

- Docker and Docker Compose
- Node.js 20+ and npm, only needed for local Vite/dev dependencies

Create `.env` at the repository root:

```bash
cp .env.example .env
```

Generate `APP_KEY` and put it into `.env`:

```bash
docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Install frontend dependencies for the Laravel app. The dev compose Vite container uses the bind-mounted `node_modules`, so this step is required for development:

```bash
cd apps/app-laravel
npm install
cd ../..
```

## Environment Example

This is a complete local development example for the root `.env`.

```dotenv
APP_NAME=ThaiLegalOcrPoc
APP_ENV=local
APP_KEY=base64:replace_with_generated_key
APP_DEBUG=true
APP_URL=http://localhost:8500

# Host ports
APP_HOST_PORT=8500
OCR_HOST_PORT=8010
PDF_HOST_PORT=3001
REDIS_HOST_PORT=6379
ELASTIC_HOST_PORT=9200
MONGO_HOST_PORT=27017
VITE_HOST_PORT=5173
VITE_DEV_SERVER_HOST=localhost

# Docker image names
LARAVEL_IMAGE=thai-ocr-laravel-app:local
OCR_IMAGE=thai-ocr-service:local
PDF_IMAGE=thai-ocr-pdf-service:local
MONGO_IMAGE=mongo:7

LOG_CHANNEL=stack
LOG_LEVEL=debug

FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=null
CACHE_STORE=file
SESSION_DRIVER=file

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

OCR_SERVICE_BASE_URL=http://ocr-service:8010
OCR_SHARED_STORAGE_ROOT=/data/poc
INTERNAL_CALLBACK_URL=http://laravel-app:8000/api/internal/pipeline-callback

PDF_SERVICE_URL=http://pdf-service:3001

AI_CORRECTION_ENABLED=true
AI_CORRECTION_PROVIDER=mock
AI_CORRECTION_MODEL=mock-thai-ocr
THAI_REVIEW_THRESHOLD=0.90
OCR_NORMALIZE_AUTOCORRECT_MIN_CONFIDENCE=0.85

PYTHON_ENV=development
DATA_ROOT=/data/poc

# Optional Google Gemini vision OCR
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.0-flash
GEMINI_TIMEOUT_SECONDS=120

# Optional LandingAI ADE Parse
VISION_AGENT_API_KEY=
LANDINGAI_BASE_URL=https://api.va.landing.ai
LANDINGAI_PARSE_MODEL=dpt-2-latest
LANDINGAI_TIMEOUT_SECONDS=60

ELASTIC_HOST=http://elasticsearch:9200
ELASTIC_INDEX=laws

MONGO_HOST=mongo
MONGO_PORT=27017
MONGO_DATABASE=poc
MONGO_USERNAME=
MONGO_PASSWORD=
```

Production should use:

```dotenv
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
APP_URL=https://your-domain.example
VITE_DEV_SERVER_HOST=your-domain.example
MONGO_IMAGE=mongo:7
AI_CORRECTION_PROVIDER=mock
```

If the deployment CPU is too old for `mongo:7`, use:

```dotenv
MONGO_IMAGE=mongo:4.4
```

## Start For Development

Development uses bind mounts and Vite HMR.

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

Open:

```text
http://localhost:8500
```

Useful development commands:

```bash
# See running containers
docker compose ps

# Tail Laravel logs
docker compose logs -f laravel-app

# Tail queue worker logs
docker compose logs -f queue-worker

# Tail Vite logs
docker compose logs -f laravel-vite

# Run Laravel tests
docker compose exec -T laravel-app php artisan test

# Run a focused test
docker compose exec -T laravel-app php artisan test --filter=LawSearchTest

# Run frontend typecheck
cd apps/app-laravel
npm run typecheck

# Run frontend production build locally
cd apps/app-laravel
npm run build -- --configLoader runner
```

Stop development services:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml down
```

## Start For Production

Production compose builds static frontend assets into the Laravel image and does not run the Vite dev server.

```bash
docker compose up -d --build
```

Open:

```text
http://localhost:8500
```

Check health:

```bash
curl http://localhost:8500/health
curl http://localhost:8500/api/health
curl http://localhost:8010/health
curl http://localhost:9200/_cluster/health
```

Scale queue workers when processing many documents:

```bash
docker compose up -d --scale queue-worker=3 --no-recreate
```

Restart after env changes:

```bash
docker compose up -d --build
```

Stop production services:

```bash
docker compose down
```

Remove persistent data volumes only when you intentionally want to delete all stored documents/search indexes:

```bash
docker compose down -v
```

## Main User Flows

1. Public landing page: `/`
2. Public law database: `/database`
3. Public law detail: `/law/{documentId}`
4. Admin dashboard: `/admin`
5. Admin upload: `/admin/upload`
6. Review extracted document: `/documents/{documentId}/review`
7. Compose/edit document: `/documents/{documentId}/compose`
8. Manage RAG sections: `/documents/{documentId}/rag`
9. Fill law metadata: `/documents/{documentId}/law-info`
10. Manage relations: `/documents/{documentId}/relations`
11. Export/result page: `/documents/{documentId}/result`

## Code Map

### Root

| Path | Purpose |
| --- | --- |
| `docker-compose.yml` | Production/runtime service graph for Laravel, queue worker, OCR, PDF, Redis, Elasticsearch, MongoDB |
| `docker-compose.dev.yml` | Development override with bind mounts and Vite HMR |
| `.env.example` | Root compose/runtime env template |
| `docs/superpowers/plans/` | Implementation notes/plans used during iterative UI/API changes |
| `schemas/` | JSON schemas for document/review/export payloads |
| `scripts/` | Utility scripts for maintenance/import/testing |

### Laravel App

| Path | Purpose |
| --- | --- |
| `apps/app-laravel/routes/web.php` | SPA web route fallback for public/admin/document pages |
| `apps/app-laravel/routes/api.php` | API route definitions for upload, review, search, export, relations, permissions |
| `apps/app-laravel/app/Http/Controllers/Api/UploadController.php` | Document upload/list/show API |
| `apps/app-laravel/app/Http/Controllers/Api/ReviewController.php` | Review document, block mutation, layout, workflow progress, relations/law meta updates |
| `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php` | Public law search, facets, Elasticsearch/file fallback search |
| `apps/app-laravel/app/Http/Controllers/Api/LawSuggestController.php` | Search suggestion endpoint with Elasticsearch and file fallback |
| `apps/app-laravel/app/Http/Controllers/Api/ReportController.php` | Admin dashboard/report summary data |
| `apps/app-laravel/app/Http/Controllers/Api/ExportController.php` | RAG JSON export and retry correction endpoints |
| `apps/app-laravel/app/Http/Controllers/Api/PdfExportController.php` | Render reviewed HTML to PDF through pdf-service |
| `apps/app-laravel/app/Http/Controllers/Api/WordExportController.php` | Word export endpoint |
| `apps/app-laravel/app/Jobs/ExtractDocumentJob.php` | Main async extraction job |
| `apps/app-laravel/app/Jobs/IngestRagJob.php` | Export/index ingestion job for RAG/search |
| `apps/app-laravel/app/Services/ReviewStore.php` | Central persistence layer for review documents, status files, metadata, relations |
| `apps/app-laravel/app/Services/DocumentPipelineClient.php` | HTTP client from Laravel to OCR service |
| `apps/app-laravel/app/Services/ExportService.php` | Build export JSON from reviewed document state |
| `apps/app-laravel/app/Services/DocumentHtmlService.php` | Build/sanitize reviewed document HTML |
| `apps/app-laravel/app/Services/Search/LawIndexer.php` | Convert law metadata/chunks into Elasticsearch documents |
| `apps/app-laravel/app/Services/Search/LawSearchService.php` | Elasticsearch law search query builder/parser |
| `apps/app-laravel/app/Services/Search/LawSuggestService.php` | Elasticsearch suggest query builder/parser |
| `apps/app-laravel/app/Services/Search/LawIndexDefinition.php` | Elasticsearch mapping definition |
| `apps/app-laravel/app/Services/Permissions/PermissionStore.php` | Permission group storage and validation |
| `apps/app-laravel/config/lookups.php` | Thai labels/options for law type, status, agencies, law groups |
| `apps/app-laravel/config/search.php` | Search host/index config |
| `apps/app-laravel/storage/app/poc/` | Runtime document storage inside the container/volume |

### Laravel Frontend

| Path | Purpose |
| --- | --- |
| `apps/app-laravel/resources/js/main.ts` | Vue app bootstrap |
| `apps/app-laravel/resources/js/router/index.ts` | Vue Router page map |
| `apps/app-laravel/resources/js/api/client.ts` | Browser API client for Laravel endpoints |
| `apps/app-laravel/resources/js/plugins/vuetify.ts` | Vuetify theme/plugin setup |
| `apps/app-laravel/resources/js/stores/documentStore.ts` | Current document/review/law meta state |
| `apps/app-laravel/resources/js/stores/lawSearchStore.ts` | Public database search state |
| `apps/app-laravel/resources/js/stores/uploadStore.ts` | Upload/progress state |
| `apps/app-laravel/resources/js/pages/public/PublicHomePage.vue` | Public landing page |
| `apps/app-laravel/resources/js/pages/public/LawDatabasePage.vue` | Public search/database page |
| `apps/app-laravel/resources/js/pages/law/LawPage.vue` | Public law detail page wrapper |
| `apps/app-laravel/resources/js/components/law/LawDocumentView.vue` | Law document reader/detail UI |
| `apps/app-laravel/resources/js/pages/admin/AdminDashboardPage.vue` | Admin dashboard |
| `apps/app-laravel/resources/js/pages/admin/AdminLawListPage.vue` | Admin law list/table |
| `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue` | Admin upload workflow entry |
| `apps/app-laravel/resources/js/pages/admin/AdminOcrQueuePage.vue` | OCR queue/log page |
| `apps/app-laravel/resources/js/pages/admin/AdminRelationsHubPage.vue` | Admin relations hub |
| `apps/app-laravel/resources/js/pages/review/ReviewPage.vue` | OCR review page |
| `apps/app-laravel/resources/js/pages/compose/ComposePage.vue` | Compose/edit page wrapper |
| `apps/app-laravel/resources/js/components/compose/DocumentComposeWorkspace.vue` | Main compose workspace |
| `apps/app-laravel/resources/js/pages/rag/RagPage.vue` | RAG section management page |
| `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue` | Law metadata form |
| `apps/app-laravel/resources/js/pages/law-relations/LawRelationsPage.vue` | Per-document relation editor |
| `apps/app-laravel/resources/js/components/shared/ELawNavbar.vue` | Public navbar |
| `apps/app-laravel/resources/js/components/shared/MainNav.vue` | Public nav links/active state |
| `apps/app-laravel/resources/js/components/shared/AppShell.vue` | Admin shell/navigation drawer |
| `apps/app-laravel/resources/js/components/shared/ThaiDatePicker.vue` | Thai/B.E. date picker wrapper |
| `apps/app-laravel/resources/js/types/document.ts` | Document/review/relation TypeScript types |
| `apps/app-laravel/resources/js/types/lawSearch.ts` | Search/facet/suggestion TypeScript types |

### OCR Service

| Path | Purpose |
| --- | --- |
| `apps/ocr-service/app/main.py` | FastAPI app bootstrap |
| `apps/ocr-service/app/api/routes.py` | OCR/extraction/reprocess/correction routes |
| `apps/ocr-service/app/api/schemas.py` | Pydantic API schemas |
| `apps/ocr-service/app/core/config.py` | OCR service settings/env |
| `apps/ocr-service/app/services/docling_service.py` | Docling integration |
| `apps/ocr-service/app/services/docx_parser.py` | DOCX parser |
| `apps/ocr-service/app/services/pdf_text_parser.py` | Text PDF parser |
| `apps/ocr-service/app/services/ocr_pipeline.py` | OCR pipeline orchestration |
| `apps/ocr-service/app/services/block_builder.py` | Build normalized document blocks |
| `apps/ocr-service/app/services/thai_normalizer.py` | Thai text normalization rules |
| `apps/ocr-service/app/services/thai_spellchecker.py` | Thai spell/suggestion support |
| `apps/ocr-service/app/services/ai_corrector.py` | AI correction abstraction |
| `apps/ocr-service/app/services/gemini_vision_parser.py` | Optional Gemini OCR/parser |
| `apps/ocr-service/app/services/landingai_parser.py` | Optional LandingAI parser |
| `apps/ocr-service/app/services/html_renderer.py` | HTML render support |
| `apps/ocr-service/app/services/rag_exporter.py` | RAG export helpers |
| `apps/ocr-service/tests/` | Python unit/integration tests |

### PDF Service

| Path | Purpose |
| --- | --- |
| `apps/pdf-service/index.js` | Express/Puppeteer PDF rendering service |
| `apps/pdf-service/Dockerfile` | PDF service image |
| `apps/pdf-service/package.json` | PDF service dependencies |

### Legacy/Experimental Vue App

| Path | Purpose |
| --- | --- |
| `apps/web-vue/` | Older standalone Vue app kept for reference; current app is `apps/app-laravel/resources/js` |

## Data Flow

```text
Browser
  -> Laravel API
  -> Redis queue
  -> OCR service
  -> shared poc_storage volume
  -> ReviewStore / MongoDB metadata
  -> review UI
  -> export JSON / PDF / Word
  -> Elasticsearch law index
  -> public search and law detail pages
```

## Search Notes

- Search endpoint: `POST /api/laws/search`
- Suggest endpoint: `POST /api/laws/suggest`
- Facets endpoint: `GET /api/laws/facets`
- Elasticsearch index mapping: `LawIndexDefinition.php`
- Indexing entrypoint: `LawIndexer.php`
- Search has file-based fallback so public search can still return metadata when Elasticsearch is unavailable or missing a document.
- Suggest has fuzzy fallback for Thai typo cases such as `บุคคลภายนอห` -> `บุคคลภายนอก`.

## Common Maintenance Commands

```bash
# Rebuild all containers
docker compose up -d --build

# Rebuild dev stack
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build

# Enter Laravel container
docker compose exec laravel-app bash

# Clear Laravel caches
docker compose exec -T laravel-app php artisan optimize:clear

# Run all Laravel tests
docker compose exec -T laravel-app php artisan test

# Run OCR service tests
docker compose exec -T ocr-service pytest

# Reindex laws, if command is available in the current branch
docker compose exec -T laravel-app php artisan laws:reindex
```

## Troubleshooting

### Vite container says node_modules is missing

Run:

```bash
cd apps/app-laravel
npm install
```

Then restart dev compose:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

### Laravel returns 500 after env changes

Run:

```bash
docker compose exec -T laravel-app php artisan optimize:clear
docker compose restart laravel-app queue-worker
```

### Elasticsearch is unhealthy

Check memory and logs:

```bash
docker compose logs elasticsearch
curl http://localhost:9200/_cluster/health
```

The compose file sets `ES_JAVA_OPTS=-Xms512m -Xmx512m`. Increase it if the host has enough RAM and search load is high.

### Mongo exits with code 132

Use the older image in `.env`:

```dotenv
MONGO_IMAGE=mongo:4.4
```

Then rebuild:

```bash
docker compose up -d --build
```

### Build warnings about TH Sarabun fonts

The frontend may print warnings that `/fonts/THSarabun*.ttf` are resolved at runtime. This is currently non-fatal; the build can still succeed.

