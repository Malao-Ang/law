<?php

namespace App\Services;

use MongoDB\Collection;

final class LawCommentStore
{
    public function __construct(private readonly Collection $collection)
    {
        $this->collection->createIndex(['document_id' => 1, 'created_at' => -1]);
    }

    /**
     * @param  array<string, mixed>  $comment
     * @return array<string, mixed>
     */
    public function insert(array $comment): array
    {
        $this->collection->insertOne($comment);

        return $comment;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $document = $this->collection->findOne(['_id' => $id]);

        return $document === null ? null : (array) $document;
    }

    /** @param array<string, mixed> $fields */
    public function update(string $id, array $fields): void
    {
        $this->collection->updateOne(['_id' => $id], ['$set' => $fields]);
    }

    /** @return list<array<string, mixed>> */
    public function forDocument(string $documentId): array
    {
        $rows = [];
        $cursor = $this->collection->find(
            ['document_id' => $documentId],
            ['sort' => ['created_at' => -1]],
        );
        foreach ($cursor as $document) {
            $rows[] = (array) $document;
        }

        return $rows;
    }

    public function delete(string $id): void
    {
        $this->collection->deleteOne(['_id' => $id]);
    }

    public function truncate(): void
    {
        $this->collection->deleteMany([]);
    }
}
