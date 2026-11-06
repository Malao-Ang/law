<?php

namespace App\Console\Commands;

use App\Services\OversightReminderDispatcher;
use App\Services\OversightService;
use Illuminate\Console\Command;

class SendOversightRemindersCommand extends Command
{
    protected $signature = 'oversight:send-reminders {--dry-run : List reminders without sending email}';

    protected $description = 'Email primary and backup contacts 30, 14, and 7 days before an oversight deadline.';

    public function handle(OversightService $oversight, OversightReminderDispatcher $mailer): int
    {
        if ($this->option('dry-run')) {
            $count = 0;
            foreach ($oversight->pendingReminders() as $batch) {
                $labels = collect($batch['hits'])->map(
                    static fn (array $hit): string => $hit['label'].' '.$hit['display_date'].' ('.$hit['days_left'].' วัน)',
                )->implode('; ');
                $this->line($batch['document_id'].' -> '.implode(', ', $batch['emails']).' | '.$labels);
                $count++;
            }
            $this->info('จะแจ้งเตือน '.$count.' ฉบับ');

            return self::SUCCESS;
        }

        $result = $mailer->sendPending();
        foreach ($result['delivered'] as $row) {
            $this->line($row['document_id'].' -> '.implode(', ', $row['emails']));
        }
        foreach ($result['failed'] as $fail) {
            $this->error($fail['document_id'].' ส่งไม่สำเร็จ: '.$fail['message']);
        }
        $this->info('ส่งแจ้งเตือน '.$result['sent'].' ฉบับ');

        return self::SUCCESS;
    }
}
