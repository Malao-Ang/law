<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentExportService;
use App\Services\ReviewStore;
use Illuminate\Http\Response;
use RuntimeException;

class WordExportController extends Controller
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly DocumentExportService $exportService,
    ) {}

    public function store(string $documentId): Response
    {
        try {
            $document = $this->reviewStore->getReviewDocument($documentId);
        } catch (RuntimeException) {
            abort(404, 'Document not found.');
        }

        $content = $this->exportService->toDocx($document);
        $this->reviewStore->setStatus($documentId, [
            'esign_exported_at' => now()->toIso8601String(),
        ]);
        $filename = $this->exportService->safeFilenameBase($document);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.docx"',
        ]);
    }
}
