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
        $output = $request->input('output');

        if ($documentId === '' || ! is_array($output)) {
            return response()->json(['error' => 'Invalid callback payload'], 422);
        }

        $reviewStore->writeReviewDocument($documentId, $output);

        $reviewStore->setStatus($documentId, [
            'status' => 'done',
            'progress' => 100,
            'current_step' => 'completed',
            'review_path' => 'storage/app/poc/'.$reviewStore->reviewRelativePath($documentId),
        ]);

        return response()->json(['status' => 'received']);
    }
}
