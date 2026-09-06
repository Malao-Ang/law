<?php

namespace App\Services\Search;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\ClientResponseException;

class ElasticClient
{
    private Client $client;
    private string $index;

    public function __construct()
    {
        $this->client = ClientBuilder::create()
            ->setHosts([config('search.host')])
            ->setRetries((int) config('search.retries', 0))
            ->setHttpClientOptions([
                'connect_timeout' => (float) config('search.connect_timeout', 0.5),
                'timeout' => (float) config('search.timeout', 1.5),
            ])
            ->build();
        $this->index = config('search.index');
    }

    public function indexExists(): bool
    {
        try {
            return $this->client->indices()->exists(['index' => $this->index])->getStatusCode() === 200;
        } catch (ClientResponseException $e) {
            return false;
        }
    }

    public function createIndex(array $definition): void
    {
        $this->client->indices()->create(['index' => $this->index, 'body' => $definition]);
    }

    public function deleteIndex(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        try {
            $this->client->indices()->delete(['index' => $this->index]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    public function deleteByLawId(string $lawId): void
    {
        if (! $this->indexExists()) {
            return;
        }
        $this->client->deleteByQuery([
            'index'   => $this->index,
            'refresh' => true,
            'body'    => ['query' => ['term' => ['law_id' => $lawId]]],
        ]);
    }

    /** @param array<int,array<string,mixed>> $docs each must contain 'chunk_id' */
    public function bulkIndex(array $docs): void
    {
        if ($docs === []) {
            return;
        }
        $ops = [];
        foreach ($docs as $doc) {
            $ops[] = ['index' => ['_index' => $this->index, '_id' => $doc['chunk_id']]];
            $ops[] = $doc;
        }
        $this->client->bulk(['refresh' => true, 'body' => $ops]);
    }

    /** @return array raw ES response */
    public function search(array $body): array
    {
        return $this->client->search(['index' => $this->index, 'body' => $body])->asArray();
    }
}
