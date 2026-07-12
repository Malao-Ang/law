<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReviewStore;
use Illuminate\Http\Response;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use RuntimeException;

class WordExportController extends Controller
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function store(string $documentId): Response
    {
        try {
            $document = $this->reviewStore->getReviewDocument($documentId);
        } catch (RuntimeException) {
            abort(404, 'Document not found.');
        }

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        $fontName = 'TH Sarabun New';
        $bodyStyle = ['name' => $fontName, 'size' => 16];
        $paragraphStyle = ['spaceAfter' => 60, 'alignment' => Jc::BOTH];

        foreach ((array) ($document['pages'] ?? []) as $page) {
            foreach ((array) ($page['blocks'] ?? []) as $block) {
                $text = trim((string) (
                    $block['approved_text']
                    ?? $block['normalized_text']
                    ?? $block['raw_text']
                    ?? ''
                ));

                if ($text === '') {
                    continue;
                }

                $type = (string) ($block['type'] ?? 'paragraph');

                if ($type === 'title') {
                    $section->addText(
                        $text,
                        ['name' => $fontName, 'size' => 20, 'bold' => true],
                        ['alignment' => Jc::CENTER, 'spaceAfter' => 100],
                    );

                    continue;
                }

                if ($type === 'section_header') {
                    $section->addText(
                        $text,
                        ['name' => $fontName, 'size' => 16, 'bold' => true],
                        $paragraphStyle,
                    );

                    continue;
                }

                $section->addText($text, $bodyStyle, $paragraphStyle);
            }
        }

        $lawMeta = is_array($document['law_meta'] ?? null) ? $document['law_meta'] : [];
        $rawTitle = trim((string) ($lawMeta['title'] ?? $document['source_file'] ?? 'document'));
        $baseName = pathinfo($rawTitle, PATHINFO_FILENAME) ?: 'document';
        $safeName = (string) preg_replace('/[^a-zA-Z0-9_\-\.]/u', '_', $baseName);
        $filename = trim($safeName, '._-') ?: 'document';

        $this->reviewStore->setStatus($documentId, [
            'esign_exported_at' => now()->toIso8601String(),
        ]);

        $tempPath = tempnam(sys_get_temp_dir(), 'we_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to create temporary file for Word export.');
        }
        $tempDocxPath = $tempPath.'.docx';

        IOFactory::createWriter($phpWord, 'Word2007')->save($tempDocxPath);
        $content = (string) file_get_contents($tempDocxPath);

        @unlink($tempPath);
        @unlink($tempDocxPath);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.docx"',
        ]);
    }
}
