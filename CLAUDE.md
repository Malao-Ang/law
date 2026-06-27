# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

POC for extracting Thai legal/government documents (DOCX, text PDF, scanned PDF) into structured, reviewable JSON ready for RAG. The pipeline is **Docling + EasyOCR + PyThaiNLP** (local) or **LandingAI Vision Agent** (cloud), with a Laravel API orchestrating async jobs and a Vue 3 / TipTap frontend for human review. No persistent database — state lives in `storage/app/poc` (Laravel) and `/data/poc` (Python), shared via a Docker volume (`poc_storage`).

For the deeper rationale (4-layer text model, block design, AI correction policy, Thai normalization rules), see `README.md`. For the extraction architecture (docling-parse for text + Docling TableFormer for tables + BBox merge for layout), see `docling-arch.md`.

## Starting for development

**Prerequisites:** Docker, Docker Compose, Node 20+ on the host.

```bash
# 1. Copy and fill in secrets (APP_KEY is required; LANDINGAI_API_KEY for cloud OCR)
cp .env.example .env
# Generate APP_KEY:  docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"

# 2. Install frontend deps on the HOST (the Vite container mounts this dir)
cd apps/app-laravel && npm install && cd ../..

# 3. Start everything
docker-compose up -d
```

After first start the Python service is **not immediately ready** — EasyOCR downloads ~2 GB of model weights in a background thread. Poll `GET http://localhost:8010/health` until `ocr_ready: true`.

Hot-reload works out of the box:
- **PHP** — `apps/app-laravel/` is bind-mounted; changes are picked up immediately.
- **Python** — edit `apps/ocr-service/app/**`; the container runs uvicorn with `--reload`.
- **Vue/TS** — Vite HMR via port 5173; the Laravel app proxies asset requests.

## Deploying

The repo ships a minimal Nginx reverse proxy config (`infra/nginx/default.conf`) that terminates HTTP and proxies to `laravel-app:8000`. For a real deployment:

1. Build images with BuildKit for layer caching:
   ```bash
   export DOCKER_BUILDKIT=1 COMPOSE_DOCKER_CLI_BUILD=1
   docker-compose build --parallel          # or ./scripts/fast-rebuild.sh all
   ```
2. Set `APP_ENV=production`, `APP_DEBUG=false`, generate a fresh `APP_KEY`.
3. Run `npm run build` in `apps/app-laravel/` on the host, then mount the resulting `public/build/` into the laravel container (or bake it into the image).
4. Put Nginx (or a load balancer) in front of `laravel-app:8000`; the `infra/nginx/` config is the starting point.
5. The `poc_storage` Docker volume must be shared between `laravel-app`, `queue-worker`, and `ocr-service` — do not separate these onto different hosts without a shared NFS/S3 backend.

## Services and ports

| Service | Container | Port | Notes |
|---|---|---|---|
| `laravel-app` | `thai-ocr-laravel-app` | 8000 | `php artisan serve`; runs `composer install` on boot |
| `laravel-vite` | `thai-ocr-laravel-vite` | 5173 | Exits if `node_modules/.bin/vite` is missing — run `npm install` on the host first |
| `queue-worker` | `thai-ocr-queue-worker` | — | `php artisan queue:listen --tries=1 --timeout=1800` |
| `ocr-service` | `thai-ocr-service` | 8010 | FastAPI + Docling + EasyOCR + LandingAI; runs as root to handle volume perms |
| `redis` | `thai-ocr-redis` | 6379 | Laravel queue backend |

The shared `poc_storage` volume mounts as `/var/www/html/storage/app/poc` (Laravel) and `/data/poc` (Python). Laravel stores relative paths; `DocumentPipelineClient::toSharedPath()` rewrites them to absolute Python paths. Never construct these paths inline.

## Common commands

### Build & restart
```bash
docker-compose up -d
./scripts/fast-rebuild.sh ocr|laravel|vite   # rebuild one service
./scripts/fast-rebuild.sh all
```

### Python OCR service (`apps/ocr-service`)
```bash
docker compose exec ocr-service pytest                                                      # full suite
docker compose exec ocr-service pytest tests/test_thai_normalizer.py -k name               # single test
docker compose exec ocr-service pytest tests/test_landingai_parser.py                      # LandingAI adapter tests
docker compose exec ocr-service pytest tests/test_semantic_indent_resolver.py              # Thai legal indent tests
docker compose exec ocr-service python scripts/regenerate-goldens.py                       # regenerate all golden fixtures
docker compose exec ocr-service python scripts/regenerate-goldens.py --fixture prakat_1.pdf.golden.json
```

Root-level `test_*.py` files (`test_indent_fix.py`, `test_numbering.py`, `test_spacing.py`, `test_preview.py`) are ad-hoc scripts run against local sample files — not part of the pytest suite.

### Laravel (`apps/app-laravel`)
```bash
docker compose exec laravel-app php artisan test
docker compose exec laravel-app php artisan test --filter=ReviewLayoutPatchTest
docker compose exec laravel-app vendor/bin/pint            # PHP formatter
docker compose exec laravel-app php artisan queue:work     # one-shot worker (dev)
```

### Frontend (Vite inside `apps/app-laravel`)
```bash
# On the HOST:
npm install          # required before docker-compose up
npm run typecheck    # tsc --noEmit
npm run build        # production bundle
```

## Architecture

### Request flow
1. **Upload** — `POST /api/documents` (with `scan_extraction_mode`: `auto` / `local` / `landingai`) → `UploadController` stores file under `storage/app/poc/uploads/{document_id}/`, writes initial status via `ReviewStore`, dispatches `ExtractDocumentJob` on Redis queue.
2. **Job** — `ExtractDocumentJob` (in `queue-worker`) calls `DocumentPipelineClient::extract()` → `POST http://ocr-service:8010/pipeline/extract`. Python returns 202 immediately and processes in `BackgroundTasks`.
3. **Extraction** (`apps/ocr-service/app/api/routes.py::_run_extraction`):
   - `detect_file_type()` classifies as `docx` / `pdf_text` / `pdf_scan` / `mixed`.
   - `DoclingService` handles DOCX and PDF-text; `OcrPipeline` (EasyOCR) or `LandingAIParser` handles scans based on `scan_extraction_mode`.
   - `LandingAIParser` quality gate: if `mean_confidence < 0.78` or `uncertain_ratio > 0.50`, auto mode falls back to EasyOCR.
   - `LayoutInferrer` clusters x-positions doc-wide to assign `indent_level`. `SemanticIndentResolver` applies Thai legal rules (มาตรา anchors top-level, ข้อ/วรรค promote levels, continuation text inherits). `NumberingTokenizer` annotates `list_marker` (มาตรา / ข้อ / วรรค / (ก) / ๑.๑).
   - `build_document_output()` runs Thai normalization + spellcheck (if `needs_review`) + mock AI correction, writes `{document_id}.review.json` to `intermediate/`.
4. **Callback** — Python `POST`s result to `INTERNAL_CALLBACK_URL` (`PipelineCallbackController`), which updates status in `ReviewStore`. LandingAI metadata (`job_id`, `duration_ms`, `credit_usage`, `failed_pages`) is stored in the document status.
5. **Review** — Vue calls `GET /api/documents/{id}/review`, user edits per-block with TipTap inline editor. `PATCH /blocks/{blockId}` saves text (including `reviewed_html`); `PATCH /blocks/{blockId}/layout` saves indent/alignment/tab layout; `POST /blocks/{blockId}/reprocess` re-runs AI; `POST /pages/{pageNo}/reprocess` re-extracts a single page via LandingAI.
6. **Export** — `POST /api/documents/{id}/export` (`ExportController` → `ExportService`) emits RAG-ready JSON.

### Python service layout (`apps/ocr-service/app/`)
- `api/routes.py` — `/health`, `/pipeline/extract` (async 202), `/pipeline/reprocess-page` (async 202), `/pipeline/reprocess-block` (sync).
- `core/config.py` — `Settings` (pydantic-settings). Tunable knobs: `THAI_REVIEW_THRESHOLD`, `bbox_overlap_threshold`, `indent_cluster_step`, `tab_gap_threshold`, `pdf_header_top_fraction`, `pdf_header_min_font_pt`, `ocr_gpu_concurrency`, `landingai_api_key`, `landingai_base_url`, `landingai_parse_model`, `landingai_timeout_seconds`.
- `services/docling_service.py` — DOCX/PDF-text extraction. `docling_parse_extractor.py` gets bbox-accurate text; `docx_parser.py` handles `.docx` XML directly.
- `services/ocr_pipeline.py` — EasyOCR wrapper (lazy-loaded; model warmed in background thread on startup).
- `services/landingai_parser.py` — LandingAI ADE Parse adapter. Converts Markdown → blocks. In `auto` mode tries LandingAI first and falls back to EasyOCR on quality gate failure. Tracks `job_id`, `duration_ms`, `credit_usage`, `failed_pages`.
- `services/block_builder.py` — assembles the 4-layer block and applies `needs_review` / spellcheck flags.
- `services/layout_inferrer.py` — doc-wide x-position clustering → `indent_level` per block.
- `services/semantic_indent_resolver.py` — post-geometry pass applying Thai legal indent rules: มาตรา anchors level 0, ข้อ promotes to level 1, วรรค and continuation text inherit parent indent, divider lines map to โดย pattern.
- `services/numbering_tokenizer.py` — annotates `list_marker.{type, level, text}` for Thai legal numbering patterns.
- `services/thai_normalizer.py` — vowel reordering, tone-mark fixes, `ํา` → `ำ`, whitespace cleanup (preserves `\t` and `\n`).
- `services/thai_spellchecker.py` — lazy singleton `ThaiSpellChecker` wrapping `NorvigSpellChecker` + `resources/legal_terms.txt`. Only runs on blocks with `needs_review=True`.
- `services/ai_corrector.py` — `MockAICorrector`; real provider plugs in here.
- `services/table_extractor.py` — Docling TableFormer (`do_cell_matching=True`); falls back to PyMuPDF fitz.
- `services/table_text_merger.py` — assigns BBox word cells into TableFormer cells by centroid/overlap.
- `services/image_extractor.py`, `services/html_renderer.py` — image cropping and HTML rendering.

### Laravel layout (`apps/app-laravel/app/`)
- `Http/Controllers/Api/` — `UploadController`, `ReviewController` (show / preview / update / updateLayout / reprocess / reprocessPage / updateDocumentReview), `ExportController`, `PipelineCallbackController`, `HealthController`, `ImageController` (serves images from `poc/images/{documentId}/` and page thumbnails from `poc/pages/{documentId}/`).
- `Http/Requests/` — `StoreDocumentRequest` (file + `scan_extraction_mode`), `UpdateBlockRequest`, `UpdateBlockLayoutRequest` (validates `indent_level`, `indent_left`, `indent_first_line`, `indent_hanging`, `alignment`, `tabs[]`).
- `Jobs/` — `ExtractDocumentJob`, `ReprocessBlockJob`, `IngestRagJob`.
- `Services/DocumentPipelineClient.php` — typed HTTP client for the Python service; path translation via `toSharedPath()`. Methods: `extract()`, `reprocessPage()`, `reprocessBlock()`.
- `Services/ReviewStore.php` — file-backed status + review state (JSON files under `storage/app/poc/`).
- `Services/DocumentHtmlService.php` — always recomputes HTML from structured block fields (never prefers a stale `reviewed_html`).

### Vue frontend (`apps/app-laravel/resources/js/`)
- `pages/UploadPage.vue` — file upload with scan mode selector (`auto` / `local` / `landingai`).
- `pages/ReviewPage.vue` — main review interface: `MatraToc` sidebar + `DocumentBlockEditor` center + `BlockReviewPanel` right panel. Displays LandingAI extraction metadata (job_id, duration, credits, failed pages) when present.
- `components/DocumentBlockEditor.vue` — block list with TipTap inline editor (double-click to edit). Formatting toolbar: Bold/Italic/Underline, H1/H2. Spell suggestions as amber chips. Layout controls (indent +/−) when not editing. Page thumbnails shown above each page boundary with a toggle button.
- `components/BlockRulerEditor.vue` — visual ruler for indent, tab stops (left/center/right/decimal), and paragraph alignment. Mirrors Word-style paragraph formatting controls.
- `components/MatraToc.vue` — collapsible sidebar Table of Contents listing มาตรา sections as sticky pills for quick navigation.
- `components/BlockReviewPanel.vue` — right panel: block metadata, image/table span controls, per-page LandingAI reprocess button, block reprocess button.
- `components/DocumentHtmlEditor.vue` — document-level HTML draft editor.
- `api/client.ts` — typed wrappers for all API endpoints: `patchBlockLayout()`, `reprocessPageWithLandingAI()`, etc.
- `types/document.ts` — canonical TS types: `DocumentBlock`, `BlockMeta` (with `layout`, `list_marker`, `image`, `spell_suggestions`, `formatting`), `BlockLayout` (with `indent_left`, `indent_first_line`, `indent_hanging`, `tabs: TabStop[]`), `TabStop` (position in twips + type), `DocumentStatus` (with `scan_extraction_mode_requested`, `scan_extraction_mode_effective`, `extraction_path[]`, `extraction.landingai`).

### Canonical contracts
- `schemas/document-output.schema.json` — Python → Laravel intermediate format (per-block 4-layer text + bbox + flags + `meta.layout` / `meta.list_marker` / `meta.image` / `meta.spell_suggestions`).
- `schemas/review-patch.schema.json` — UI → Laravel block updates.
- `schemas/export-rag.schema.json` — final RAG export.

## Conventions and gotchas

- **Every block carries 4 text layers** (`raw_text`, `normalized_text`, `ai_suggested_text`, `approved_text`). Preserve this for all new block types.
- **AI correction is opt-in per block**, gated by `THAI_REVIEW_THRESHOLD` (default 0.90). Only flag low-confidence or reviewer-requested blocks.
- **Path translation is one-way**: Laravel relative paths → Python absolute paths via `DocumentPipelineClient::toSharedPath()`. Never construct these inline.
- **Docling OCR is off** — we use docling-parse for text, EasyOCR/LandingAI for scans, and Docling TableFormer only for table structure.
- **LandingAI scan mode**: `auto` tries LandingAI then falls back to EasyOCR if quality gate fails (`mean_confidence < 0.78` or `uncertain_ratio > 0.50`). `local` forces EasyOCR. `landingai` forces LandingAI with no fallback. The effective mode used is stored in `DocumentStatus.scan_extraction_mode_effective`.
- **`SemanticIndentResolver` runs after `LayoutInferrer`** — never skip this pass on legal PDFs; it corrects geometry-based indent assignments using มาตรา/ข้อ/วรรค section rules.
- **Rich HTML persistence**: blocks store both `approved_text` (plain) and `reviewed_html` (HTML with bold/italic/underline). The editor sends both on save. `reviewed_html` is sanitized server-side with `strip_tags` allowlist; client-side with DOMPurify before display.
- **DOCX numbered items use a single space after the marker** in the active `DoclingService` path; do not reintroduce a structural `\t` between marker and body text.
- **First Python service start is slow** — EasyOCR downloads ~2 GB of weights. `/health` returns `ocr_ready: false` until complete.
- **Failure handling in `_run_extraction`**: errors are written to intermediate JSON *and* posted to the callback (dual-write). Keep both paths — the file is the local inspection surface.
- **Image serving**: images at `/data/poc/images/{documentId}/{filename}`, served at `GET /api/documents/{documentId}/images/{filename}`. Page thumbnails at `/data/poc/pages/{documentId}/{pageNo}.jpg`, served at `GET /api/documents/{documentId}/pages/{pageNo}/image`. The `_build_image_meta()` helper sets `src_url` and optionally embeds `data_uri` for images ≤ 200 KB.
- **`DocumentHtmlService` always recomputes HTML from blocks** — it never falls back to a stale `reviewed_html` field. Calling `markOutOfSync()` on `ReviewStore` signals the UI to prompt a regenerate.
- **PDF review pages use a sheet-of-paper layout** in the Laravel CSS; preserve visible per-page boundaries and non-sticky page headers.
- **OCR table detection requires both horizontal and vertical structure** and can be disabled with `enable_ocr_table_detection`; do not relax it without fixture validation.
- **Ghost-tone recovery exists in `docling_service.py`** and is tuned by `GHOST_TONE_MAX_WIDTH_PT`; prefer threshold tuning and targeted dictionary fixes over broad spell-repair heuristics.
- **Golden fixture tests** (`tests/test_golden_fixtures.py::TestGoldenAccuracy`) skip automatically when the golden file or source document is absent. Regenerate with `scripts/regenerate-goldens.py`; hand-edit the `.golden.json` files before committing. Synthetic fixtures (`multi_level_list.docx`, `table_with_merges.docx`) must be created manually.
- Sample documents (`ประกาศ (1).pdf`, `ประกาศ (3).docx`) live in the **repo root**, not in `samples/`.
