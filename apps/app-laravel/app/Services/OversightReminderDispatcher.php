<?php

namespace App\Services;

use App\Mail\OversightReminderMail;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class OversightReminderDispatcher
{
    public function __construct(private readonly OversightService $oversight) {}

    /**
     * Send every reminder that is due on a configured offset and has not been sent.
     *
     * @return array{sent: int, failed: array<int, array{document_id: string, title: string, message: string}>, delivered: array<int, array{document_id: string, title: string, emails: array<int, string>, channel: string}>}
     */
    public function sendPending(?\Illuminate\Http\Request $request = null): array
    {
        $sent = 0;
        $failed = [];
        $delivered = [];

        foreach ($this->oversight->pendingReminders() as $batch) {
            if ($request !== null) {
                $access = app(\App\Services\Auth\DocumentAccess::class);
                $document = $access->readDocument($batch['document_id']);
                if ($document === null || ! $access->adminScope($request, $document) || ! $access->allows($request, 'edit', $document)) {
                    continue;
                }
            }
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
                'channel' => (string) ($batch['channel'] ?? OversightService::CHANNEL_DEADLINE),
            ];
            $sent++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'delivered' => $delivered];
    }

    /**
     * Send one law now, including laws that are not on a reminder day.
     * Sends one mail per channel that has open deadlines.
     *
     * @return array{sent: int, failed: array<int, array{document_id: string, title: string, message: string}>, delivered: array<int, array{document_id: string, title: string, emails: array<int, string>, channel: string}>, document_id: string, title: string, emails: array<int, string>}
     */
    public function sendDocument(string $documentId): array
    {
        $batches = $this->oversight->manualBatches($documentId);
        $delivered = [];
        $emails = [];
        $title = '';

        foreach ($batches as $batch) {
            try {
                $this->deliver($batch);
            } catch (Throwable $e) {
                report($e);
                throw new RuntimeException('ส่งไม่สำเร็จ: '.$e->getMessage(), 422);
            }
            foreach ($batch['hits'] as $hit) {
                $this->oversight->markSent($batch['document_id'], (string) $hit['sent_key']);
            }
            $delivered[] = [
                'document_id' => $batch['document_id'],
                'title' => $batch['title'],
                'emails' => $batch['emails'],
                'channel' => (string) ($batch['channel'] ?? OversightService::CHANNEL_DEADLINE),
            ];
            $title = $batch['title'];
            foreach ($batch['emails'] as $email) {
                $emails[$email] = $email;
            }
        }

        return [
            'sent' => count($delivered),
            'failed' => [],
            'delivered' => $delivered,
            'document_id' => $documentId,
            'title' => $title,
            'emails' => array_values($emails),
        ];
    }

    /**
     * @param  array{document_id: string, title: string, emails: array<int, string>, hits: array<int, array<string, mixed>>, channel?: string}  $batch
     */
    private function deliver(array $batch): void
    {
        $channel = (string) ($batch['channel'] ?? OversightService::CHANNEL_DEADLINE);
        $template = $this->oversight->mailTemplate($channel);
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
