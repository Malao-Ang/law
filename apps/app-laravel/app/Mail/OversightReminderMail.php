<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OversightReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{label: string, date: string, display_date: string, days_left: int, sent_key: string}>  $hits
     */
    public function __construct(
        public string $lawTitle,
        public array $hits,
        public string $oversightUrl,
    ) {}

    public function envelope(): Envelope
    {
        $days = collect($this->hits)->pluck('days_left')->unique()->sort()->implode('/');

        return new Envelope(
            subject: "แจ้งเตือนกำหนดดำเนินการกฎหมาย — เหลือ {$days} วัน",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.oversight-reminder',
        );
    }
}
