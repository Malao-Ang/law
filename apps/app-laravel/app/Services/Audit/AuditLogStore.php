<?php

namespace App\Services\Audit;

use MongoDB\Collection;

final class AuditLogStore
{
    /** @var array<string, string> */
    public const ACTIONS = [
        'create' => 'เพิ่ม',
        'update' => 'แก้ไข',
        'delete' => 'ลบ',
    ];

    /** @var array<string, string> */
    public const RESOURCES = [
        'document' => 'เอกสาร',
        'block' => 'บล็อกเนื้อหา',
        'permission_group' => 'กลุ่มสิทธิ์',
        'oversight' => 'กำกับดูแล',
        'relation' => 'ความสัมพันธ์',
    ];

    public function __construct(private readonly Collection $collection) {}

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public function append(array $entry): array
    {
        $id = 'aud_'.now()->format('Ymd_His').'_'.bin2hex(random_bytes(3));
        $document = [
            '_id' => $id,
            'at' => now()->toIso8601String(),
            'action' => (string) $entry['action'],
            'resource' => (string) $entry['resource'],
            'resource_id' => (string) $entry['resource_id'],
            'document_id' => $this->nullableString($entry['document_id'] ?? null),
            'label' => $this->clip($entry['label'] ?? '', 180),
            'summary' => $this->clip($entry['summary'] ?? '', 240),
            'changes' => $this->changes($entry['changes'] ?? []),
            'actor_name' => $this->clip($entry['actor_name'] ?? 'ไม่ระบุตัวตน', 120),
            'actor_role' => $this->clip($entry['actor_role'] ?? '', 80),
            'ip' => $this->clip($entry['ip'] ?? '', 64),
        ];

        $this->collection->insertOne($document);

        return $this->present($document);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, actions: array<int, array{value: string, label: string}>, resources: array<int, array{value: string, label: string}>}
     */
    public function search(?string $action, ?string $resource, ?string $query, int $limit = 200): array
    {
        $filter = [];
        if (is_string($action) && isset(self::ACTIONS[$action])) {
            $filter['action'] = $action;
        }
        if (is_string($resource) && isset(self::RESOURCES[$resource])) {
            $filter['resource'] = $resource;
        }

        $query = trim((string) $query);
        if ($query !== '') {
            $pattern = preg_quote(mb_substr($query, 0, 80), '/');
            $regex = ['$regex' => $pattern, '$options' => 'i'];
            $filter['$or'] = [
                ['label' => $regex],
                ['summary' => $regex],
                ['resource_id' => $regex],
                ['document_id' => $regex],
                ['actor_name' => $regex],
            ];
        }

        $limit = max(1, min($limit, 200));
        $cursor = $this->collection->find($filter, [
            'sort' => ['at' => -1],
            'limit' => $limit,
        ]);

        $items = [];
        foreach ($cursor as $document) {
            $items[] = $this->present((array) $document);
        }

        return [
            'items' => $items,
            'actions' => $this->options(self::ACTIONS),
            'resources' => $this->options(self::RESOURCES),
        ];
    }

    public function truncate(): void
    {
        $this->collection->deleteMany([]);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function present(array $document): array
    {
        $action = (string) ($document['action'] ?? '');
        $resource = (string) ($document['resource'] ?? '');
        $id = (string) ($document['_id'] ?? $document['id'] ?? '');

        return [
            'id' => $id,
            'at' => (string) ($document['at'] ?? ''),
            'action' => $action,
            'action_label' => self::ACTIONS[$action] ?? $action,
            'resource' => $resource,
            'resource_label' => self::RESOURCES[$resource] ?? $resource,
            'resource_id' => (string) ($document['resource_id'] ?? ''),
            'document_id' => $this->nullableString($document['document_id'] ?? null),
            'label' => (string) ($document['label'] ?? ''),
            'summary' => (string) ($document['summary'] ?? ''),
            'changes' => array_values(array_filter((array) ($document['changes'] ?? []), 'is_string')),
            'actor_name' => (string) ($document['actor_name'] ?? ''),
            'actor_role' => (string) ($document['actor_role'] ?? ''),
            'ip' => (string) ($document['ip'] ?? ''),
        ];
    }

    /**
     * @param  array<string, string>  $map
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $map): array
    {
        $options = [];
        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /** @param  array<int, mixed>  $changes
     * @return array<int, string>
     */
    private function changes(array $changes): array
    {
        $out = [];
        foreach ($changes as $change) {
            $text = $this->clip($change, 240);
            if ($text === '') {
                continue;
            }
            $out[] = $text;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    private function clip(mixed $value, int $limit): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        return mb_substr($text, 0, $limit);
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 180);
    }
}
