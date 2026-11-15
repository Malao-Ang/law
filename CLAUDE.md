# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

POC for extracting Thai legal/government documents (DOCX, .doc, text PDF, scanned PDF) into structured, reviewable JSON ready for RAG. Two extraction engines:
- **Fast (default)** — PHP-only `FastExtractionPipeline` handles DOCX/.doc/PDF-text without Python, then dispatches `NormalizeDocumentJob` (CPU-only Thai normalize + spellcheck via Python).
- **Standard** — full Python pipeline: Docling + EasyOCR + PyThaiNLP (local) or LandingAI Vision Agent (cloud).

State lives in MongoDB (`MongoBlobStore`: documents, users, permissions, audit logs, master data incl. units) plus files in `storage/app/poc` (Laravel) and `/data/poc` (Python), shared via Docker volume `poc_storage`. Search runs on Elasticsearch (`ELASTIC_INDEX`, default `laws`); queue on Redis.

For the block design rationale and Thai normalization rules see `README.md`. For the docling-parse + TableFormer extraction architecture see `docling-arch.md`.

## Starting for development

**Prerequisites:** Docker, Docker Compose, Node 20+ on the host.

```bash
# 1. Copy and fill in secrets (APP_KEY is required; LANDINGAI_API_KEY for cloud OCR)
cp .env.example .env
# Generate APP_KEY: docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"

# 2. Install frontend deps on the HOST (the Vite container mounts this dir)
cd apps/app-laravel && npm install && cd ../..

# 3. Start everything
docker-compose up -d
```

After first start the Python service is **not immediately ready** — EasyOCR downloads ~2 GB of model weights in a background thread. Poll `GET http://localhost:8010/health` until `ocr_ready: true`. Fast-path uploads work immediately without waiting.

Hot-reload works out of the box:
- **PHP** — in the dev compose override `apps/app-laravel/` is bind-mounted; changes are picked up immediately. Production does **not** do this (see Deploying).
- **Python** — edit `apps/ocr-service/app/**`; the container runs uvicorn with `--reload`.
- **Vue/TS** — `laravel-vite` runs Vite HMR on port 5173; install host deps with `cd apps/app-laravel && npm install` before `docker-compose up`.

## Deploying

The repo ships a minimal Nginx reverse proxy config (`infra/nginx/default.conf`) that terminates HTTP and proxies to `laravel-app:8000`. For a real deployment:

1. Build images with BuildKit for layer caching:
   ```bash
   export DOCKER_BUILDKIT=1 COMPOSE_DOCKER_CLI_BUILD=1
   docker-compose build --parallel          # or ./scripts/fast-rebuild.sh all
   ```
2. Set `APP_ENV=production`, `APP_DEBUG=false`, generate a fresh `APP_KEY`.
3. The frontend is built inside the image (`npm run build` in the Dockerfile `frontend` stage); `public/build/` is git-ignored.
4. Put Nginx (or a load balancer) in front of `laravel-app:8000`; the `infra/nginx/` config is the starting point.
5. The `poc_storage` Docker volume must be shared between `laravel-app`, `queue-worker`, and `ocr-service` — do not separate these onto different hosts without a shared NFS/S3 backend.

## Deploying to production (read before pulling)

Production differs from dev in ways that repeatedly cause "I pulled but nothing changed":

- **Code is baked into the image.** `apps/app-laravel/Dockerfile` does `COPY . .` and runs `npm run build` inside the image; `docker-compose.yml` does not bind-mount the app code. `git pull` alone changes nothing. Update with:
  ```bash
  git fetch origin && git reset --hard origin/main   # no merge, no conflicts
  docker compose build laravel-app
  docker compose up -d laravel-app queue-worker scheduler   # all three share image thai-ocr-laravel-app:local
  docker image prune -f                                      # only after the new stack is verified
  ```
  Then hard-refresh the browser (`Ctrl+Shift+R`). Never use `docker system prune -a` or `--volumes` (deletes data volumes).
- **`.env` lives at the repo root** (next to `docker-compose.yml`, ignored by git, read by every service via `env_file`). `apps/app-laravel/.env` is only for running Laravel outside Docker. Create it from `.env.example` once; `git reset --hard` does not touch it.
- **Production `.env` must have:** `APP_ENV=production`, `APP_DEBUG=false`, real `APP_KEY`, `AUTH_ENFORCED=true` (it is off on dev), `SUPER_ADMIN_USERNAMES`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS`, the `BUU_*` and `BUU_UMS_FACULTY_*` credentials, and **no** `AUTH_DEV_LOGIN`. Credentials stay in `.env` only — never commit, never paste into chats/screenshots.
- **Session continuity:** Compose forces `SESSION_DRIVER=redis` for the web app and persists Redis in `redis_data` (AOF), regardless of an older root `.env` value. Set `SESSION_LIFETIME=120` in production `.env` for a 2-hour idle timeout; active clients refresh before idle expiry. A separate server-side 12-hour absolute cap requires login again even with ongoing activity. First migration from file sessions requires login once; **recreating the previously volume-less Redis container can also discard queued jobs/cache**: inspect and drain/export its data before deploying. Never flush Redis or use `docker compose down -v`. Confirm with `docker compose exec -T laravel-app php artisan tinker --execute="echo config('session.driver'), ' ', config('session.lifetime'), PHP_EOL;"` after recreate.
- **Pasting commands/values through chat tools can inject `@url:` and backticks** (seen in `.env` `ELASTIC_HOST` and in `curl` URLs). Check with `grep -c '@url' .env; grep -c '`' .env` (must be 0) and type URLs plain.
- **Mongo image is `mongo:4.4`: use `mongo`, not `mongosh`**, and `var`/`function` instead of `const`/arrow functions in `--eval` scripts.
- After editing `.env`, recreate containers (`docker compose up -d`) and run `php artisan optimize:clear`.

### Reset to first-use state (only data types + units remain)

Deletes documents, permissions, audit logs and users; keeps `master_data` (document types, units). Irreversible unless backed up (`mongodump`, `tar` of `storage/app`):
```bash
docker compose exec laravel-app php artisan down
docker compose exec -T mongo mongo --quiet poc --eval '
var cols = ["documents","permissions","audit_logs","membership_audit_logs","users"];
cols.forEach(function(c){ db.getCollection(c).deleteMany({}); });'
docker compose exec -T elasticsearch curl -s -X POST "http://localhost:9200/laws/_delete_by_query?conflicts=proceed&refresh=true" -H 'Content-Type: application/json' -d '{"query":{"match_all":{}}}'
docker compose exec redis redis-cli FLUSHDB
docker compose exec laravel-app php artisan optimize:clear
docker compose exec laravel-app php artisan up
```
Mongo alone is not enough — Elasticsearch keeps searchable copies and `storage/app/poc` keeps the files. External MinIO objects are never removed by this. The first Super Admin comes from `SUPER_ADMIN_USERNAMES`; log in once to verify.

## Services and ports

| Service | Container | Port | Notes |
|---|---|---|---|
| `laravel-app` | `thai-ocr-laravel-app` | 8000 | `php artisan serve`; runs `composer install` on boot |
| `laravel-vite` | `thai-ocr-laravel-vite` | 5173 | Exits if `node_modules/.bin/vite` is missing — run `npm install` on the host first |
| `queue-worker` | `thai-ocr-queue-worker` | — | `php artisan queue:listen --tries=1 --timeout=1800` |
| `ocr-service` | `thai-ocr-service` | 8010 | FastAPI + Docling + EasyOCR + LandingAI; runs as root to handle volume perms |
| `redis` | `thai-ocr-redis` | 6379 | Laravel queue backend |
| `mongo` | `thai-ocr-mongo` | 27017 | Document blob store (`MongoBlobStore`); data in volume `poc_mongo` |
| `elasticsearch` | `thai-ocr-elasticsearch` | 9200 | Thai keyword search index (local dev, security off) |

The shared `poc_storage` volume mounts as `/var/www/html/storage/app/poc` (Laravel) and `/data/poc` (Python). Laravel stores relative paths; `DocumentPipelineClient::toSharedPath()` rewrites them to absolute Python paths. Never construct these paths inline.

## Common commands

### Build & restart
```bash
docker-compose up -d
./scripts/compose-dev.sh               # start stack with Vite HMR
./scripts/fast-rebuild.sh ocr|laravel|vite   # rebuild one service
./scripts/fast-rebuild.sh all
```

### Python OCR service (`apps/ocr-service`)
```bash
docker compose exec ocr-service pytest                                                       # full suite
docker compose exec ocr-service pytest tests/test_thai_normalizer.py -k name                # single test
docker compose exec ocr-service pytest tests/test_landingai_parser.py                       # LandingAI adapter tests
docker compose exec ocr-service pytest tests/test_gemini_vision_parser.py                   # Gemini vision OCR tests
docker compose exec ocr-service pytest tests/test_semantic_indent_resolver.py               # Thai legal indent tests
docker compose exec ocr-service python scripts/regenerate-goldens.py                        # regenerate all golden fixtures
docker compose exec ocr-service python scripts/regenerate-goldens.py --fixture prakat_1.pdf.golden.json
```

Root-level `test_*.py` files (`test_indent_fix.py`, `test_numbering.py`, `test_spacing.py`, `test_preview.py`) are ad-hoc scripts run against local sample files — not part of the pytest suite.

### Laravel (`apps/app-laravel`)
```bash
docker compose exec laravel-app php artisan test
docker compose exec laravel-app php artisan test --filter=FastExtractionTest
docker compose exec laravel-app php artisan test --filter=NormalizeDocumentJobTest
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

### End-to-end flow

**Admin flow (primary):**
1. `/admin` → `/admin/upload` — drag-and-drop upload, polls status, auto-redirects when done.
2. `/documents/:id/review` — `DocumentEditorShell`: whole-document TipTap editor with 3-row toolbar (headings, font size/format, paragraph alignment/indent). Save → `/documents/:id/rag`.
3. `/documents/:id/rag` — `RagManageWorkspace`: read-only block list, select/merge/delete blocks, "บันทึกและเผยแพร่" → export.
4. `/law/:id` — `LawDocumentView`: public read-only view with section TOC sidebar.

**Upload → extraction branching (`ExtractDocumentJob`):**
- `extraction_engine = 'fast'` (default): `FastExtractionPipeline::run()` → writes review JSON directly → marks status `done` → dispatches `NormalizeDocumentJob`. Falls back to standard pipeline via `FastPathUnsupportedException` for unsupported types.
- `extraction_engine = 'standard'`: calls `DocumentPipelineClient::extract()` → Python `/pipeline/extract` (202 async) → callback → status `done`.

**`NormalizeDocumentJob`** (fast path only, non-fatal if Python is down): reads review JSON, sends all non-table/image blocks to `POST /pipeline/normalize`, writes normalized text back via `ReviewStore::applyNormalizationResults()`. No AI, CPU-only.

### Request flow (standard engine)
1. **Upload** — `POST /api/documents` → `UploadController` stores file, dispatches `ExtractDocumentJob`.
2. **Job** — `ExtractDocumentJob` calls `DocumentPipelineClient::extract()` → `POST :8010/pipeline/extract`. Python returns 202.
3. **Extraction** (`apps/ocr-service/app/api/routes.py::_run_extraction`): `detect_file_type()` → `DoclingService` (DOCX/PDF-text) or `OcrPipeline`/`LandingAiAdeParser` (scans) → `LayoutInferrer` + `SemanticIndentResolver` + `NumberingTokenizer` → `build_document_output()` writes `{id}.review.json` to `intermediate/`.
4. **Callback** — Python `POST`s to `INTERNAL_CALLBACK_URL` (`PipelineCallbackController`), updates `ReviewStore`.
5. **Review/RAG/Export** — see Admin flow above.

### PHP Fast path (`apps/app-laravel/app/Services/Fast/`)
- `FastExtractionPipeline.php` — entry point; dispatches to DOCX/PDF extractor by file extension; handles `.doc` via `LibreOfficeConverter`.
- `FastDocxExtractor.php` — unzips DOCX via `DocxArchive`, runs `ParagraphParser` + `NumberingResolver` + `TableParser` → block list.
- `FastPdfTextExtractor.php` — PDF text layer → blocks (no OCR).
- `LibreOfficeConverter.php` — converts `.doc` → `.docx` via soffice binary.
- `FastPathUnsupportedException.php` — thrown for unsupported types; caught by `ExtractDocumentJob` to trigger standard fallback.
- `Docx/` — `DocxArchive` (unzip), `ParagraphParser` (paragraph XML → block), `NumberingResolver` (list markers from `numbering.xml`), `TableParser` (table XML → block), `TableHtmlRenderer`, `WordXml` (XML helpers).

### Python service layout (`apps/ocr-service/app/`)
- `api/routes.py` — `/health`, `/pipeline/extract` (async 202), `/pipeline/normalize` (sync, batch), `/pipeline/correct` (async 202), `/pipeline/reprocess-page` (async 202), `/pipeline/reprocess-block` (sync).
- `core/config.py` — `Settings` (pydantic-settings). Tunable knobs: `THAI_REVIEW_THRESHOLD`, `bbox_overlap_threshold`, `indent_cluster_step`, `tab_gap_threshold`, `pdf_header_top_fraction`, `pdf_header_min_font_pt`, `ocr_gpu_concurrency`, `landingai_api_key`, `landingai_base_url`, `landingai_parse_model`, `landingai_timeout_seconds`, `gemini_api_key`, `gemini_model`, `gemini_timeout_seconds`, `normalize_autocorrect_min_confidence`.
- `services/docling_service.py` — DOCX/PDF-text extraction via docling-parse (bbox-accurate).
- `services/ocr_pipeline.py` — EasyOCR wrapper (lazy-loaded; model warmed on startup).
- `services/landingai_parser.py` — LandingAI ADE Parse adapter for scanned PDFs.
- `services/gemini_vision_parser.py` — Google Gemini vision OCR adapter for scanned PDFs.
- `services/block_builder.py` — assembles 4-layer block, runs Thai normalization + spellcheck.
- `services/layout_inferrer.py` — doc-wide x-position clustering → `indent_level` per block.
- `services/semantic_indent_resolver.py` — post-geometry pass: มาตรา anchors level 0, ข้อ → level 1, วรรค/continuation text inherits parent.
- `services/numbering_tokenizer.py` — annotates `list_marker.{type, level, text}` (มาตรา/ข้อ/วรรค/(ก)/๑.๑).
- `services/thai_normalizer.py` — vowel reordering, tone-mark fixes, `ํา` → `ำ`, whitespace cleanup.
- `services/thai_spellchecker.py` — lazy singleton `ThaiSpellChecker` (Norvig + `resources/legal_terms.txt`); only runs on `needs_review=True` blocks.
- `services/correction_service.py` — runs spellcheck + mock AI correction on already-extracted review JSON.
- `services/ai_corrector.py` — `MockAICorrector`; real provider plugs in here.
- `services/table_extractor.py` — Docling TableFormer (`do_cell_matching=True`); falls back to PyMuPDF fitz.

### Laravel layout (`apps/app-laravel/app/`)
- `Http/Controllers/Api/` — `UploadController` (store/show + `extraction_engine` field), `ReviewController` (show / preview / update / updateLayout / reprocess / reprocessPage / updateDocumentReview / mergeBlocks / deleteBlock / splitBlock / createBlock / reorderBlocks), `ExportController` (store + retryCorrection), `PipelineCallbackController`, `HealthController`, `ImageController`.
- `Http/Requests/` — `StoreDocumentRequest` (file + `scan_extraction_mode` + `extraction_engine`), `UpdateBlockRequest`, `UpdateBlockLayoutRequest`.
- `Jobs/` — `ExtractDocumentJob` (fast/standard branching), `NormalizeDocumentJob` (fast path post-extract), `CorrectDocumentJob` (standard path legacy), `ReprocessBlockJob`, `IngestRagJob`.
- `Services/Fast/` — see PHP Fast path section above.
- `Services/DocumentPipelineClient.php` — typed HTTP client; methods: `extract()`, `reprocessPage()`, `reprocessBlock()`, `normalize()`, `correct()`. Path translation: `toSharedPath()`.
- `Services/ReviewStore.php` — file-backed state; key write methods: `writeReviewDocument`, `patchApprovedBlock`, `patchBlockLayout`, `applyNormalizationResults`, `reorderBlocks`, `deleteBlock`, `mergeBlocks`.
- `Services/DocumentHtmlService.php` — always recomputes HTML from blocks (never uses stale `reviewed_html`).

### Vue frontend (`apps/app-laravel/resources/js/`)

**Pages** (all under `pages/`, organized by feature subdirectory):
- `admin/AdminDashboardPage.vue` — dashboard with stats cards.
- `admin/AdminUploadPage.vue` — drag-and-drop upload with real-time status polling; auto-redirects to `/review` when done. Uses `AppShell`.
- `review/ReviewPage.vue` → `components/review/DocumentEditorShell.vue` — whole-document TipTap editor, 3-row toolbar (history/headings, font size/format, alignment/indent). Loaded by `useDocumentStore`.
- `rag/RagPage.vue` → `components/rag/RagManageWorkspace.vue` — read-only block list with selection; merge/delete; "บันทึกและเผยแพร่". Uses `useComposeStore`.
- `preview/PreviewPage.vue` — rendered preview. Uses `usePreviewStore`.
- `law/LawPage.vue` → `components/law/LawDocumentView.vue` — public read-only view with section TOC sidebar (anchored to block IDs). Uses `useDocumentStore`.
- `compose/ComposePage.vue` → `components/compose/DocumentComposeWorkspace.vue` — legacy per-block compose view (not part of primary flow).
- `UploadPage.vue` — legacy upload page (kept for backward compatibility).

**Component subdirectories:**
- `components/review/` — `DocumentEditorShell`, `DocumentBlockEditor`, `BlockReviewPanel`, `BlockRulerEditor` (Word-style ruler: indent + tab stops), `MatraToc`, `DocumentHtmlEditor`, `DocumentViewer`, `DiffViewer`.
- `components/compose/` — `DocumentComposeWorkspace`, `ComposeSectionEditor`, `ComposeToolbar`, `ComposeBlockSelectionBar`, `ComposeFooterBar`, `ComposeMetadataPanel`, `ComposeSectionNavigator`.
- `components/rag/` — `RagManageWorkspace`.
- `components/law/` — `LawDocumentView`.
- `components/admin/` — `AdminStatCard`.
- `components/shared/` — `AppShell` (admin/workflow shell: sidebar, breadcrumbs, theme defaults), `UploadForm`, `ELawNavbar`, `ELawHeroSearch`, `ELawLawCard`, `HeaderComponent`, `SectionActionBar`, `ResizableImage`.

**Stores** (`stores/`):
- `documentStore.ts` — `fetch(id)`, `saveReview()`, `reset()`. Used by ReviewPage and LawPage.
- `blockStore.ts` — stateless async ops: `patch`, `patchLayout`, `reprocess`, `merge`, `remove`, `split`, `create`, `reorderBlocks`, `reprocessPage`.
- `composeStore.ts` — RAG page: `fetch`, `triggerExport`, `pollStatus`.
- `reviewUiStore.ts` — UI state: `mode`, `isDirty`, `selectedBlockId`, `editing`.
- `uploadStore.ts` — `upload(file, scanMode, engine)`, `pollOnce`.
- `previewStore.ts` — `fetch(id)` → GET /preview HTML.

**Types** (`types/document.ts`): `DocumentBlock` (4 text layers + `meta.layout` / `meta.list_marker` / `meta.image` / `meta.spell_suggestions`), `BlockLayout` (indent in twips + `tabs: TabStop[]`), `DocumentStatus` (includes `extraction_engine`, `fast_fallback_reason`, `timings`).

### API routes

**Laravel API (`/api/...`):**
- `POST /documents` — upload (params: `file`, `scan_extraction_mode`, `extraction_engine`)
- `GET /documents/{id}` — poll status
- `GET /documents/{id}/review` — full review JSON
- `GET /documents/{id}/preview` — generated HTML
- `PUT /documents/{id}/document-review` — save whole-doc draft_html
- `PATCH /documents/{id}/blocks/{blockId}` — update block text
- `PATCH /documents/{id}/blocks/{blockId}/layout` — update indent/alignment/tabs
- `POST /documents/{id}/blocks` — create block
- `POST /documents/{id}/blocks/reorder` — reorder blocks (array of IDs)
- `POST /documents/{id}/blocks/merge` — merge selected block IDs → 1 block
- `DELETE /documents/{id}/blocks/{blockId}` — delete block
- `POST /documents/{id}/blocks/{blockId}/split` — split block at cursor
- `POST /documents/{id}/blocks/{blockId}/reprocess` — re-extract via Python
- `POST /documents/{id}/pages/{pageNo}/reprocess` — re-extract page via LandingAI
- `POST /documents/{id}/export` — export RAG JSON
- `POST /documents/{id}/retry-correction` — re-run spellcheck/correction
- `POST /internal/pipeline-callback` — Python → Laravel callback

**Python service (`/pipeline/...`):**
- `GET /health` — `{ocr_ready, device, ...}`
- `POST /pipeline/extract` — async 202
- `POST /pipeline/normalize` — sync; `{document_id, blocks[], autocorrect_min_confidence}` → `{results: [{block_id, normalized_text, changes}]}`
- `POST /pipeline/correct` — async 202 (legacy standard path)
- `POST /pipeline/reprocess-page` — async 202
- `POST /pipeline/reprocess-block` — sync

### Canonical contracts
- `schemas/document-output.schema.json` — Python → Laravel intermediate format.
- `schemas/review-patch.schema.json` — UI → Laravel block updates.
- `schemas/export-rag.schema.json` — final RAG export.

## Conventions and gotchas

- **LawMeta type/status fields**: overlapping `law_type`/`source`/`document_type`, singular↔array pairs (`agency`/`agencies` …), and the 4 status axes are documented in [`docs/law-metadata-reference.md`](docs/law-metadata-reference.md). Read it before adding logic that reads/writes any of them — `source` is derived from `law_type`, singular fields mirror `array[0]`, and `LawMeta.status` ≠ `DocumentStatus.status`.
- **Master data options**: kinds, code prefixes, read-only change-status rows, alias rules, and the migration/reindex runbook live in [`docs/law-metadata-reference.md#master-data`](docs/law-metadata-reference.md#master-data).
- **Two extraction engines**: `fast` (PHP-only, default) and `standard` (Python). `ExtractDocumentJob` branches on `$this->extractionEngine`. `FastPathUnsupportedException` triggers automatic fallback to standard.
- **Every block carries 4 text layers** (`raw_text`, `normalized_text`, `ai_suggested_text`, `approved_text`). Preserve this for all new block types including Fast path.
- **`NormalizeDocumentJob` is non-fatal** — if Python is down, it catches `ConnectionException` and silently skips. The document is already marked `done` before normalize runs.
- **`normalize_autocorrect_min_confidence`** defaults to `0.85` — spell suggestions with ≥85% confidence auto-apply during normalize. Raise to `1.0` via `.env` to effectively disable auto-correct. Tunable per-deploy without code changes.
- **Path translation is one-way**: Laravel relative paths → Python absolute paths via `DocumentPipelineClient::toSharedPath()`. Never construct these inline.
- **Docling OCR is off** — we use docling-parse for text, EasyOCR/LandingAI for scans, and Docling TableFormer only for table structure.
- **Scan extraction modes**: `local` forces EasyOCR. `gemini` forces Google Gemini vision OCR (requires `GEMINI_API_KEY`). `landingai` forces LandingAI ADE Parse (requires `VISION_AGENT_API_KEY`). `auto` tries EasyOCR first; if quality is poor, falls back to Gemini then LandingAI when keys are configured.
- **`SemanticIndentResolver` runs after `LayoutInferrer`** — never skip on legal PDFs.
- **Rich HTML persistence**: blocks store both `approved_text` (plain) and `reviewed_html` (bold/italic/underline). `reviewed_html` is sanitized server-side with `strip_tags` allowlist; client-side with DOMPurify before display.
- **`ReviewStore::reorderBlocks`** builds `$blockMap` via `foreach ($document['pages'] as &$page)` — NOT `foreach (($document['pages'] ?? []) as &$page)`. The `?? []` expression creates a temporary copy that breaks PHP reference propagation.
- **`DocumentHtmlService` always recomputes HTML from blocks** — never falls back to a stale `reviewed_html` field. `markOutOfSync()` is a transient trigger consumed by `getReviewDocument()`'s lazy-sync; after sync in 'generated' mode it is always `false`.
- **Failure handling in `_run_extraction`**: errors are written to intermediate JSON *and* posted to the callback (dual-write). Keep both paths.
- **Image serving**: images at `/data/poc/images/{documentId}/{filename}`, thumbnails at `/data/poc/pages/{documentId}/{pageNo}.jpg`. `_build_image_meta()` embeds `data_uri` for images ≤ 200 KB.
- **Golden fixture tests** (`tests/test_golden_fixtures.py`) skip when golden file or source doc is absent. Regenerate with `scripts/regenerate-goldens.py`. Sample documents (`ประกาศ (1).pdf`, `ประกาศ (3).docx`) live in the **repo root**.
- **Ghost-tone recovery** in `docling_service.py` tuned by `GHOST_TONE_MAX_WIDTH_PT`; prefer threshold tuning over broad spell-repair.
- **DOCX numbered items use a single space** after the marker in the DoclingService path; do not reintroduce a structural `\t`.
## Auth, roles, units and document visibility

Added after the original POC; the rules below are decided requirements — do not "simplify" them.

- **Login** is BUU LDAP via Kong (`app/Services/Buu/*`); users are stored in Mongo (`MongoUserStore`, collection `users`, data nested under `user`). `AuthController` handles login, `active-unit` and logout.
- **Roles:** Super Admin / Admin (`inner_admin`) / Outer Admin / Editor / User. A user has memberships per unit (`unit_code` + role + actions). After every login a persistent dialog (`RoleSelectDialog.vue`) lets the user pick a unit/role; **everyone who logs in can also pick plain `user`** (`acting_mode = 'user'`, no unit, no admin pages). Switching reloads the current page (`ActiveUnitMenu.vue`); if the new role lacks permission the router sends admin roles to `/admin`.
- **Active role + active unit only.** Admin lists, dashboard (`/api/reports/summary`), direct document open, search/suggest, relations, downloads, audit and notifications must filter by the active unit — never by all memberships. Unit A must not see unit B's drafts; the user switches back to see them.
- **Public site visibility:** guests see only published `Public` documents. A logged-in plain user also sees published `Private` documents that have **no** permission group/unit. Private documents with groups/explicit users are checked against the **active unit** or the listed person only. Active Super Admin sees everything.
- **Relations across units** are allowed only for published documents; otherwise show a restricted placeholder that can be linked but whose status cannot be edited.
- **Editor** manages own documents and creates master data, but cannot grant permissions to others. Co-editor was removed (API and components) — do not reintroduce it. **Outer Admin** may switch Public/Private and pick groups only for documents they are responsible for in the active unit.
- **Public/Private labels must come from `access_scope`** stored on the document, not inferred (an earlier bug showed Private documents as Public). Admin tables show `Public` / `Private` in English.
- **Units** (`UnitStore`, codes `DEPnnn` by type ranges: faculty 1–99, division 100–199, department 200–499, law_subunit 500–999). "Fetch faculties from UMS" now returns a **preview** (`POST /api/units/sync-faculties`) and the admin applies selected rows (`POST /api/units/sync-faculties/apply`). A renamed faculty (same `fac_id`, new name) creates a **new** unit and detaches `ums_fac_id` from the old one (`replaced_by`) so existing documents keep their old unit; UMS fallback data comes from `database/seeders/data/faculty.json`.
- **UMS person search** has a 10 s timeout and no automatic retry; on failure it returns HTTP 200 with `ums_unavailable: true` and the user presses search again. Do not "fix" it by disabling permission checks.
- **User management** lives at `/admin/users` (Super Admin; `UserController`, `UsersPage.vue`). `AUTH_ENFORCED` is off on dev for convenience and **must be on in production**.

## UI, theme and Thai text conventions

- Built on Vuetify; do not hand-roll components. Two themes switched in `main.ts` (`router.afterEach`): **`admin`** (navy, applies to `/admin/*` **and** `/documents/*`) and **`user`** (gold/cream from the Figma file). `AppShell.vue` uses the same path test for sidebar, breadcrumbs and admin defaults — keep both in sync. `/documents/:id/review` and `/compose` are full-screen editors without `AppShell`.
- Theme work changes colour/font/radius/shadow/spacing only — **do not move buttons, change flows or data** unless asked.
- All user-visible text and validation errors are **Thai**. A raw English key like `law_meta.permission_group_ids` reaching the screen is a bug (an old `min:1` on permission groups did this and wrongly forced groups for Public/Private).
- Role names shown as-is: Super Admin / Admin / Outer Admin / Editor / User. Printed documents use THSarabunNew. Unknown PDF values render as em dash `—` (UI `-`).
- Skeleton loaders are light grey `#e5e7eb`; scrollbars are thin and must not cover icons; sidebar rail keeps icons centred.
- Logout returns to `/` (full reload clears cached document/permission state); the login page's own logout button returns to `/login`.

## Frequently-made mistakes

1. Pulling on production and expecting new code — rebuild the image (see Deploying).
2. Resetting data in Mongo only — also clear Elasticsearch, Redis and `storage/app/poc`.
3. Using `mongosh`/`const` against `mongo:4.4`.
4. Filtering visibility by all memberships instead of the active unit; or forgetting a path (dashboard summary, direct open, relations suggest, downloads).
5. Showing the Public/Private label from a derived field instead of `access_scope`.
6. Adding `required`, `required_if`, `required_with`, `required_without` rules (project rule: forbidden), changing the `/api/lookups` response shape, or touching `applySameLevelReplacement` / `useShowRelations`.
7. Showing English validation/error text; or inconsistent placeholder + floating label overlap (admin theme hides placeholders until the field is active).
8. Hacking global input `autocomplete` from JS (`main.ts`) — it made pages unclickable and was reverted. The dark suggestion box on selects is the browser's autofill, not the app; turn it off in the browser.
9. Only updating `AppShell.vue` or only `main.ts` when changing which paths use the admin theme — change both.
10. Running `eslint --fix`, committing `.env`, Docker/compose diffs or credentials, pushing to `old-origin` (GitLab), or pushing without being asked.
11. Treating the 22 pre-existing failing Laravel tests and `vue-tsc` errors in `LawDatabasePage.vue` / `PublicShowRelationsPage.vue` as caused by your change — check `git stash` baseline first. When the user says not to write/run tests, do not.
12. Paste artefacts (`@url:`, backticks) inside `.env` values or shell commands.

## Git workflow

- No push without permission. Feature branches off `develop` with ≤3-word names and no prefix; merge with `--no-ff`; `develop` → `main` only when asked. Never push `old-origin`.
- Production uses `git reset --hard origin/main` instead of `git pull` to avoid merge conflicts (production should have no local edits).
- No co-author lines or AI mentions in commits/comments; LF line endings in new files; keep diffs minimal.

- **First Python service start is slow** — EasyOCR downloads ~2 GB of weights; fast-path uploads work immediately.
