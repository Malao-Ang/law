<?php

namespace App\Services\Fast;

use App\Services\ReviewStore;
use RuntimeException;

class FastExtractionPipeline
{
    public function __construct(
        private readonly LibreOfficeConverter $libreOffice,
        private readonly FastDocxExtractor $docxExtractor,
        private readonly FastPdfTextExtractor $pdfExtractor,
        private readonly ReviewStore $reviewStore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $documentId, string $relativeFilePath): array
    {
        $absolutePath = $this->reviewStore->absoluteUploadPath($relativeFilePath);
        if (! file_exists($absolutePath)) {
            throw new RuntimeException("Upload file not found at {$absolutePath}");
        }

        $startedAt = (int) round(microtime(true) * 1000);
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $output = match ($ext) {
            'docx' => $this->docxExtractor->extract(
                $absolutePath,
                $documentId,
                $this->reviewStore->absoluteImagesDir($documentId),
            ),
            'doc' => $this->extractLegacyDoc($absolutePath, $documentId),
            'pdf' => $this->pdfExtractor->extract($absolutePath, $documentId),
            default => throw new FastPathUnsupportedException(
                reason: "Unsupported extension '.{$ext}' for fast path",
                detectedType: 'unknown',
            ),
        };

        $output['source_file'] = basename($absolutePath);
        $output['timings'] = [
            'fast_extract' => (int) round(microtime(true) * 1000) - $startedAt,
        ];

        $this->reviewStore->writeReviewDocument($documentId, $output);

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractLegacyDoc(string $docPath, string $documentId): array
    {
        $convertedDocx = $this->libreOffice->convertToDocx($docPath);

        try {
            $output = $this->docxExtractor->extract(
                $convertedDocx,
                $documentId,
                $this->reviewStore->absoluteImagesDir($documentId),
            );
            $output['extraction']['path'] = ['fast:php:doc->docx', 'fast:php:docx'];

            return $output;
        } finally {
            @unlink($convertedDocx);
            @rmdir(dirname($convertedDocx));
        }
    }
}
