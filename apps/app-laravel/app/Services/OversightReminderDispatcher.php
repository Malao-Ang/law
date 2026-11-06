<?php

namespace App\Services;

use App\Mail\OversightReminderMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class OversightReminderDispatcher
{
    public function __construct(private readonly OversightService $oversight) {}

    /**
     * Send every reminder that is due on a configured offset and has not been sent.
     *
     * @return array{sent: int, failed: array<int, array{document_id: string, title: string, message: string}>, delivered: array<int, array{document_id: string, title: string, emails: array<int, string>}>}
     */
    public function sendPending(): array
    {
        $sent = 0;
        $failed = [];
        $delivered = [];

        foreach ($this->oversight->pendingReminders() as $batch) {
            try {
                $this->deliver($batch);
            } catch (Throwable $e) {
                report($e);
                $failed[] = [
                    'document_id' => $batch['document_id'],
                    'title' => $batch['title'],
                    'message' => $e->getMessage(),
                ];

                continue;
            }

            foreach ($batch['hits'] as $hit) {
                $this->oversight->markSent($batch['document_id'], (string) $hit['sent_key']);
            }
            $delivered[] = [
                'document_id' => $batch['document_id'],
                'title' => $batch['title'],
                'emails' => $batch['emails'],
            ];
            $sent++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'delivered' => $delivered];
    }

    /**
     * Send one law now, including laws that are not on a reminder day.
     *
     * @return array{sent: int, failed: array<int, array{document_id: string, title: string, message: string}>, delivered: array<int, array{document_id: string, title: string, emails: array<int, string>}>, document_id: string, title: string, emails: array<int, string>}
     */
    public function sendDocument(string $documentId): array
    {
        $batch = $this->oversight->manualBatch($documentId);
        try {
            $this->deliver($batch);
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException('ส่งไม่สำเร็จ: '.$e->getMessage(), 422);
        }
        foreach ($batch['hits'] as $hit) {
            $this->oversight->markSent($batch['document_id'], (string) $hit['sent_key']);
        }

        $row = [
            'document_id' => $batch['document_id'],
            'title' => $batch['title'],
            'emails' => $batch['emails'],
        ];

        return [
            'sent' => 1,
            'failed' => [],
            'delivered' => [$row],
            'document_id' => $row['document_id'],
            'title' => $row['title'],
            'emails' => $row['emails'],
        ];
    }

    /**
     * @param  array{document_id: string, title: string, emails: array<int, string>, hits: array<int, array<string, mixed>>}  $batch
     */
    private function deliver(array $batch): void
    {
        $template = $this->oversight->mailTemplate();
        Mail::to($batch['emails'])->send(new OversightReminderMail(
            $batch['title'],
            $batch['hits'],
            url('/admin/oversight'),
            $template['subject'],
            $template['body'],
            $template['link_label'],
        ));
    }
}
