<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Cache;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use RuntimeException;

final class MongoBlobStore
{
    public function __construct(private readonly Collection $collection) {}

    /** @return array<int|string, mixed>|null */
    public function read(string $kind, string $id): ?array
    {
        $doc = $this->collection->findOne(['_id' => $id, $kind => ['$exists' => true]]);
        if ($doc === null) {
            return null;
        }
        $sub = $doc[$kind] ?? null;

        return $sub !== null ? (array) $sub : null;
    }

    /** @param array<int|string, mixed> $data */
    public function write(string $kind, string $id, array $data): void
    {
        $this->collection->updateOne(
            ['_id' => $id],
            [
                '$set' => [$kind => $data, 'updated_at' => new UTCDateTime],
                '$inc' => ['_version' => 1],
            ],
            ['upsert' => true],
        );
        if ($kind === 'review' || $kind === 'status') {
            Cache::forget('law-meta-list');
        }
    }

    public function exists(string $kind, string $id): bool
    {
        return $this->collection->countDocuments([
            '_id' => $id,
            $kind => ['$exists' => true, '$ne' => null],
        ]) > 0;
    }

    /** @param callable(array<int|string, mixed> &): void $cb */
    public function withLock(string $kind, string $id, callable $cb): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $doc = $this->collection->findOne(['_id' => $id]);
            $version = $doc !== null ? (int) ($doc['_version'] ?? 0) : -1;
            $data = ($doc !== null && isset($doc[$kind])) ? (array) $doc[$kind] : [];

            $cb($data);

            if ($version === -1) {
                try {
                    $this->collection->insertOne([
                        '_id' => $id,
                        $kind => $data,
                        '_version' => 1,
                        'updated_at' => new UTCDateTime,
                    ]);
                    if ($kind === 'review' || $kind === 'status') {
                        Cache::forget('law-meta-list');
                    }

                    return;
                } catch (BulkWriteException) {
                    continue;
                }
            }

            $result = $this->collection->updateOne(
                ['_id' => $id, '_version' => $version],
                [
                    '$set' => [$kind => $data, 'updated_at' => new UTCDateTime],
                    '$inc' => ['_version' => 1],
                ],
            );

            if ($result->getMatchedCount() > 0) {
                if ($kind === 'review' || $kind === 'status') {
                    Cache::forget('law-meta-list');
                }

                return;
            }
        }

        throw new RuntimeException("MongoBlobStore: failed to commit after 3 retries ({$kind}/{$id})");
    }

    /**
     * Return the `status` sub-field of every document that has one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allStatuses(): array
    {
        $cursor = $this->collection->find(
            ['status' => ['$exists' => true]],
            ['projection' => ['status' => 1, '_id' => 0]],
        );

        $out = [];
        foreach ($cursor as $doc) {
            $sub = $doc['status'] ?? null;
            if ($sub !== null) {
                $out[] = (array) $sub;
            }
        }

        return $out;
    }

    /** Remove all documents — used in tests only. */
    public function truncate(): void
    {
        $this->collection->deleteMany([]);
    }
}
