<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CorrectDocumentJob;
use App\Services\ExportService;
use App\Services\RagIngestService;
use App\Services\ReviewStore;
use App\Services\Search\LawIndexer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService $exportService,
        private readonly RagIngestService $ragIngestService,
        private readonly LawIndexer $lawIndexer,
        private readonly ReviewStore $reviewStore,
    ) {}

    public function store(string $documentId): JsonResponse
    {
        $status = $this->reviewStore->getStatus($documentId) ?? [];
        $correctionStatus = $status['correction_status'] ?? 'not_required';

        if (in_array($correctionStatus, ['pending', 'in_progress'], true)) {
            return response()->json([
                'message' => 'AI correction is still in progress. Please wait a moment and try again.',
                'error_code' => 'CORRECTION_PENDING',
                'correction_status' => $correctionStatus,
            ], 409);
        }

        try {
            $result = $this->exportService->export($documentId);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        $ingestResult = $this->ragIngestService->ingest($documentId);

        try {
            $this->lawIndexer->index($documentId);
        } catch (Throwable $exception) {
            Log::warning('Law indexing failed (non-fatal)', [
                'document_id' => $documentId,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->reviewStore->setStatus($documentId, [
            'status' => 'ingested',
            'current_step' => 'rag_ingested',
            'progress' => 100,
            'export_path' => $result['export_path'],
            'ingest_path' => $ingestResult['ingest_path'],
            'ingested_chunk_count' => $ingestResult['chunk_count'],
        ]);

        $response = array_merge($result, ['rag_status' => 'ingested']);

        if ($correctionStatus === 'failed') {
            $response['correction_warning'] = true;
            $response['correction_warning_message'] = 'AI correction did not complete. The export uses uncorrected text. You can retry correction from the review page.';
        }

        return response()->json($response);
    }

    public function retryCorrection(string $documentId): JsonResponse
    {
        $status = $this->reviewStore->getStatus($documentId) ?? [];

        if (empty($status)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $correctionStatus = $status['correction_status'] ?? 'not_required';

        if (in_array($correctionStatus, ['pending', 'in_progress'], true)) {
            return response()->json([
                'message' => 'AI correction is already running.',
                'correction_status' => $correctionStatus,
            ], 409);
        }

        $this->reviewStore->setStatus($documentId, [
            'correction_status' => 'pending',
            'current_step' => 'correction_retry_queued',
        ]);

        CorrectDocumentJob::dispatch(
            documentId: $documentId,
            enableAiCorrection: true,
        );

        return response()->json([
            'message' => 'AI correction queued.',
            'correction_status' => 'pending',
        ]);
    }
}
