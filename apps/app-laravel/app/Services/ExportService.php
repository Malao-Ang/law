<?php

namespace App\Services;

class ExportService
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    /**
     * @return array{document_id: string, status: string, export_path: string}
     */
    public function export(string $documentId): array
    {
        $document = $this->reviewStore->getReviewDocument($documentId);
        $chunks = [];
        $counter = 1;
        $title = null;

        foreach (($document['pages'] ?? []) as $page) {
            $pageNo = (int) ($page['page_no'] ?? 1);

            foreach (($page['blocks'] ?? []) as $block) {
                $type = (string) ($block['type'] ?? 'unknown');
                $blockId = (string) ($block['block_id'] ?? '');
                $text = trim((string) ($block['approved_text'] ?? $block['ai_suggested_text'] ?? $block['normalized_text'] ?? $block['raw_text'] ?? ''));

                if ($text === '' || $blockId === '') {
                    continue;
                }

                if ($title === null && $type === 'title') {
                    $title = $text;
                }

                $chunks[] = [
                    'chunk_id' => sprintf('%s_chunk_%04d', $documentId, $counter++),
                    'page_no' => $pageNo,
                    'block_ids' => [$blockId],
                    'section_path' => $block['meta']['section_path'] ?? null,
                    'text' => $text,
                    'meta' => [
                        'type' => $type,
                        'html' => $block['meta']['reviewed_html'] ?? null,
                        'layout' => $block['meta']['layout'] ?? [
                            'bbox' => $block['bbox'] ?? null,
                            'reading_order' => $block['reading_order'] ?? null,
                        ],
                        'table' => $block['meta']['table'] ?? null,
                    ],
                ];
            }
        }

        $exportData = [
            'document_id' => $documentId,
            'document_title' => $title,
            'chunks' => $chunks,
        ];

        $relativePath = $this->reviewStore->writeExport($documentId, $exportData);

        return [
            'document_id' => $documentId,
            'status' => 'exported',
            'export_path' => 'storage/app/poc/'.$relativePath,
        ];
    }
}
