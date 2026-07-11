<?php

namespace App\Console\Commands;

use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexDefinition;
use App\Services\Search\LawIndexer;
use Illuminate\Console\Command;

class ReindexLawsCommand extends Command
{
    protected $signature = 'laws:reindex {--id= : Reindex only this document id} {--fresh : Drop and recreate the index first}';

    protected $description = 'Rebuild the Elasticsearch law index from published export JSON.';

    public function handle(ElasticClient $client, LawIndexer $indexer, ReviewStore $store): int
    {
        if ($this->option('fresh')) {
            $client->deleteIndex();
            $this->info('Dropped existing index.');
        }

        if (! $client->indexExists()) {
            $client->createIndex(LawIndexDefinition::definition());
            $this->info('Created index with current mapping.');
        }

        $documentId = $this->option('id');
        if (is_string($documentId) && $documentId !== '') {
            $indexer->index($documentId);
            $this->info("Reindexed {$documentId}.");

            return self::SUCCESS;
        }

        $ids = array_values(array_filter(array_column($store->listLawMeta(), 'document_id'), 'is_string'));
        $bar = $this->output->createProgressBar(count($ids));

        foreach ($ids as $id) {
            $indexer->index($id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Reindexed '.count($ids).' law(s).');

        return self::SUCCESS;
    }
}
