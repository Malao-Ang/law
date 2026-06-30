<?php

namespace App\Services\Fast;

use App\Services\Fast\Docx\DocxArchive;
use App\Services\Fast\Docx\ImageExtractor;
use App\Services\Fast\Docx\NumberingResolver;
use App\Services\Fast\Docx\ParagraphParser;
use App\Services\Fast\Docx\TableHtmlRenderer;
use App\Services\Fast\Docx\TableParser;
use App\Services\Fast\Docx\WordXml;
use DOMElement;

class FastDocxExtractor
{
    public function __construct(
        private readonly ?ParagraphParser $paragraphParser = null,
        private readonly ?TableParser $tableParser = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(string $docxPath, string $documentId, ?string $imagesDir = null): array
    {
        $archive = new DocxArchive($docxPath);
        $document = $archive->documentXml();
        $xpath = WordXml::createXPath($document);
        $body = $xpath->query('/w:document/w:body')->item(0);
        $sourceFile = basename($docxPath);
        $paragraphParser = $this->paragraphParser ?? new ParagraphParser;
        $tableParser = $this->tableParser ?? new TableParser($paragraphParser, new TableHtmlRenderer);
        $numberingResolver = new NumberingResolver($archive->numberingXml());
        $imageExtractor = $imagesDir !== null
            ? new ImageExtractor($archive, $documentId, $imagesDir)
            : null;

        $blocks = [];
        $readingOrder = 1;

        if ($body instanceof DOMElement) {
            foreach ($body->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                $block = null;
                if ($child->localName === 'p') {
                    if ($imageExtractor !== null) {
                        foreach ($imageExtractor->fromParagraph($child) as $imageMeta) {
                            $imageBlock = $this->makeBlock('image', '', $readingOrder, [], []);
                            $imageBlock['meta']['image'] = $imageMeta;
                            $blocks[] = $imageBlock;
                            $readingOrder++;
                        }
                    }

                    $paragraph = $paragraphParser->parse($child, $readingOrder, $numberingResolver);
                    if ($paragraph !== null) {
                        $block = $this->makeBlock(
                            $paragraph['type'],
                            (string) $paragraph['text'],
                            $readingOrder,
                            is_array($paragraph['formatting'] ?? null) ? $paragraph['formatting'] : [],
                            is_array($paragraph['layout'] ?? null) ? $paragraph['layout'] : [],
                        );
                        if (is_array($paragraph['numbering'] ?? null)) {
                            $block['meta']['numbering'] = $paragraph['numbering'];
                        }
                    }
                } elseif ($child->localName === 'tbl') {
                    $table = $tableParser->parse($child);
                    if ($table !== null) {
                        $block = $this->makeBlock('table', (string) $table['text'], $readingOrder, [], []);
                        $block['meta']['table'] = $table;
                    }
                }

                if ($block === null) {
                    continue;
                }

                $blocks[] = $block;
                $readingOrder++;
            }
        }

        $reviewRequiredCount = count(array_filter($blocks, static fn (array $block): bool => (bool) ($block['needs_review'] ?? false)));

        return [
            'document_id' => $documentId,
            'source_file' => $sourceFile,
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => count($blocks),
                'review_required_count' => $reviewRequiredCount,
            ],
            'pages' => [[
                'page_no' => 1,
                'image_path' => null,
                'image_url' => null,
                'source_kind' => 'docx',
                'blocks' => $blocks,
            ]],
            'timings' => null,
            'extraction' => [
                'scan_extraction_mode_requested' => null,
                'scan_extraction_mode_effective' => null,
                'path' => ['fast:php:docx'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $formatting
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    private function makeBlock(string $type, string $text, int $readingOrder, array $formatting, array $layout): array
    {
        return [
            'block_id' => sprintf('p1-b%04d', $readingOrder),
            'type' => $type,
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
                'layout' => array_merge([
                    'bbox' => null,
                    'reading_order' => $readingOrder,
                    'alignment' => null,
                    'indent_left' => null,
                    'indent_first_line' => null,
                    'indent_hanging' => null,
                    'indent_level' => null,
                    'tabs' => [],
                    'first_line_inferred' => null,
                ], $layout),
                'list_marker' => null,
                'image' => null,
                'table' => null,
                'spell_suggestions' => [],
                'formatting' => array_merge([
                    'bold' => false,
                    'italic' => false,
                    'underline' => false,
                ], $formatting),
            ],
        ];
    }
}
