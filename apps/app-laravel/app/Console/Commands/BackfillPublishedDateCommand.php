<?php

namespace App\Console\Commands;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BackfillPublishedDateCommand extends Command
{
    protected $signature = 'laws:backfill-published-date {--dry-run : Report changes without writing}';

    protected $description = 'Set published_date on already-ingested documents that lack it, so they stay visible after the publish-visibility filter is enabled.';

    public function handle(MongoBlobStore $blob): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        foreach ($blob->allStatuses() as $status) {
            $documentId = (string) ($status['document_id'] ?? '');
            if ($documentId === '' || ($status['status'] ?? '') !== 'ingested') {
                continue;
            }

            $review = $blob->read('review', $documentId);
            if ($review === null) {
                continue;
            }

            $meta = is_array($review['law_meta'] ?? null) ? $review['law_meta'] : [];
            if (trim((string) ($meta['published_date'] ?? '')) !== '') {
                continue;
            }

            $stamp = trim((string) ($meta['promulgation_date'] ?? '')) ?: now()->toDateString();
            $this->line("{$documentId} -> published_date = {$stamp}");
            $updated++;

            if (! $dryRun) {
                $meta['published_date'] = $stamp;
                $review['law_meta'] = $meta;
                $blob->write('review', $documentId, $review);
            }
        }

        if (! $dryRun) {
            Cache::forget('law-meta-list');
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Backfilled published_date on {$updated} document(s).");

        return self::SUCCESS;
    }
}
