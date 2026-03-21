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
