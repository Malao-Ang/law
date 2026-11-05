<?php

namespace App\Services\Audit;

use Carbon\Carbon;
use MongoDB\Collection;

final class AuditLogStore
{
    /** @var array<string, string> */
    public const ACTIONS = [
        'create' => 'เพิ่ม',
        'update' => 'แก้ไข',
        'delete' => 'ลบ',
        'login' => 'เข้าสู่ระบบ', 'logout' => 'ออกจากระบบ', 'login_failed' => 'เข้าสู่ระบบไม่สำเร็จ',
        'view' => 'เปิดดู', 'upload' => 'อัปโหลด', 'publish' => 'เผยแพร่', 'unpublish' => 'ยกเลิกเผยแพร่',
        'export' => 'ส่งออก', 'esign_send' => 'ส่งลงนาม', 'esign_cancel' => 'ยกเลิกลงนาม',
        'esign_signed' => 'ลงนามเสร็จ', 'transfer' => 'โอน', 'toggle' => 'เปลี่ยนสถานะ', 'reorder' => 'เรียงลำดับ',
    ];

    /** @var array<string, string> */
    public const RESOURCES = [
        'document' => 'เอกสาร',
        'block' => 'บล็อกเนื้อหา',
        'permission_group' => 'กลุ่มสิทธิ์',
        'oversight' => 'กำกับดูแล',
        'relation' => 'ความสัมพันธ์',
        'master_data' => 'ข้อมูลหลัก', 'unit' => 'หน่วยงาน', 'membership' => 'สมาชิกหน่วยงาน',
        'audit_log' => 'ประวัติการใช้งาน', 'user' => 'ผู้ใช้', 'auth' => 'การเข้าสู่ระบบ',
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

        foreach (['actor_username', 'active_unit_code', 'unit_code', 'on_behalf_of', 'kind', 'result', 'user_agent', 'before', 'after'] as $field) {
            $document[$field] = $entry[$field] ?? ($field === 'result' ? 'success' : null);
        }
        $document['user_agent'] = $this->clip($document['user_agent'] ?? '', 500);
        $this->collection->insertOne($document);

        return $this->present($document);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, actions: array<int, array{value: string, label: string}>, resources: array<int, array{value: string, label: string}>}
     */
    public function filter(array $input, ?array $units): array
    {
        $filter = [];
        if ($units !== null) {
            $filter['unit_code'] = ['$in' => $units];
        }
        if (! empty($input['unit'])) {
            $unit = (string) $input['unit'];
            $filter['unit_code'] = $units !== null && ! in_array($unit, $units, true) ? ['$in' => []] : $unit;
        }
        $resources = array_values(array_filter((array) ($input['resource'] ?? []), 'is_string'));
        if ($resources !== []) {
            $filter['resource'] = ['$in' => $resources];
        }
        foreach (['action' => 'action', 'actor' => 'actor_username', 'document' => 'document_id'] as $key => $field) {
            if (! empty($input[$key])) {
                $filter[$field] = (string) $input[$key];
            }
        }
        if (! empty($input['from'])) {
            $filter['at']['$gte'] = Carbon::parse($input['from'], 'Asia/Bangkok')->startOfDay()->utc()->toIso8601String();
        }
        if (! empty($input['to'])) {
            $filter['at']['$lte'] = Carbon::parse($input['to'], 'Asia/Bangkok')->endOfDay()->utc()->toIso8601String();
        }
        if (trim((string) ($input['q'] ?? '')) !== '') {
            $regex = ['$regex' => preg_quote(mb_substr(trim($input['q']), 0, 120), '/'), '$options' => 'i'];
            $filter['$or'] = array_map(fn ($field) => [$field => $regex], ['label', 'summary', 'resource_id', 'document_id', 'actor_name', 'actor_username']);
        }

        return $filter;
    }

    public function page(array $filter, int $page, int $perPage): array
    {
        $items = [];
        foreach ($this->collection->find($filter, ['sort' => ['at' => -1, '_id' => -1], 'skip' => ($page - 1) * $perPage, 'limit' => $perPage]) as $document) {
            $items[] = $this->present((array) $document);
        }
        $total = $this->collection->countDocuments($filter);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)), 'actions' => $this->options(self::ACTIONS), 'resources' => $this->options(self::RESOURCES)];
    }

    /** Mongo cursor fetches matching rows in batches without a 200-row cap. */
    public function rows(array $filter): \Generator
    {
        foreach ($this->collection->find($filter, ['sort' => ['at' => -1, '_id' => -1], 'batchSize' => 500]) as $document) {
            yield $this->present((array) $document);
        }
    }

    public function indexes(): void
    {
        foreach (['at', 'resource', 'action', 'unit_code', 'actor_username', 'document_id'] as $field) {
            $this->collection->createIndex([$field => $field === 'at' ? -1 : 1]);
        }
    }

    public function truncate(): void
    {
        if (! app()->runningUnitTests()) {
            throw new \LogicException('Audit logs are append-only.');
        }
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

        unset($document['_id']);

        return array_merge($document, [
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
        ]);
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
