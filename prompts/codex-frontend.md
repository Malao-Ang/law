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
