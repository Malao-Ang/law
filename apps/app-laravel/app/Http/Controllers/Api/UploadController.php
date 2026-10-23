<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ExtractDocumentJob;
use App\Services\Buu\MinioUploadService;
use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use App\Services\Auth\DocumentOwnership;
use App\Services\Auth\CurrentUser;
use App\Services\Auth\MongoUserStore;
use App\Services\MasterData\UnitStore;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function index(): JsonResponse
    {
        return response()->json(['documents' => $this->reviewStore->listDocuments()]);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $ownership = app(DocumentOwnership::class)->forUpload($request);
        $extension = strtolower((string) $request->file('file')->getClientOriginalExtension());
        $requestedMode = (string) $request->input('scan_extraction_mode', '');
        if ($extension === 'pdf') {
            $scanExtractionMode = in_array($requestedMode, ['gemini', 'landingai'], true)
                ? $requestedMode
                : 'gemini';
            $extractionEngine = 'standard';
        } else {
            $scanExtractionMode = 'local';
            $extractionEngine = 'fast';
        }

        $documentId = $this->reviewStore->generateDocumentId();
        $storedFile = $this->reviewStore->storeUpload($request->file('file'), $documentId);

        $this->reviewStore->setStatus($documentId, $ownership);

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

            // Upload old document to MinIO immediately (non-fatal)
            $sourcePath = $this->reviewStore->absolutePath($storedFile['relative_path']);
            if (is_file($sourcePath)) {
                $minioFilename = app(MinioUploadService::class)->uploadIfEnabled(
                    absolutePath: $sourcePath,
                    originalExtension: strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)),
                    documentId: $documentId,
                    folderPath: '/'.$documentId,
                );
                if ($minioFilename !== null) {
                    $this->reviewStore->setStatus($documentId, [
                        'minio_source_filename' => $minioFilename,
                    ]);
                }
            }

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

    public function transfer(Request $request, string $documentId): JsonResponse
    {
        $actor = app(CurrentUser::class)->user($request);
        abort_if(config('auth_ldap.enforced') && ! ($actor['is_super_admin'] ?? false), 403, 'เฉพาะผู้ดูแลระบบสูงสุดเท่านั้นที่โอนเจ้าของเอกสารได้');
        $before = $this->reviewStore->getStatus($documentId);
        abort_if($before === null, 404, 'ไม่พบเอกสาร');
        $data = $request->validate(['owner_unit_code' => ['required', 'string'], 'username' => ['present', 'nullable', 'string']]);
        $unit = app(UnitStore::class)->find($data['owner_unit_code']);
        abort_unless($unit && $unit['is_active'], 422, 'ไม่พบหน่วยงานที่เปิดใช้งาน');
        $owner = $data['username'] === null ? null : app(MongoUserStore::class)->find($data['username']);
        if ($data['username'] !== null) {
            abort_unless($owner && ! ($owner['is_disabled'] ?? false)
                && in_array($unit['code'], array_column($owner['memberships'] ?? [], 'unit_code'), true), 422, 'ผู้รับผิดชอบต้องเป็นสมาชิกของหน่วยงานเจ้าของเอกสาร');
        }
        $fields = ['owner_unit_code' => $unit['code'], 'responsible' => $owner ? ['username' => $owner['username'], 'name' => $owner['name'] ?? null] : null];
        $this->reviewStore->setStatus($documentId, $fields);
        app(DocumentOwnership::class)->record($request, $documentId, 'transfer', $before, $this->reviewStore->getStatus($documentId));

        return response()->json($fields);
    }

    public function destroy(string $documentId): JsonResponse
    {
        try {
            if (! $this->reviewStore->deleteDocument($documentId)) {
                return response()->json([
                    'message' => 'Document not found.',
                ], 404);
            }
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'document_id' => $documentId,
            'status' => 'deleted',
        ]);
    }
}
