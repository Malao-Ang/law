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

    public string $bodyHtml;

    /**
     * @param  array<int, array{label: string, date: string, display_date: string, days_left: int, sent_key: string}>  $hits
     */
    public function __construct(
        public string $lawTitle,
        public array $hits,
        public string $oversightUrl,
        public string $subjectTemplate = '',
        public string $bodyTemplate = '',
        public string $linkLabel = '',
    ) {
        if ($this->subjectTemplate === '') {
            $this->subjectTemplate = $this->configured('mail_subject', 'แจ้งเตือนกำหนดดำเนินการกฎหมาย — เหลือ {days} วัน');
        }
        if ($this->bodyTemplate === '') {
            $this->bodyTemplate = $this->configured(
                'mail_body',
                "เรียนผู้รับผิดชอบ\n\nกฎหมาย {title} ใกล้ถึงกำหนดดำเนินการ\n\n{deadlines}\n\nกรุณาเข้าตรวจสอบสถานะและวันแล้วเสร็จได้ที่ {link}",
            );
        }
        if ($this->linkLabel === '') {
            $this->linkLabel = $this->configured('mail_link_label', 'ระบบติดตามการกำกับดูแล');
        }

        $this->bodyHtml = $this->renderBody();
    }

    public function envelope(): Envelope
    {
        $subject = str_replace(
            ['{days}', '{title}'],
            [$this->daysLabel(), $this->lawTitle],
            $this->subjectTemplate,
        );
        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);
        if ($subject === '') {
            $subject = 'แจ้งเตือนกำหนดดำเนินการกฎหมาย';
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.oversight-reminder',
        );
    }

    private function renderBody(): string
    {
        $deadlines = '<ul>';
        foreach ($this->hits as $hit) {
            $deadlines .= '<li>'
                .e((string) ($hit['label'] ?? ''))
                .' วันที่ '
                .e((string) ($hit['display_date'] ?? ''))
                .' ('
                .e($this->daysPhrase((int) ($hit['days_left'] ?? 0)))
                .')</li>';
        }
        $deadlines .= '</ul>';

        $replacements = [
            '{deadlines}' => $deadlines,
            '{link}' => '<a href="'.e($this->oversightUrl).'">'.e($this->linkLabel).'</a>',
            '{title}' => '<strong>'.e($this->lawTitle).'</strong>',
            '{days}' => e($this->daysLabel()),
        ];

        $parts = preg_split('/(\{deadlines\}|\{link\}|\{title\}|\{days\})/', $this->bodyTemplate, -1, PREG_SPLIT_DELIM_CAPTURE);
        $html = '';
        foreach ($parts ?: [] as $part) {
            $html .= $replacements[$part] ?? nl2br(e($part), false);
        }

        return $html;
    }

    private function daysLabel(): string
    {
        return collect($this->hits)->pluck('days_left')->unique()->sort()->implode('/');
    }

    private function daysPhrase(int $days): string
    {
        if ($days < 0) {
            return 'เกิน '.abs($days).' วัน';
        }
        if ($days === 0) {
            return 'ครบกำหนดวันนี้';
        }

        return 'เหลือ '.$days.' วัน';
    }

    private function configured(string $key, string $fallback): string
    {
        $value = config('oversight.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }
}
