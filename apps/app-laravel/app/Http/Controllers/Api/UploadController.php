<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ExtractDocumentJob;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $documentId = $this->reviewStore->generateDocumentId();
        $storedFile = $this->reviewStore->storeUpload($request->file('file'), $documentId);

        $this->reviewStore->setStatus($documentId, [
            'status' => 'queued',
            'progress' => 0,
            'current_step' => 'queued',
            'source_file' => $storedFile['source_file'],
            'source_path' => $storedFile['relative_path'],
        ]);

        ExtractDocumentJob::dispatch(
            documentId: $documentId,
            relativeFilePath: $storedFile['relative_path'],
            enableAiCorrection: (bool) config('services.ocr.enable_ai_correction', true),
        );

        return response()->json([
            'document_id' => $documentId,
            'status' => 'queued',
        ], 202);
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
}
