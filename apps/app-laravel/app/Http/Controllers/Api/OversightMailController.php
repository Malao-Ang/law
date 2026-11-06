<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendOversightMailRequest;
use App\Http\Requests\UpdateOversightMailRequest;
use App\Services\Audit\AuditLogger;
use App\Services\OversightReminderDispatcher;
use App\Services\OversightService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class OversightMailController extends Controller
{
    public function __construct(
        private readonly OversightService $oversight,
        private readonly OversightReminderDispatcher $mailer,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $items = $this->oversight->reminderCatalog();

        return response()->json([
            'template' => $this->oversight->mailTemplate(),
            'defaults' => $this->oversight->defaultMailTemplate(),
            'placeholders' => $this->placeholders(),
            'reminder_days' => $this->reminderDays(),
            'pending_count' => count(array_filter($items, static fn (array $item): bool => $item['due_now'])),
            'items' => $items,
        ]);
    }

    public function update(UpdateOversightMailRequest $request): JsonResponse
    {
        $template = $this->oversight->saveMailTemplate($request->validated());
        $this->audit->record(
            'update',
            'oversight',
            'mail-template',
            'แก้ไขข้อความอีเมลแจ้งเตือน',
            ['หัวข้อ: '.$template['subject']],
            'ข้อความอีเมลแจ้งเตือน',
        );

        return response()->json(['template' => $template]);
    }

    public function send(SendOversightMailRequest $request): JsonResponse
    {
        $documentId = $request->validated('document_id');
        $documentId = is_string($documentId) ? $documentId : '';

        try {
            if ($documentId !== '') {
                $result = $this->mailer->sendDocument($documentId);
                $this->audit->record(
                    'update',
                    'oversight',
                    $documentId,
                    'ส่งอีเมลแจ้งเตือนด้วยตนเอง',
                    ['ผู้รับ: '.implode(', ', $result['emails'])],
                    $result['title'],
                    $documentId,
                );

                return response()->json($result);
            }

            $result = $this->mailer->sendPending();
            $this->audit->record(
                'update',
                'oversight',
                'mail-send',
                'ส่งอีเมลแจ้งเตือนตามรอบด้วยตนเอง',
                ['ส่งสำเร็จ '.$result['sent'].' ฉบับ'],
                'อีเมลแจ้งเตือน',
            );

            return response()->json($result);
        } catch (RuntimeException $e) {
            $status = $e->getCode();
            if (! in_array($status, [404, 422], true)) {
                throw $e;
            }

            return response()->json(['message' => $e->getMessage()], $status);
        }
    }

    /**
     * @return array<int, array{token: string, label: string, fields: string}>
     */
    private function placeholders(): array
    {
        return [
            ['token' => '{title}', 'label' => 'ชื่อกฎหมาย', 'fields' => 'หัวข้อและข้อความ'],
            ['token' => '{days}', 'label' => 'จำนวนวันที่เหลือ', 'fields' => 'หัวข้อและข้อความ'],
            ['token' => '{deadlines}', 'label' => 'รายการกำหนด', 'fields' => 'ข้อความ'],
            ['token' => '{link}', 'label' => 'ลิงก์เข้าระบบ', 'fields' => 'ข้อความ'],
        ];
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
}
