<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ExtractDocumentJob;
use App\Jobs\IndexHistoricalDocumentJob;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function index(): JsonResponse
    {
        return response()->json(['documents' => $this->reviewStore->listDocuments()]);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $extension = strtolower((string) $request->file('file')->getClientOriginalExtension());
        $scanExtractionMode = $extension === 'pdf' ? 'gemini' : 'local';
        $extractionEngine = $extension === 'pdf' ? 'standard' : 'fast';

        $documentId = $this->reviewStore->generateDocumentId();
        $storedFile = $this->reviewStore->storeUpload($request->file('file'), $documentId);

        if ($request->input('document_type') === 'old') {
            $this->reviewStore->createHistoricalStub($documentId, $storedFile['source_file'], [
                'source' => (string) $request->input('source', ''),
                'law_type' => (string) $request->input('law_type', ''),
            ]);

            $this->reviewStore->setStatus($documentId, [
                'status' => 'done',
                'progress' => 100,
                'current_step' => 'historical_stored',
                'source_file' => $storedFile['source_file'],
                'source_path' => $storedFile['relative_path'],
                'document_type' => 'old',
                'correction_status' => 'not_required',
                'review_path' => $this->reviewStore->displayPath($this->reviewStore->reviewRelativePath($documentId)),
            ]);

            IndexHistoricalDocumentJob::dispatch(
                documentId: $documentId,
                relativeFilePath: $storedFile['relative_path'],
            );

            return response()->json(['document_id' => $documentId, 'status' => 'done'], 202);
        }

        // ── existing "new" document path ──
        $this->reviewStore->setStatus($documentId, [
            'status' => 'queued',
            'progress' => 0,
            'current_step' => 'queued',
            'source_file' => $storedFile['source_file'],
            'source_path' => $storedFile['relative_path'],
            'scan_extraction_mode_requested' => $scanExtractionMode,
            'extraction_engine' => $extractionEngine,
            'correction_status' => 'not_required',
            'document_type' => 'new',
        ]);

        ExtractDocumentJob::dispatch(
            documentId: $documentId,
            relativeFilePath: $storedFile['relative_path'],
            enableAiCorrection: (bool) config('services.ocr.enable_ai_correction', true),
            scanExtractionMode: $scanExtractionMode,
            extractionEngine: $extractionEngine,
        );

        return response()->json(['document_id' => $documentId, 'status' => 'queued'], 202);
    }

    public function show(string $documentId): JsonResponse
    {
        $status = $this->reviewStore->getStatus($documentId);

        if ($status === null) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        return response()->json($status);
    }

    public function destroy(string $documentId): JsonResponse
    {
        if (! $this->reviewStore->deleteDocument($documentId)) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        return response()->json([
            'document_id' => $documentId,
            'status' => 'deleted',
        ]);
    }
}
