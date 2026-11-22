<?php

namespace App\Services;

use MongoDB\Collection;

final class LawCommentStore
{
    public function __construct(private readonly Collection $collection)
    {
        $this->collection->createIndex(['document_id' => 1, 'created_at' => -1]);
        $this->collection->createIndex(['status' => 1, 'created_at' => -1]);
        $this->collection->createIndex(['code' => 1], ['unique' => true, 'sparse' => true]);
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

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = [];
        $cursor = $this->collection->find([], ['sort' => ['created_at' => -1]]);
        foreach ($cursor as $document) {
            $rows[] = (array) $document;
        }

        return $rows;
    }

    public function nextCode(): string
    {
        $highest = 0;
        foreach ($this->collection->find([], ['projection' => ['code' => 1]]) as $row) {
            $code = (string) ($row['code'] ?? '');
            if (preg_match('/^CMT(\d+)$/i', $code, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'CMT'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
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
