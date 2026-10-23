<?php

namespace Tests\Feature;

use App\Mail\OversightReminderMail;
use App\Services\ReviewStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OversightTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_counts_overdue_compliance_and_next_month(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');

        $overdue = 'ov_late_'.uniqid();
        $onTime = 'ov_ok_'.uniqid();
        $late = 'ov_miss_'.uniqid();
        $nextPublish = 'ov_next_'.uniqid();
        $nextStep = 'ov_step_'.uniqid();
        $cancelled = 'ov_cancel_'.uniqid();
        $later = 'ov_later_'.uniqid();
        $plain = 'ov_plain_'.uniqid();

        $this->seedDocument($overdue, 'เกินกำหนดออกประกาศ');
        $this->seedDocument($onTime, 'ประกาศตรงเวลา');
        $this->seedDocument($late, 'ประกาศช้า');
        $this->seedDocument($nextPublish, 'ถึงรอบเดือนหน้า');
        $this->seedDocument($nextStep, 'ขั้นตอนเดือนหน้า');
        $this->seedDocument($cancelled, 'ยกเลิกแล้ว');
        $this->seedDocument($later, 'ยังไม่ถึงเดือนหน้า');
        $this->seedDocument($plain, 'ยังไม่ติดตาม');

        $this->track($overdue, 'อยู่ระหว่างการยกร่าง', '2026-10-01');
        $this->track($onTime, 'ประกาศแล้ว', '2026-10-15', [
            'published' => ['completed_on' => '2026-10-01'],
        ]);
        $this->track($late, 'ประกาศแล้ว', '2026-10-01', [
            'published' => ['completed_on' => '2026-10-20'],
        ]);
        $this->track($nextPublish, 'เสนอเพื่อพิจารณา', '2026-11-15');
        $this->track($nextStep, 'อยู่ระหว่างการยกร่าง', '2026-12-01', [
            'drafting' => ['due_date' => '2026-11-10'],
        ]);
        $this->track($cancelled, 'ยกเลิก', '2026-09-01');
        $this->track($later, 'อนุมัติแล้ว/รอประกาศ', '2026-12-01');

        $res = $this->getJson('/api/oversight/summary');
        $res->assertOk();
        $res->assertJsonPath('totals.tracked', 7);
        $res->assertJsonPath('totals.overdue_publication', 1);
        $res->assertJsonPath('totals.on_time', 1);
        $res->assertJsonPath('totals.late', 1);
        $res->assertJsonPath('next_month_label', 'พฤศจิกายน 2569');
        $this->assertEquals(50, $res->json('totals.compliance_rate'));

        $nextIds = collect($res->json('next_month'))->pluck('document_id')->all();
        $this->assertEqualsCanonicalizing([$nextPublish, $nextStep], $nextIds);

        $stepRow = collect($res->json('next_month'))->firstWhere('document_id', $nextStep);
        $this->assertSame('อยู่ระหว่างการยกร่าง', $stepRow['next_action_label']);
        $this->assertSame('2026-11-10', $stepRow['next_action_date']);

        $byStatus = collect($res->json('by_status'))->keyBy('key');
        $this->assertSame(2, $byStatus['drafting']['count']);
        $this->assertSame(1, $byStatus['submitted']['count']);
        $this->assertSame(1, $byStatus['approved']['count']);
        $this->assertSame(2, $byStatus['published']['count']);
        $this->assertSame(1, $byStatus['cancelled']['count']);

        $plainRow = collect($res->json('items'))->firstWhere('document_id', $plain);
        $this->assertFalse($plainRow['tracked']);
        $this->assertFalse($plainRow['overdue']);
    }

    public function test_update_stores_status_completion_and_both_contacts(): void
    {
        $id = 'ov_edit_'.uniqid();
        $this->seedDocument($id, 'ฉบับแก้ไขสถานะ');

        $res = $this->putJson("/api/oversight/{$id}", [
            'status' => 'เสนอเพื่อพิจารณา',
            'publish_due_date' => '2026-12-31',
            'steps' => $this->steps([
                'drafting' => ['due_date' => '2026-11-01', 'completed_on' => '2026-10-28'],
            ]),
            'primary' => ['name' => 'สมชาย ใจดี', 'email' => 'Owner@Example.com'],
            'backup' => ['name' => 'สุดา แสงทอง', 'email' => 'backup@example.com'],
        ]);

        $res->assertOk();
        $res->assertJsonPath('status', 'เสนอเพื่อพิจารณา');
        $res->assertJsonPath('publish_due_date', '2026-12-31');
        $res->assertJsonPath('primary.email', 'owner@example.com');
        $res->assertJsonPath('backup.name', 'สุดา แสงทอง');
        $res->assertJsonPath('steps.0.completed_on', '2026-10-28');
        $res->assertJsonPath('steps.0.due_date', '2026-11-01');
    }

    public function test_update_rejects_unknown_status_and_missing_document(): void
    {
        $id = 'ov_bad_'.uniqid();
        $this->seedDocument($id);

        $this->putJson("/api/oversight/{$id}", [
            'status' => 'ไม่มีสถานะนี้',
            'publish_due_date' => null,
            'steps' => $this->steps(),
            'primary' => ['name' => '', 'email' => 'not-an-email'],
            'backup' => ['name' => '', 'email' => null],
        ])->assertStatus(422);

        $this->putJson('/api/oversight/missing_'.uniqid(), [
            'status' => 'อยู่ระหว่างการยกร่าง',
            'publish_due_date' => null,
            'steps' => $this->steps(),
            'primary' => ['name' => '', 'email' => null],
            'backup' => ['name' => '', 'email' => null],
        ])->assertNotFound();
    }

    #[DataProvider('reminderOffsets')]
    public function test_reminder_sends_once_to_primary_and_backup(int $days): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $due = Carbon::now('Asia/Bangkok')->startOfDay()->addDays($days)->toDateString();
        $id = 'ov_mail_'.$days.'_'.uniqid();
        $this->seedDocument($id, 'ฉบับแจ้งเตือน');
        $this->track($id, 'อยู่ระหว่างการยกร่าง', $due, [], [
            'primary' => ['name' => 'สมชาย', 'email' => 'owner@example.com'],
            'backup' => ['name' => 'สุดา', 'email' => 'backup@example.com'],
        ]);

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, function (OversightReminderMail $mail) use ($days): bool {
            return $mail->hasTo('owner@example.com')
                && $mail->hasTo('backup@example.com')
                && $mail->hits[0]['days_left'] === $days;
        });

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, 1);
    }

    public function test_status_update_does_not_resend_the_same_reminder(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $due = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(14)->toDateString();
        $id = 'ov_keep_'.uniqid();
        $this->seedDocument($id);
        $this->track($id, 'อยู่ระหว่างการยกร่าง', $due);

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        $this->track($id, 'เสนอเพื่อพิจารณา', $due);
        $this->artisan('oversight:send-reminders')->assertSuccessful();

        Mail::assertSent(OversightReminderMail::class, 1);
    }

    public function test_reminder_uses_open_step_deadline_and_skips_closed_or_empty_contacts(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();
        $stepDue = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(7)->toDateString();

        $stepId = 'ov_step_mail_'.uniqid();
        $this->seedDocument($stepId, 'เตือนตามขั้นตอน');
        $this->track($stepId, 'อยู่ระหว่างการยกร่าง', '2027-06-01', [
            'drafting' => ['due_date' => $stepDue],
        ], [
            'primary' => ['name' => '', 'email' => null],
            'backup' => ['name' => 'ผู้ประสาน', 'email' => 'backup-only@example.com'],
        ]);

        $closedId = 'ov_closed_mail_'.uniqid();
        $this->seedDocument($closedId);
        $this->track($closedId, 'ประกาศแล้ว', $stepDue, [
            'published' => ['completed_on' => '2026-10-01'],
        ]);

        $silentId = 'ov_silent_'.uniqid();
        $this->seedDocument($silentId);
        $this->track($silentId, 'อยู่ระหว่างการยกร่าง', $stepDue, [], [
            'primary' => ['name' => '', 'email' => null],
            'backup' => ['name' => '', 'email' => null],
        ]);

        $this->artisan('oversight:send-reminders --dry-run')->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, function (OversightReminderMail $mail): bool {
            return $mail->hasTo('backup-only@example.com')
                && $mail->lawTitle === 'เตือนตามขั้นตอน'
                && count($mail->hits) === 1
                && $mail->hits[0]['days_left'] === 7;
        });
        Mail::assertSent(OversightReminderMail::class, 1);
    }

    /** @return array<string, array{0: int}> */
    public static function reminderOffsets(): array
    {
        return [
            '30 days' => [30],
            '14 days' => [14],
            '7 days' => [7],
        ];
    }

    private function seedDocument(string $id, string $title = 'กฎหมายทดสอบ'): void
    {
        $store = app(ReviewStore::class);
        $store->setStatus($id, ['status' => 'done', 'source_file' => $id.'.docx']);
        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => $id.'.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'law_meta' => ['title' => $title, 'law_type' => 'ประกาศ'],
            'pages' => [],
        ]);
    }

    /**
     * @param  array<string, array{due_date?: string|null, completed_on?: string|null}>  $stepDates
     * @param  array<string, array{name?: string, email?: string|null}>  $contacts
     */
    private function track(
        string $id,
        string $status,
        ?string $publishDue,
        array $stepDates = [],
        array $contacts = [],
    ): void {
        $this->putJson("/api/oversight/{$id}", [
            'status' => $status,
            'publish_due_date' => $publishDue,
            'steps' => $this->steps($stepDates),
            'primary' => $contacts['primary'] ?? ['name' => 'สมชาย', 'email' => 'owner@example.com'],
            'backup' => $contacts['backup'] ?? ['name' => 'สุดา', 'email' => 'backup@example.com'],
        ])->assertOk();
    }

    /**
     * @param  array<string, array{due_date?: string|null, completed_on?: string|null}>  $dates
     * @return array<int, array{key: string, due_date: string|null, completed_on: string|null}>
     */
    private function steps(array $dates = []): array
    {
        $steps = [];
        foreach (['drafting', 'submitted', 'approved', 'published', 'cancelled'] as $key) {
            $steps[] = [
                'key' => $key,
                'due_date' => $dates[$key]['due_date'] ?? null,
                'completed_on' => $dates[$key]['completed_on'] ?? null,
            ];
        }

        return $steps;
    }
}
