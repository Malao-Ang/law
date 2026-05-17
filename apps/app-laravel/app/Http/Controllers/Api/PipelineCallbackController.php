<?php

namespace App\Http\Controllers\Api;

use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineCallbackController
{
    public function receive(Request $request, ReviewStore $reviewStore): JsonResponse
    {
        $documentId = (string) $request->input('document_id', '');
        $status = (string) $request->input('status', '');

        if ($documentId === '') {
            return response()->json(['error' => 'Invalid callback payload: missing document_id'], 422);
        }

        // Handle failure callbacks from Python service
        if ($status === 'failed') {
            $error = (string) $request->input('error', 'Unknown error');
            $errorCode = (string) $request->input('error_code', 'UNKNOWN_ERROR');

            $reviewStore->setStatus($documentId, [
                'status' => 'failed',
                'progress' => 0,
                'current_step' => 'extract_document',
                'error' => $error,
                'error_code' => $errorCode,
            ]);

            return response()->json(['status' => 'failure_received']);
        }

        // Handle success callbacks
        $output = $request->input('output');
        if (! is_array($output)) {
            return response()->json(['error' => 'Invalid callback payload: missing output'], 422);
        }

        $reviewStore->writeReviewDocument($documentId, $output);
        $extraction = is_array($output['extraction'] ?? null) ? $output['extraction'] : [];

        $reviewStore->setStatus($documentId, [
            'status' => 'done',
            'progress' => 100,
            'current_step' => 'completed',
            'review_path' => 'storage/app/poc/'.$reviewStore->reviewRelativePath($documentId),
            'scan_extraction_mode_requested' => $extraction['scan_extraction_mode_requested'] ?? null,
            'scan_extraction_mode_effective' => $extraction['scan_extraction_mode_effective'] ?? null,
            'extraction_path' => $extraction['path'] ?? null,
            'timings' => $output['timings'] ?? null,
        ]);

        return response()->json(['status' => 'received']);
    }
}
