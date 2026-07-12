<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReprocessBlockRequest;
use App\Http\Requests\UpdateBlockLayoutRequest;
use App\Http\Requests\UpdateBlockSizeRequest;
use App\Http\Requests\UpdateBlockRequest;
use App\Http\Requests\UpdateDocumentReviewRequest;
use App\Jobs\ReprocessBlockJob;
use App\Services\DocumentHtmlService;
use App\Services\DocumentPipelineClient;
use App\Services\Permissions\PermissionStore;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly DocumentHtmlService $documentHtmlService,
        private readonly DocumentPipelineClient $pipelineClient,
        private readonly PermissionStore $permissionStore,
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
     * @param  array<string, mixed>  $review
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
        $payload = $request->validated();

        try {
            if (isset($payload['law_meta']) && is_array($payload['law_meta'])) {
                $payload['law_meta'] = $this->normalizePermissionLawMeta($payload['law_meta']);
            }

            $payload = $this->reviewStore->updateDocumentReview($documentId, $payload);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'document_id' => $documentId,
            'status' => 'updated',
            'document_review' => $payload['document_review'],
            'compose_state' => $payload['compose_state'],
            'law_meta' => $payload['law_meta'] ?? [],
            'relations' => $payload['relations'] ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $lawMeta
     * @return array<string, mixed>
     */
    private function normalizePermissionLawMeta(array $lawMeta): array
    {
        $hasScope = array_key_exists('access_scope', $lawMeta);
        $hasGroupIds = array_key_exists('permission_group_ids', $lawMeta);

        if (! $hasScope && ! $hasGroupIds) {
            return $lawMeta;
        }

        $scope = $hasScope ? (string) $lawMeta['access_scope'] : null;

        if ($scope === 'public') {
            $lawMeta['permission_group_ids'] = [];
            return $lawMeta;
        }

        $groupIds = $this->permissionStore->validateGroupIds((array) ($lawMeta['permission_group_ids'] ?? []));

        if ($scope === 'private' && $groupIds === []) {
            throw ValidationException::withMessages([
                'law_meta.permission_group_ids' => ['ต้องเลือกกลุ่มสิทธิ์อย่างน้อย 1 กลุ่มเมื่อกำหนดเป็น Private'],
            ]);
        }

        if ($hasGroupIds || $scope === 'private') {
            $lawMeta['permission_group_ids'] = $groupIds;
        }

        return $lawMeta;
    }

    public function updateWorkflowProgress(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'completed_step' => ['required', 'integer', 'min:1', 'max:6'],
        ]);

        if ($this->reviewStore->getStatus($documentId) === null) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $completedStep = (int) $validated['completed_step'];
        $currentStep = min($completedStep + 1, 6);

        $this->reviewStore->setStatus($documentId, [
            'workflow_completed_step' => $completedStep,
            'workflow_current_step' => $currentStep,
            'workflow_updated_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'document_id' => $documentId,
            'status' => 'updated',
            'workflow_completed_step' => $completedStep,
            'workflow_current_step' => $currentStep,
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

    public function updateBlockSize(UpdateBlockSizeRequest $request, string $documentId, string $blockId): JsonResponse
    {
        try {
            $updatedBlock = $this->reviewStore->patchBlockSize(
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
        $mode = (string) $request->input('scan_extraction_mode', 'gemini');

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
            'current_step' => 'reprocess_page_cloud_ocr',
        ]);

        return response()->json([
            'document_id' => $documentId,
            'page_no' => $pageNo,
            'scan_extraction_mode' => $mode,
            'status' => 'queued',
        ], 202);
    }

    public function reorderBlocks(Request $request, string $documentId): JsonResponse
    {
        $blockIds = $request->input('block_ids', []);

        if (! is_array($blockIds) || empty($blockIds)) {
            return response()->json(['message' => 'block_ids must be a non-empty array'], 422);
        }

        try {
            $this->reviewStore->reorderBlocks($documentId, $blockIds);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'document_id' => $documentId,
            'status' => 'updated',
            'reordered_block_ids' => $blockIds,
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

    public function deleteBlock(Request $request, string $documentId, string $blockId): JsonResponse
    {
        $validated = $request->validate(['page_no' => 'required|integer|min:1']);
        $pageNo = (int) $validated['page_no'];

        try {
            $this->reviewStore->deleteBlock($documentId, $pageNo, $blockId);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(['document_id' => $documentId, 'block_id' => $blockId, 'status' => 'deleted']);
    }

    public function mergeBlocks(Request $request, string $documentId): JsonResponse
    {
        $blockIds = $request->input('block_ids', []);
        if (! is_array($blockIds) || count($blockIds) < 2) {
            return response()->json(['message' => 'block_ids must contain at least 2 IDs'], 422);
        }
        foreach ($blockIds as $id) {
            if (! is_string($id) || trim($id) === '') {
                return response()->json(['message' => 'Each block_id must be a non-empty string'], 422);
            }
        }

        try {
            $merged = $this->reviewStore->mergeBlocks($documentId, $blockIds);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(['document_id' => $documentId, 'status' => 'merged', 'block' => $merged]);
    }

    public function restoreBlocks(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'pages' => 'required|array|min:1',
            'pages.*.page_no' => 'required|integer|min:1',
            'pages.*.blocks' => 'required|array',
            'pages.*.blocks.*' => 'array',
        ]);

        try {
            $this->reviewStore->restoreBlocks($documentId, $validated['pages']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'document_id' => $documentId,
            'status' => 'restored',
        ]);
    }

    public function splitBlock(Request $request, string $documentId, string $blockId): JsonResponse
    {
        $validated = $request->validate([
            'page_no' => 'required|integer|min:1',
            'before_text' => 'nullable|string',
            'before_html' => 'nullable|string',
            'after_text' => 'nullable|string',
            'after_html' => 'nullable|string',
        ]);

        try {
            $result = $this->reviewStore->splitBlock(
                $documentId,
                (int) $validated['page_no'],
                $blockId,
                (string) ($validated['before_text'] ?? ''),
                (string) ($validated['before_html'] ?? ''),
                (string) ($validated['after_text'] ?? ''),
                (string) ($validated['after_html'] ?? ''),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'document_id' => $documentId,
            'status' => 'split',
            'first' => $result['first'],
            'second' => $result['second'],
        ]);
    }

    public function createBlock(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'page_no' => 'required|integer|min:1',
            'after_block_id' => 'nullable|string',
            'type' => 'string|in:paragraph,list_item,section_header,title,footnote',
            'approved_text' => 'string',
            'reviewed_html' => 'string',
            'layout' => 'array',
        ]);

        try {
            $newBlock = $this->reviewStore->createBlock(
                $documentId,
                (int) $validated['page_no'],
                $validated['after_block_id'] ?? null,
                $validated,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['document_id' => $documentId, 'status' => 'created', 'block' => $newBlock]);
    }
}
