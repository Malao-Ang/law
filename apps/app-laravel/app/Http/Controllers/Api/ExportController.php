<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CorrectDocumentJob;
use App\Jobs\IngestRagJob;
use App\Services\ExportService;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService $exportService,
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

        $this->reviewStore->setStatus($documentId, [
            'status' => 'ingesting',
            'current_step' => 'rag_ingest_queued',
            'progress' => 100,
            'export_path' => $result['export_path'],
        ]);

        IngestRagJob::dispatch($documentId);

        $response = array_merge($result, ['rag_status' => 'queued']);

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
