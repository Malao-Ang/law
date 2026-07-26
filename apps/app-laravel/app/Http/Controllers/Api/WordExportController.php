<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentExportService;
use App\Services\ReviewStore;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

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
        $this->reviewStore->patchLawMeta($documentId, ['access_scope' => 'public']);

        $filenameWithExt = $this->exportService->safeFilenameBase($document).'.docx';
        $asciiFallback = trim((string) preg_replace('/[^\x20-\x7e]/', '', $filenameWithExt)) ?: 'document.docx';
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filenameWithExt,
            $asciiFallback,
        );

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => $disposition,
        ]);
    }
}
