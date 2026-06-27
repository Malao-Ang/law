<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReprocessBlockRequest;
use App\Http\Requests\UpdateBlockLayoutRequest;
use App\Http\Requests\UpdateBlockRequest;
use App\Http\Requests\UpdateDocumentReviewRequest;
use App\Jobs\ReprocessBlockJob;
use App\Services\DocumentHtmlService;
use App\Services\DocumentPipelineClient;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly DocumentHtmlService $documentHtmlService,
        private readonly DocumentPipelineClient $pipelineClient,
    ) {}

    public function show(string $documentId): JsonResponse
    {
        try {
            $review = $this->reviewStore->getReviewDocument($documentId);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Review document not found.'], 404);
        }

        return response()->json($this->decorateReviewPayload($documentId, $review));
    }

    public function preview(string $documentId): JsonResponse
    {
        try {
            $review = $this->reviewStore->getReviewDocument($documentId);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Review document not found.'], 404);
        }

        $html = $this->documentHtmlService->buildGeneratedHtml($review);
        $documentReview = is_array($review['document_review'] ?? null) ? $review['document_review'] : [];

        return response()->json([
            'document_id' => $documentId,
            'html' => $html,
            'draft_html' => $documentReview['draft_html'] ?? $html,
            'html_mode' => $documentReview['html_mode'] ?? 'generated',
            'out_of_sync' => $documentReview['out_of_sync'] ?? false,
            'source_file' => $review['source_file'] ?? null,
            'source_type' => $review['source_type'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function decorateReviewPayload(string $documentId, array $review): array
    {
        $sourceType = (string) ($review['source_type'] ?? '');
        $pages = is_array($review['pages'] ?? null) ? $review['pages'] : [];

        $review['pages'] = array_map(function (mixed $page) use ($documentId, $sourceType): mixed {
            if (! is_array($page)) {
                return $page;
            }

            $pageNo = (int) ($page['page_no'] ?? 0);
            $hasImage = ! empty($page['image_path']);
            $page['source_kind'] = $page['source_kind']
                ?? ($hasImage || $sourceType === 'pdf_scan' ? 'pdf_scan' : ($sourceType === 'docx' ? 'docx' : 'pdf_text'));
            $page['image_url'] = $hasImage && $pageNo > 0
                ? '/api/documents/'.rawurlencode($documentId).'/pages/'.$pageNo.'/image'
                : null;

            return $page;
        }, $pages);

        return $review;
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

    public function updateDocumentReview(UpdateDocumentReviewRequest $request, string $documentId): JsonResponse
    {
        try {
            $payload = $this->reviewStore->updateDocumentReview($documentId, $request->validated());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'document_id' => $documentId,
            'status' => 'updated',
            'document_review' => $payload['document_review'],
            'compose_state' => $payload['compose_state'],
        ]);
    }

    public function updateLayout(UpdateBlockLayoutRequest $request, string $documentId, string $blockId): JsonResponse
    {
        try {
            $updatedBlock = $this->reviewStore->patchBlockLayout(
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

    public function reprocessPage(Request $request, string $documentId): JsonResponse
    {
        $pageNo = (int) $request->input('page_no', 0);
        $mode = (string) $request->input('scan_extraction_mode', 'landingai');

        if ($pageNo < 1) {
            return response()->json(['message' => 'page_no must be >= 1'], 422);
        }

        try {
            $status = $this->reviewStore->getStatus($documentId);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $sourceFile = (string) ($status['source_file'] ?? '');
        if ($sourceFile === '') {
            return response()->json(['message' => 'Source file path not available.'], 422);
        }

        $callbackUrl = route('pipeline.callback');
        $this->pipelineClient->reprocessPage(
            documentId: $documentId,
            relativeInputPath: 'uploads/'.$documentId.'/'.$sourceFile,
            pageNo: $pageNo,
            callbackUrl: $callbackUrl,
            scanExtractionMode: $mode,
        );

        $this->reviewStore->setStatus($documentId, [
            'status' => 'processing',
            'current_step' => 'reprocess_page_landingai',
        ]);

        return response()->json([
            'document_id' => $documentId,
            'page_no' => $pageNo,
            'scan_extraction_mode' => $mode,
            'status' => 'queued',
        ], 202);
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
