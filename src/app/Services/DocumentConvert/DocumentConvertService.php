<?php

namespace App\Services\DocumentConvert;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DocumentConvertService
{
    public function __construct(
        private readonly DocxToHtmlConverter $docx,
        private readonly PdfToHtmlConverter $pdf
    ) {}

    public function convertToHtml(string $path, string $ext): string
    {
        if (! file_exists($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $ext = strtolower($ext);

        try {
            return match ($ext) {
                'docx' => $this->docx->convert($path),
                'pdf' => $this->pdf->convert($path),
                default => throw new InvalidArgumentException("Unsupported file type: {$ext}"),
            };
        } catch (\Throwable $e) {
            Log::error('Document conversion failed', [
                'path' => $path,
                'extension' => $ext,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
