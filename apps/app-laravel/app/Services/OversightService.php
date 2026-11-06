<?php

namespace App\Services;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Support\Carbon;
use RuntimeException;

final class OversightService
{
    /** @var array<string, array<int, array{sections: array<int, string>}>>|null */
    private ?array $issuedFollowUpIndex = null;
    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'มกราคม',
        2 => 'กุมภาพันธ์',
        3 => 'มีนาคม',
        4 => 'เมษายน',
        5 => 'พฤษภาคม',
        6 => 'มิถุนายน',
        7 => 'กรกฎาคม',
        8 => 'สิงหาคม',
        9 => 'กันยายน',
        10 => 'ตุลาคม',
        11 => 'พฤศจิกายน',
        12 => 'ธันวาคม',
    ];

    public function __construct(private readonly MongoBlobStore $blob) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $records = $this->records();
        $tracked = array_values(array_filter($records, static fn (array $row): bool => $row['tracked']));

        $onTime = 0;
        $late = 0;
        foreach ($tracked as $row) {
            $result = $this->publicationResult($row);
            if ($result === 'on_time') {
                $onTime++;
            } elseif ($result === 'late') {
                $late++;
            }
        }

        $closedWithDeadline = $onTime + $late;
        $items = array_map($this->publicItem(...), $records);
        $nextMonth = array_values(array_filter($items, static fn (array $row): bool => $row['in_next_month']));
        usort($nextMonth, static fn (array $a, array $b): int => strcmp((string) $a['next_action_date'], (string) $b['next_action_date']));

        return [
            'as_of' => $this->today()->toDateString(),
            'next_month_label' => $this->nextMonthLabel(),
            'reminder_days' => $this->reminderDays(),
            'statuses' => $this->statusOptions(),
            'totals' => [
                'tracked' => count($tracked),
                'overdue_publication' => count(array_filter($tracked, static fn (array $row): bool => $row['overdue'])),
                'on_time' => $onTime,
                'late' => $late,
                'compliance_rate' => $closedWithDeadline === 0 ? null : round($onTime / $closedWithDeadline * 100, 1),
            ],
            'by_status' => $this->countByStatus($tracked),
            'next_month' => $nextMonth,
            'items' => $this->sortItems($items),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(string $documentId, array $payload): array
    {
        if ($this->blob->read('review', $documentId) === null) {
            throw new RuntimeException('Review document not found.');
        }

        $this->blob->withLock('review', $documentId, function (array &$doc) use ($payload): void {
            $existing = is_array($doc['oversight'] ?? null) ? $doc['oversight'] : [];
            $doc['oversight'] = $this->storedFromPayload($payload, $existing);
        });

        $fresh = $this->blob->read('review', $documentId);

        return $this->publicItem($this->present($documentId, is_array($fresh) ? $fresh : []));
    }

    /**
     * @return array{subject: string, body: string, link_label: string, reminder_days: array<int, int>}
     */
    public function mailTemplate(): array
    {
        $defaults = $this->defaultMailTemplate();
        $stored = $this->mailSettings();

        return [
            'subject' => $this->storedMailText($stored['subject'] ?? null, $defaults['subject'], 180),
            'body' => $this->storedMailText($stored['body'] ?? null, $defaults['body'], 4000),
            'link_label' => $this->storedMailText($stored['link_label'] ?? null, $defaults['link_label'], 120),
            'reminder_days' => $this->reminderDays(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{subject: string, body: string, link_label: string, reminder_days: array<int, int>}
     */
    public function saveMailTemplate(array $payload): array
    {
        $existing = $this->mailSettings();
        $days = array_key_exists('reminder_days', $payload)
            ? $this->normalizeReminderDays($payload['reminder_days'])
            : $this->normalizeReminderDays($existing['reminder_days'] ?? null);
        if ($days === []) {
            $days = $this->defaultReminderDays();
        }

        $template = [
            'subject' => $this->cleanMailLine($payload['subject'] ?? '', 180),
            'body' => $this->cleanMailBody($payload['body'] ?? '', 4000),
            'link_label' => $this->cleanMailLine($payload['link_label'] ?? '', 120),
            'reminder_days' => $days,
        ];
        $this->blob->write('settings', 'oversight-mail', $template);

        return $template;
    }

    /**
     * @return array{subject: string, body: string, link_label: string, reminder_days: array<int, int>}
     */
    public function defaultMailTemplate(): array
    {
        return [
            'subject' => $this->configMail('mail_subject', 'แจ้งเตือนกำหนดดำเนินการกฎหมาย — เหลือ {days} วัน'),
            'body' => $this->configMail(
                'mail_body',
                "เรียนผู้รับผิดชอบ\n\nกฎหมาย {title} ใกล้ถึงกำหนดดำเนินการ\n\n{deadlines}\n\nกรุณาเข้าตรวจสอบสถานะและวันแล้วเสร็จได้ที่ {link}",
            ),
            'link_label' => $this->configMail('mail_link_label', 'ระบบติดตามการกำกับดูแล'),
            'reminder_days' => $this->defaultReminderDays(),
        ];
    }

    /**
     * Tracked laws that can receive a reminder, including ones that are not due today.
     *
     * @return array<int, array{document_id: string, title: string, emails: array<int, string>, due_now: bool, already_sent: bool, hits: array<int, array<string, mixed>>}>
     */
    public function reminderCatalog(): array
    {
        $offsets = $this->reminderDays();
        $items = [];

        foreach ($this->records() as $record) {
            if (! $record['tracked'] || $record['closed']) {
                continue;
            }

            $emails = $this->emails($record);
            if ($emails === []) {
                continue;
            }

            $allHits = $this->deadlineHits($record);
            if ($allHits === []) {
                continue;
            }

            $sent = is_array($record['reminders_sent'] ?? null) ? $record['reminders_sent'] : [];
            $offsetHits = [];
            $unsentOffset = [];
            foreach ($allHits as $hit) {
                if (! in_array($hit['days_left'], $offsets, true)) {
                    continue;
                }
                $offsetHits[] = $hit;
                if (! isset($sent[$hit['sent_key']])) {
                    $unsentOffset[] = $hit;
                }
            }

            $dueNow = $unsentOffset !== [];
            $items[] = [
                'document_id' => $record['document_id'],
                'title' => $record['title'],
                'emails' => $emails,
                'due_now' => $dueNow,
                'already_sent' => $offsetHits !== [] && ! $dueNow,
                'hits' => $dueNow ? $unsentOffset : ($offsetHits !== [] ? $offsetHits : $allHits),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $daysA = (int) ($a['hits'][0]['days_left'] ?? 9999);
            $daysB = (int) ($b['hits'][0]['days_left'] ?? 9999);

            return [$b['due_now'] ? 1 : 0, $daysA, $a['title']]
                <=> [$a['due_now'] ? 1 : 0, $daysB, $b['title']];
        });

        return $items;
    }

    /**
     * Documents with a deadline landing on a reminder offset that has not been sent.
     *
     * @return array<int, array{document_id: string, title: string, emails: array<int, string>, hits: array<int, array<string, mixed>>}>
     */
    public function pendingReminders(): array
    {
        $batches = [];
        foreach ($this->reminderCatalog() as $item) {
            if (! $item['due_now']) {
                continue;
            }
            $batches[] = [
                'document_id' => $item['document_id'],
                'title' => $item['title'],
                'emails' => $item['emails'],
                'hits' => $item['hits'],
            ];
        }

        return $batches;
    }

    /**
     * @return array{document_id: string, title: string, emails: array<int, string>, hits: array<int, array<string, mixed>>}
     */
    public function manualBatch(string $documentId): array
    {
        if ($this->blob->read('review', $documentId) === null) {
            throw new RuntimeException('ไม่พบเอกสาร', 404);
        }

        foreach ($this->reminderCatalog() as $item) {
            if ($item['document_id'] !== $documentId) {
                continue;
            }

            return [
                'document_id' => $item['document_id'],
                'title' => $item['title'],
                'emails' => $item['emails'],
                'hits' => $item['hits'],
            ];
        }

        foreach ($this->records() as $record) {
            if ($record['document_id'] !== $documentId) {
                continue;
            }
            if (! $record['tracked']) {
                throw new RuntimeException('กฎหมายนี้ยังไม่ได้ติดตาม', 422);
            }
            if ($record['closed']) {
                throw new RuntimeException('กฎหมายนี้ปิดงานแล้ว จึงไม่ส่งแจ้งเตือน', 422);
            }
            if ($this->emails($record) === []) {
                throw new RuntimeException('ยังไม่มีอีเมลผู้รับผิดชอบ', 422);
            }

            throw new RuntimeException('ไม่มีกำหนดที่ยังเปิดอยู่', 422);
        }

        throw new RuntimeException('ไม่พบเอกสาร', 404);
    }

    public function markSent(string $documentId, string $key): void
    {
        if ($this->blob->read('review', $documentId) === null) {
            return;
        }

        $this->blob->withLock('review', $documentId, function (array &$doc) use ($key): void {
            $oversight = is_array($doc['oversight'] ?? null) ? $doc['oversight'] : [];
            $sent = is_array($oversight['reminders_sent'] ?? null) ? $oversight['reminders_sent'] : [];
            $sent[$key] = now()->toIso8601String();
            $oversight['reminders_sent'] = $sent;
            $doc['oversight'] = $oversight;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        $rows = [];
        foreach ($this->blob->allStatuses() as $status) {
            $documentId = (string) ($status['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }

            $review = $this->blob->read('review', $documentId);
            if (! is_array($review)) {
                continue;
            }

            $rows[] = $this->present($documentId, $review);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private function present(string $documentId, array $review): array
    {
        $meta = is_array($review['law_meta'] ?? null) ? $review['law_meta'] : [];
        $tracked = is_array($review['oversight'] ?? null);
        $stored = $tracked ? $review['oversight'] : [];
        $options = $this->statusOptions();
        $labels = array_column($options, 'label');
        $status = $tracked && in_array($stored['status'] ?? '', $labels, true)
            ? (string) $stored['status']
            : null;
        $closed = $status !== null && in_array($status, $this->closedStatuses(), true);

        $storedSteps = [];
        foreach (is_array($stored['steps'] ?? null) ? $stored['steps'] : [] as $step) {
            if (is_array($step) && isset($step['key'])) {
                $storedSteps[(string) $step['key']] = $step;
            }
        }

        $steps = [];
        foreach ($options as $option) {
            $incoming = $storedSteps[$option['key']] ?? [];
            $steps[] = [
                'key' => $option['key'],
                'label' => $option['label'],
                'due_date' => $this->dateOrNull($incoming['due_date'] ?? null),
                'completed_on' => $this->dateOrNull($incoming['completed_on'] ?? null),
            ];
        }

        $publishDue = $tracked ? $this->dateOrNull($stored['publish_due_date'] ?? null) : null;
        $daysLeft = $publishDue === null ? null : $this->daysUntil($publishDue);
        [$nextStart, $nextEnd] = $this->nextMonthRange();

        $actions = [];
        if ($tracked && ! $closed) {
            $publishOn = $this->parseDate($publishDue);
            if ($publishOn !== null && $publishOn->betweenIncluded($nextStart, $nextEnd)) {
                $actions[] = ['date' => $publishOn->toDateString(), 'label' => 'กำหนดเวลาออกประกาศ'];
            }
            foreach ($steps as $step) {
                $stepDue = $this->parseDate($step['due_date']);
                if ($step['completed_on'] === null && $stepDue !== null && $stepDue->betweenIncluded($nextStart, $nextEnd)) {
                    $actions[] = ['date' => $stepDue->toDateString(), 'label' => $step['label']];
                }
            }
            usort($actions, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        }

        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($review['source_file'] ?? '')) ?: $documentId;
        }

        $agencies = is_array($meta['agencies'] ?? null) ? array_values(array_filter($meta['agencies'], 'is_string')) : [];

        return [
            'document_id' => $documentId,
            'title' => $title,
            'law_type' => trim((string) ($meta['law_type'] ?? '')),
            'agencies' => $agencies,
            'tracked' => $tracked,
            'status' => $status,
            'closed' => $closed,
            'publish_due_date' => $publishDue,
            'days_left' => $daysLeft,
            'overdue' => $tracked && ! $closed && $daysLeft !== null && $daysLeft < 0,
            'in_next_month' => $actions !== [],
            'next_action_date' => $actions[0]['date'] ?? null,
            'next_action_label' => $actions[0]['label'] ?? null,
            'primary' => $this->contact($stored['primary'] ?? []),
            'backup' => $this->contact($stored['backup'] ?? []),
            'steps' => $steps,
            'reminders_sent' => is_array($stored['reminders_sent'] ?? null) ? $stored['reminders_sent'] : [],
            'proclamation_deadlines' => $this->proclamationDeadlines($documentId, $review),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function publicItem(array $record): array
    {
        unset($record['reminders_sent'], $record['proclamation_deadlines']);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function storedFromPayload(array $payload, array $existing): array
    {
        $byKey = [];
        foreach ($payload['steps'] ?? [] as $step) {
            if (is_array($step)) {
                $byKey[(string) ($step['key'] ?? '')] = $step;
            }
        }

        $steps = [];
        foreach ($this->statusOptions() as $option) {
            $incoming = $byKey[$option['key']] ?? [];
            $steps[] = [
                'key' => $option['key'],
                'due_date' => $this->dateOrNull($incoming['due_date'] ?? null),
                'completed_on' => $this->dateOrNull($incoming['completed_on'] ?? null),
            ];
        }

        return [
            'status' => (string) $payload['status'],
            'publish_due_date' => $this->dateOrNull($payload['publish_due_date'] ?? null),
            'steps' => $steps,
            'primary' => $this->contact($payload['primary'] ?? []),
            'backup' => $this->contact($payload['backup'] ?? []),
            'reminders_sent' => is_array($existing['reminders_sent'] ?? null) ? $existing['reminders_sent'] : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tracked
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function countByStatus(array $tracked): array
    {
        $buckets = [];
        foreach ($this->statusOptions() as $option) {
            $buckets[] = [
                'key' => $option['key'],
                'label' => $option['label'],
                'count' => count(array_filter(
                    $tracked,
                    static fn (array $row): bool => $row['status'] === $option['label'],
                )),
            ];
        }

        return $buckets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function sortItems(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $dueA = $a['publish_due_date'] ?: '9999-99-99';
            $dueB = $b['publish_due_date'] ?: '9999-99-99';

            return [$b['overdue'] ? 1 : 0, $dueA, $a['title']]
                <=> [$a['overdue'] ? 1 : 0, $dueB, $b['title']];
        });

        return $items;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function publicationResult(array $record): ?string
    {
        if ($record['status'] !== $this->publishedStatus() || $record['publish_due_date'] === null) {
            return null;
        }

        $completedOn = null;
        foreach ($record['steps'] as $step) {
            if ($step['key'] === 'published') {
                $completedOn = $step['completed_on'];
                break;
            }
        }

        if ($completedOn === null) {
            return null;
        }

        return $completedOn <= $record['publish_due_date'] ? 'on_time' : 'late';
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, array{label: string, date: string, display_date: string, days_left: int, sent_key: string}>
     */
    private function deadlineHits(array $record): array
    {
        $hits = [];
        foreach ($this->openDeadlines($record) as $deadline) {
            $days = $this->daysUntil($deadline['date']);
            if ($days === null) {
                continue;
            }
            $hits[] = [
                'label' => $deadline['label'],
                'date' => $deadline['date'],
                'display_date' => $this->formatThai($deadline['date']),
                'days_left' => $days,
                'sent_key' => $deadline['kind'].':'.$deadline['key'].':'.$deadline['date'].':'.$days,
            ];
        }

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, array{kind: string, key: string, label: string, date: string}>
     */
    private function openDeadlines(array $record): array
    {
        $deadlines = [];
        if (is_string($record['publish_due_date']) && $record['publish_due_date'] !== '') {
            $deadlines[] = [
                'kind' => 'publish',
                'key' => 'publish',
                'label' => 'กำหนดเวลาออกประกาศ',
                'date' => $record['publish_due_date'],
            ];
        }

        foreach ($record['steps'] as $step) {
            if (! is_string($step['due_date']) || $step['due_date'] === '' || $step['completed_on'] !== null) {
                continue;
            }
            $deadlines[] = [
                'kind' => 'step',
                'key' => (string) $step['key'],
                'label' => 'ขั้นตอน'.$step['label'],
                'date' => $step['due_date'],
            ];
        }

        foreach (is_array($record['proclamation_deadlines'] ?? null) ? $record['proclamation_deadlines'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $date = $this->dateOrNull($item['date'] ?? null);
            if ($date === null) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $scope = (string) ($item['scope'] ?? 'whole');
            $sections = is_array($item['sections'] ?? null) ? implode(', ', $item['sections']) : '';
            $prefix = $scope === 'sections'
                ? 'ออกประกาศฉบับใหม่บางข้อ'.($sections !== '' ? ' ('.$sections.')' : '')
                : 'ออกประกาศฉบับใหม่ทั้งฉบับ';
            $deadlines[] = [
                'kind' => 'proclamation',
                'key' => (string) ($item['id'] ?? $date),
                'label' => $title !== '' ? $prefix.': '.$title : $prefix,
                'date' => $date,
            ];
        }

        return $deadlines;
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<int, array{id: string, title: string, date: string, scope: string, sections: array<int, string>}>
     */
    private function proclamationDeadlines(string $documentId, array $review): array
    {
        $items = [];
        foreach (is_array($review['relations'] ?? null) ? $review['relations'] : [] as $relation) {
            if (! is_array($relation) || empty($relation['requires_new_proclamation'])) {
                continue;
            }
            $date = $this->dateOrNull($relation['proclamation_due_date'] ?? null);
            if ($date === null || $this->proclamationFulfilled($documentId, $relation)) {
                continue;
            }
            $id = trim((string) ($relation['id'] ?? ''));
            $scope = ($relation['proclamation_scope'] ?? '') === 'sections' ? 'sections' : 'whole';
            $sections = [];
            foreach (is_array($relation['proclamation_sections'] ?? null) ? $relation['proclamation_sections'] : [] as $section) {
                $text = trim((string) $section);
                if ($text !== '') {
                    $sections[] = $text;
                }
            }
            $items[] = [
                'id' => $id !== '' ? $id : $date,
                'title' => trim((string) ($relation['target_title'] ?? '')),
                'date' => $date,
                'scope' => $scope,
                'sections' => $sections,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $relation
     */
    private function proclamationFulfilled(string $documentId, array $relation): bool
    {
        if ($this->dateOrNull($relation['proclamation_issued_on'] ?? null) !== null) {
            return true;
        }

        $scope = ($relation['proclamation_scope'] ?? '') === 'sections' ? 'sections' : 'whole';
        $needed = [];
        foreach (is_array($relation['proclamation_sections'] ?? null) ? $relation['proclamation_sections'] : [] as $section) {
            $text = trim((string) $section);
            if ($text !== '') {
                $needed[$text] = $text;
            }
        }
        $covered = [];
        foreach ($this->issuedFollowUps()[$documentId] ?? [] as $followUp) {
            if ($scope === 'whole') {
                return true;
            }
            foreach ($followUp['sections'] as $section) {
                $covered[$section] = $section;
            }
        }

        return $scope === 'sections' && $needed !== [] && array_diff($needed, $covered) === [];
    }

    /**
     * Laws already in force that cite this document, keyed by the cited document id.
     *
     * @return array<string, array<int, array{sections: array<int, string>}>>
     */
    private function issuedFollowUps(): array
    {
        if ($this->issuedFollowUpIndex !== null) {
            return $this->issuedFollowUpIndex;
        }

        $index = [];
        foreach ($this->blob->allStatuses() as $status) {
            $id = trim((string) ($status['document_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $review = $this->blob->read('review', $id);
            if (! is_array($review)) {
                continue;
            }
            $meta = is_array($review['law_meta'] ?? null) ? $review['law_meta'] : [];
            if (! $this->lawIsInForce($meta['status'] ?? null)) {
                continue;
            }

            $parents = [];
            foreach (['parent_document_ids'] as $key) {
                foreach (is_array($meta[$key] ?? null) ? $meta[$key] : [] as $parent) {
                    $parent = trim((string) $parent);
                    if ($parent !== '') {
                        $parents[$parent] = $parent;
                    }
                }
            }
            $legacyParent = trim((string) ($meta['parent_document_id'] ?? ''));
            if ($legacyParent !== '') {
                $parents[$legacyParent] = $legacyParent;
            }

            $sectionsByParent = [];
            foreach (is_array($review['relations'] ?? null) ? $review['relations'] : [] as $relation) {
                if (! is_array($relation)) {
                    continue;
                }
                $target = trim((string) ($relation['target_document_id'] ?? ''));
                if ($target === '') {
                    continue;
                }
                $parents[$target] = $target;
                foreach (['target_section', 'target_block_id'] as $field) {
                    $section = trim((string) ($relation[$field] ?? ''));
                    if ($section !== '') {
                        $sectionsByParent[$target][$section] = $section;
                    }
                }
            }

            foreach ($parents as $parent) {
                if ($parent === $id) {
                    continue;
                }
                $index[$parent][] = [
                    'sections' => array_values($sectionsByParent[$parent] ?? []),
                ];
            }
        }

        $this->issuedFollowUpIndex = $index;

        return $index;
    }

    private function lawIsInForce(mixed $status): bool
    {
        $normalized = mb_strtolower(trim((string) $status), 'UTF-8');
        if ($normalized === '') {
            return false;
        }
        foreach (['ยกเลิก', 'สิ้นผล', 'หมดอายุ', 'repeal', 'cancel', 'expired'] as $inactive) {
            if (str_contains($normalized, $inactive)) {
                return false;
            }
        }

        return in_array($normalized, ['มีผลบังคับใช้', 'ใช้งาน', 'active', 'published'], true)
            || str_contains($normalized, 'เผยแพร่');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    private function emails(array $record): array
    {
        $list = [];
        foreach (['primary', 'backup'] as $role) {
            $email = strtolower(trim((string) ($record[$role]['email'] ?? '')));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $list[$email] = $email;
            }
        }

        return array_values($list);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function nextMonthRange(): array
    {
        $start = $this->today()->addMonthNoOverflow()->startOfMonth();
        $end = $start->copy()->endOfMonth()->startOfDay();

        return [$start, $end];
    }

    private function nextMonthLabel(): string
    {
        [$start] = $this->nextMonthRange();

        return (self::MONTHS[$start->month] ?? '').' '.($start->year + 543);
    }

    private function daysUntil(?string $value): ?int
    {
        $due = $this->parseDate($value);
        if ($due === null) {
            return null;
        }

        $today = $this->today();
        $days = (int) $today->diff($due)->days;

        return $due->lessThan($today) ? -$days : $days;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        $iso = $this->dateOrNull($value);
        if ($iso === null) {
            return null;
        }

        $date = Carbon::createFromFormat('Y-m-d', $iso, $this->timezone());
        if ($date === false || $date->format('Y-m-d') !== $iso) {
            return null;
        }

        return $date->startOfDay();
    }

    private function dateOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return null;
        }

        return $text;
    }

    private function formatThai(string $iso): string
    {
        $date = $this->parseDate($iso);
        if ($date === null) {
            return $iso;
        }

        return sprintf('%02d/%02d/%d', $date->day, $date->month, $date->year + 543);
    }

    /**
     * @return array{name: string, email: string}
     */
    private function contact(mixed $input): array
    {
        $input = is_array($input) ? $input : [];

        return [
            'name' => trim((string) ($input['name'] ?? '')),
            'email' => strtolower(trim((string) ($input['email'] ?? ''))),
        ];
    }

    private function today(): Carbon
    {
        return Carbon::now($this->timezone())->startOfDay();
    }

    private function timezone(): string
    {
        $zone = config('oversight.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'Asia/Bangkok';
    }

    private function storedMailText(mixed $value, string $fallback, int $limit): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return mb_substr(str_replace("\r\n", "\n", trim($value)), 0, $limit);
    }

    private function cleanMailLine(mixed $value, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return mb_substr($text, 0, $limit);
    }

    private function cleanMailBody(mixed $value, int $limit): string
    {
        $text = str_replace("\r\n", "\n", trim((string) $value));

        return mb_substr($text, 0, $limit);
    }

    private function configMail(string $key, string $fallback): string
    {
        $value = config('oversight.'.$key);
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return str_replace("\r\n", "\n", trim($value));
    }

    /** @return array<int, int> */
    public function reminderDays(): array
    {
        $stored = $this->normalizeReminderDays($this->mailSettings()['reminder_days'] ?? null);

        return $stored !== [] ? $stored : $this->defaultReminderDays();
    }

    /**
     * @return array<string, mixed>
     */
    private function mailSettings(): array
    {
        $stored = $this->blob->read('settings', 'oversight-mail');

        return is_array($stored) ? $stored : [];
    }

    /** @return array<int, int> */
    private function defaultReminderDays(): array
    {
        $days = $this->normalizeReminderDays(config('oversight.reminder_days'));

        return $days !== [] ? $days : [30, 14, 7];
    }

    /** @return array<int, int> */
    private function normalizeReminderDays(mixed $days): array
    {
        if (! is_array($days)) {
            return [];
        }

        $clean = [];
        foreach ($days as $day) {
            if (! is_numeric($day)) {
                continue;
            }
            $value = (int) $day;
            if ($value < 0 || $value > 365) {
                continue;
            }
            $clean[$value] = $value;
        }

        $clean = array_values($clean);
        rsort($clean);

        return $clean;
    }

    /** @return array<int, string> */
    private function closedStatuses(): array
    {
        $statuses = config('oversight.closed_statuses');

        return is_array($statuses) ? array_values(array_filter($statuses, 'is_string')) : [];
    }

    private function publishedStatus(): string
    {
        $status = config('oversight.published_status');

        return is_string($status) && $status !== '' ? $status : 'ประกาศแล้ว';
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    private function statusOptions(): array
    {
        $options = config('oversight.statuses');
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            $key = trim((string) ($option['key'] ?? ''));
            $label = trim((string) ($option['label'] ?? ''));
            if ($key === '' || $label === '') {
                continue;
            }
            $normalized[] = ['key' => $key, 'label' => $label];
        }

        return $normalized;
    }
}
