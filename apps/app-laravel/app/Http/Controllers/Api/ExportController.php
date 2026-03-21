<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        try {
            $result = $this->exportService->export($documentId);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        $this->reviewStore->setStatus($documentId, [
            'status' => 'exported',
            'current_step' => 'exported',
            'progress' => 100,
            'export_path' => $result['export_path'],
        ]);

        return response()->json($result);
    }
}
