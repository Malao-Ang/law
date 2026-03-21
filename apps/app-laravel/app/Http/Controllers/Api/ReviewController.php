<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReprocessBlockRequest;
use App\Http\Requests\UpdateBlockRequest;
use App\Jobs\ReprocessBlockJob;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function show(string $documentId): JsonResponse
    {
        try {
            $review = $this->reviewStore->getReviewDocument($documentId);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Review document not found.'], 404);
        }

        return response()->json($review);
    }

    public function update(UpdateBlockRequest $request, string $documentId, string $blockId): JsonResponse
    {
        try {
            $updatedBlock = $this->reviewStore->patchApprovedBlock(
                documentId: $documentId,
                pageNo: (int) $request->validated('page_no'),
                blockId: $blockId,
                patch: $request->validated(),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'document_id' => $documentId,
            'block_id' => $blockId,
            'status' => 'updated',
            'block' => $updatedBlock,
        ]);
    }

    public function reprocess(ReprocessBlockRequest $request, string $documentId, string $blockId): JsonResponse
    {
        ReprocessBlockJob::dispatch(
            documentId: $documentId,
            pageNo: (int) $request->validated('page_no'),
            blockId: $blockId,
            mode: (string) $request->validated('mode'),
        );

        $this->reviewStore->setStatus($documentId, [
            'status' => 'processing',
            'current_step' => 'reprocess_block',
        ]);

        return response()->json([
            'document_id' => $documentId,
            'page_no' => (int) $request->validated('page_no'),
            'block_id' => $blockId,
            'status' => 'queued',
        ], 202);
    }
}
