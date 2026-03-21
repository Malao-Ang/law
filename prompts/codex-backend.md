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
