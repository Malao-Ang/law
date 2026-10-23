<?php

namespace App\Console\Commands;

use App\Mail\OversightReminderMail;
use App\Services\OversightService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOversightRemindersCommand extends Command
{
    protected $signature = 'oversight:send-reminders {--dry-run : List reminders without sending email}';

    protected $description = 'Email primary and backup contacts 30, 14, and 7 days before an oversight deadline.';

    public function handle(OversightService $oversight): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        foreach ($oversight->pendingReminders() as $batch) {
            $labels = collect($batch['hits'])->map(
                static fn (array $hit): string => $hit['label'].' '.$hit['display_date'].' ('.$hit['days_left'].' วัน)',
            )->implode('; ');
            $this->line($batch['document_id'].' -> '.implode(', ', $batch['emails']).' | '.$labels);

            if ($dryRun) {
                $sent++;

                continue;
            }

            try {
                Mail::to($batch['emails'])->send(new OversightReminderMail(
                    $batch['title'],
                    $batch['hits'],
                    url('/admin/oversight'),
                ));
            } catch (Throwable $e) {
                report($e);
                $this->error($batch['document_id'].' ส่งไม่สำเร็จ: '.$e->getMessage());

                continue;
            }

            foreach ($batch['hits'] as $hit) {
                $oversight->markSent($batch['document_id'], $hit['sent_key']);
            }
            $sent++;
        }

        $this->info(($dryRun ? 'จะแจ้งเตือน ' : 'ส่งแจ้งเตือน ').$sent.' ฉบับ');

        return self::SUCCESS;
    }
}
