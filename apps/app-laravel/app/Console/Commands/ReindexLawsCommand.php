<?php

namespace App\Console\Commands;

use App\Services\ExportService;
use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexDefinition;
use App\Services\Search\LawIndexer;
use Illuminate\Console\Command;
use Throwable;

class ReindexLawsCommand extends Command
{
    protected $signature = 'laws:reindex
        {--id= : Reindex only this document id}
        {--fresh : Drop and recreate the index first}
        {--reexport : Regenerate export JSON from current blocks before indexing (fixes empty text field)}';

    protected $description = 'Rebuild the Elasticsearch law index from published export JSON.';

    public function handle(ElasticClient $client, LawIndexer $indexer, ReviewStore $store, ExportService $exporter): int
    {
        if ($this->option('fresh')) {
            $client->deleteIndex();
            $this->info('Dropped existing index.');
        }

        if (! $client->indexExists()) {
            $client->createIndex(LawIndexDefinition::definition());
            $this->info('Created index with current mapping.');
        }

        $reexport = (bool) $this->option('reexport');

        $documentId = $this->option('id');
        if (is_string($documentId) && $documentId !== '') {
            $this->indexOne($documentId, $indexer, $reexport ? $exporter : null);
            $this->info("Reindexed {$documentId}.");

            return self::SUCCESS;
        }

        $ids = array_values(array_filter(array_column($store->listLawMeta(), 'document_id'), 'is_string'));
        $bar = $this->output->createProgressBar(count($ids));

        foreach ($ids as $id) {
            $this->indexOne($id, $indexer, $reexport ? $exporter : null);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Reindexed '.count($ids).' law(s).');

        return self::SUCCESS;
    }

    private function indexOne(string $id, LawIndexer $indexer, ?ExportService $exporter): void
    {
        if ($exporter !== null) {
            try {
                $exporter->export($id);
            } catch (Throwable $e) {
                $this->warn("  Export failed for {$id}: {$e->getMessage()}");
            }
        }

        $indexer->index($id);
    }
}
