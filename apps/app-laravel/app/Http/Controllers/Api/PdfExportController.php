<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentExportService;
use App\Services\ReviewStore;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PdfExportController extends Controller
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly DocumentExportService $exportService,
    ) {}

    public function store(string $documentId): Response
    {
        $response = $this->buildPdfResponse($documentId, HeaderUtils::DISPOSITION_ATTACHMENT);

        $this->reviewStore->setStatus($documentId, [
            'esign_exported_at' => now()->toIso8601String(),
        ]);

        $this->reviewStore->patchLawMeta($documentId, ['access_scope' => 'public']);

        return $response;
    }

    public function preview(string $documentId): Response
    {
        return $this->buildPdfResponse($documentId, HeaderUtils::DISPOSITION_INLINE);
    }

    private function buildPdfResponse(string $documentId, string $dispositionType): Response
    {
        try {
            $document = $this->reviewStore->getReviewDocument($documentId);
        } catch (RuntimeException) {
            abort(404, 'Document not found.');
        }

        try {
            $pdfBytes = $this->exportService->toPdf($document);
        } catch (RuntimeException $exception) {
            $status = $exception->getMessage() === 'PDF service unavailable' ? 503 : 500;

            return response($exception->getMessage(), $status);
        }

        $filenameWithExt = $this->exportService->safeFilenameBase($document).'.pdf';
        $asciiFallback = trim((string) preg_replace('/[^\x20-\x7e]/', '', $filenameWithExt)) ?: 'document.pdf';
        $disposition = HeaderUtils::makeDisposition(
            $dispositionType,
            $filenameWithExt,
            $asciiFallback,
        );

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]);
    }
}
