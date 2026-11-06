<?php

namespace App\Services;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Support\Carbon;
use RuntimeException;

final class OversightService
{
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
     * Documents with a deadline landing on a reminder offset that has not been sent.
     *
     * @return array<int, array{document_id: string, title: string, emails: array<int, string>, hits: array<int, array<string, mixed>>}>
     */
    public function pendingReminders(): array
    {
        $offsets = $this->reminderDays();
        $batches = [];

        foreach ($this->records() as $record) {
            if (! $record['tracked'] || $record['closed']) {
                continue;
            }

            $emails = $this->emails($record);
            if ($emails === []) {
                continue;
            }

            $sent = is_array($record['reminders_sent'] ?? null) ? $record['reminders_sent'] : [];
            $hits = [];
            foreach ($this->openDeadlines($record) as $deadline) {
                $days = $this->daysUntil($deadline['date']);
                if ($days === null || ! in_array($days, $offsets, true)) {
                    continue;
                }

                $sentKey = $deadline['kind'].':'.$deadline['key'].':'.$deadline['date'].':'.$days;
                if (isset($sent[$sentKey])) {
                    continue;
                }

                $hits[] = [
                    'label' => $deadline['label'],
                    'date' => $deadline['date'],
                    'display_date' => $this->formatThai($deadline['date']),
                    'days_left' => $days,
                    'sent_key' => $sentKey,
                ];
            }

            if ($hits === []) {
                continue;
            }

            $batches[] = [
                'document_id' => $record['document_id'],
                'title' => $record['title'],
                'emails' => $emails,
                'hits' => $hits,
            ];
        }

        return $batches;
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
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function publicItem(array $record): array
    {
        unset($record['reminders_sent']);

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

        return $deadlines;
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

    /** @return array<int, int> */
    private function reminderDays(): array
    {
        $days = config('oversight.reminder_days');
        if (! is_array($days) || $days === []) {
            return [30, 14, 7];
        }

        return array_values(array_map(static fn (mixed $day): int => (int) $day, $days));
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
