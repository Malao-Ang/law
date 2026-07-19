<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fast\LibreOfficeConverter;
use App\Services\ReviewStore;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

class OriginalPdfExportController extends Controller
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly LibreOfficeConverter $libreOffice,
    ) {}

    public function store(string $documentId): Response
    {
        $status = $this->reviewStore->getStatus($documentId);
        if ($status === null) {
            abort(404, 'Document not found.');
        }

        $relative = (string) ($status['source_path'] ?? '');
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (! in_array($ext, ['docx', 'doc'], true)) {
            return response('Original is not a Word document.', 422);
        }

        $absolute = $this->reviewStore->absoluteUploadPath($relative);
        if (! is_file($absolute)) {
            abort(404, 'Original file missing.');
        }

        $pdfPath = null;
        try {
            $pdfPath = $this->libreOffice->convertToPdf($absolute);
            $bytes = (string) file_get_contents($pdfPath);
            if ($bytes === '') {
                return response('PDF service unavailable', 503);
            }
        } catch (\Throwable) {
            return response('PDF service unavailable', 503);
        } finally {
            if ($pdfPath !== null) {
                @unlink($pdfPath);
                @rmdir(dirname($pdfPath));
            }
        }

        $base = pathinfo((string) ($status['source_file'] ?? 'document'), PATHINFO_FILENAME) ?: 'document';
        $filenameWithExt = $base.'.pdf';
        $asciiFallback = trim((string) preg_replace('/[^\x20-\x7e]/', '', $filenameWithExt)) ?: 'document.pdf';
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filenameWithExt,
            $asciiFallback,
        );

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]);
    }
}
