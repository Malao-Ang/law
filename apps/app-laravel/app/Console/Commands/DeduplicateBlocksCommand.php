<?php

namespace App\Console\Commands;

use App\Services\Fast\FastDocxExtractor;
use App\Services\ReviewStore;
use Illuminate\Console\Command;

class DeduplicateBlocksCommand extends Command
{
    protected $signature = 'docs:dedup-blocks
        {--dry-run : Show what would be removed without saving}';

    protected $description = 'Remove exact-duplicate text blocks from all stored review documents.';

    public function handle(ReviewStore $store): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $docsAffected = 0;
        $totalRemoved = 0;

        foreach ($store->listDocuments() as $documentMeta) {
            $documentId = (string) ($documentMeta['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }

            try {
                $document = $store->getReviewDocument($documentId);
            } catch (\Throwable) {
                $this->warn("Skipping {$documentId}: unable to read review document.");

                continue;
            }

            $removed = 0;
            foreach (array_keys($document['pages'] ?? []) as $pageIndex) {
                $blocks = is_array($document['pages'][$pageIndex]['blocks'] ?? null)
                    ? $document['pages'][$pageIndex]['blocks']
                    : [];

                $deduplicated = FastDocxExtractor::deduplicateBlocks($blocks);
                $removed += count($blocks) - count($deduplicated);
                $document['pages'][$pageIndex]['blocks'] = $deduplicated;
            }

            if ($removed === 0) {
                continue;
            }

            $docsAffected++;
            $totalRemoved += $removed;

            if ($dryRun) {
                $this->line("Would remove {$removed} duplicate block(s) from {$documentId}.");

                continue;
            }

            $document['summary'] = $this->buildSummary($document);
            $store->writeReviewDocument($documentId, $document);
            $this->info("Removed {$removed} duplicate block(s) from {$documentId}.");
        }

        if ($dryRun) {
            $this->info("Dry run complete. {$docsAffected} document(s) would change; {$totalRemoved} block(s) would be removed.");

            return self::SUCCESS;
        }

        $this->info("Dedup complete. Updated {$docsAffected} document(s); removed {$totalRemoved} block(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{page_count: int, block_count: int, review_required_count: int}
     */
    private function buildSummary(array $document): array
    {
        $pages = is_array($document['pages'] ?? null) ? $document['pages'] : [];
        $blockCount = 0;
        $reviewCount = 0;

        foreach ($pages as $page) {
            foreach (($page['blocks'] ?? []) as $block) {
                $blockCount++;
                if ((bool) ($block['needs_review'] ?? false)) {
                    $reviewCount++;
                }
            }
        }

        return [
            'page_count' => count($pages),
            'block_count' => $blockCount,
            'review_required_count' => $reviewCount,
        ];
    }
}
