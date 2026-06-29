<?php

namespace App\Services\Fast;

use Smalot\PdfParser\Parser;

class FastPdfTextExtractor
{
    public function __construct(
        private readonly int $minTextLength = 50,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(string $pdfPath, string $documentId): array
    {
        $parser = new Parser;
        $pdf = $parser->parseFile($pdfPath);

        $sourceFile = basename($pdfPath);
        $pages = [];
        $globalReadingOrder = 1;
        $totalTextLength = 0;

        foreach ($pdf->getPages() as $index => $page) {
            $pageNo = $index + 1;
            $blocks = [];

            $rawText = $page->getText();
            $totalTextLength += strlen(trim($rawText));

            foreach ($this->splitIntoParagraphs($rawText) as $paragraphText) {
                $blocks[] = $this->makeParagraphBlock(
                    pageNo: $pageNo,
                    readingOrder: $globalReadingOrder,
                    text: $paragraphText,
                );
                $globalReadingOrder++;
            }

            $pages[] = [
                'page_no' => $pageNo,
                'image_path' => null,
                'image_url' => null,
                'source_kind' => 'pdf_text',
                'blocks' => $blocks,
            ];
        }

        if ($totalTextLength < $this->minTextLength) {
            throw new FastPathUnsupportedException(
                reason: 'PDF contains too little text and likely needs OCR',
                detectedType: 'pdf_scan',
            );
        }

        $totalBlocks = array_sum(array_map(static fn (array $page): int => count($page['blocks']), $pages));

        return [
            'document_id' => $documentId,
            'source_file' => $sourceFile,
            'source_type' => 'pdf_text',
            'language' => 'th',
            'summary' => [
                'page_count' => count($pages),
                'block_count' => $totalBlocks,
                'review_required_count' => 0,
            ],
            'pages' => $pages,
            'timings' => null,
            'extraction' => [
                'scan_extraction_mode_requested' => null,
                'scan_extraction_mode_effective' => null,
                'path' => ['fast:php:pdf_text'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function splitIntoParagraphs(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = preg_split('/\n{2,}/u', $text) ?: [];

        $paragraphs = [];
        foreach ($parts as $part) {
            $cleaned = trim(preg_replace('/[ \t]+/u', ' ', $part) ?? $part);
            $cleaned = str_replace("\n", ' ', $cleaned);
            if ($cleaned !== '') {
                $paragraphs[] = $cleaned;
            }
        }

        return $paragraphs;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeParagraphBlock(int $pageNo, int $readingOrder, string $text): array
    {
        return [
            'block_id' => sprintf('p%d-b%04d', $pageNo, $readingOrder),
            'type' => 'paragraph',
            'bbox' => null,
            'reading_order' => $readingOrder,
            'raw_text' => $text,
            'normalized_text' => $text,
            'ai_suggested_text' => $text,
            'approved_text' => $text,
            'confidence' => 1.0,
            'needs_review' => false,
            'flags' => ['fast_extracted'],
            'meta' => [
                'section_path' => null,
                'reviewed_html' => null,
                'layout' => [
                    'bbox' => null,
                    'reading_order' => $readingOrder,
                    'alignment' => null,
                    'indent_left' => null,
                    'indent_first_line' => null,
                    'indent_hanging' => null,
                    'indent_level' => null,
                    'tabs' => [],
                ],
                'list_marker' => null,
                'image' => null,
                'table' => null,
                'spell_suggestions' => [],
                'formatting' => [
                    'bold' => false,
                    'italic' => false,
                    'underline' => false,
                ],
            ],
        ];
    }
}
