<?php

namespace Tests\Feature;

use App\Mail\OversightReminderMail;
use App\Services\Auth\CurrentUser;
use App\Services\Auth\MongoUserStore;
use App\Services\ReviewStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OversightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(MongoUserStore::class)->upsertFromLogin('ov_admin', ['name' => 'ผู้ทดสอบกำกับดูแล'], null, true);
        $this->withHeaders(['Referer' => 'http://localhost:8500/admin', 'Accept' => 'application/json'])
            ->withSession([
                CurrentUser::SESSION_KEY => 'ov_admin',
                CurrentUser::ACTING_MODE_KEY => 'super_admin',
                CurrentUser::LOGIN_AT_KEY => time(),
            ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_shows_due_date_from_proclamation_relation(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');

        $overdue = 'ov_late_'.uniqid();
        $next = 'ov_next_'.uniqid();
        $later = 'ov_later_'.uniqid();
        $plain = 'ov_plain_'.uniqid();
        $cancelled = 'ov_cancel_'.uniqid();

        $this->seedDocument($overdue, 'เกินกำหนดออกประกาศ');
        $this->seedDocument($next, 'ถึงรอบเดือนหน้า');
        $this->seedDocument($later, 'ยังไม่ถึงเดือนหน้า');
        $this->seedDocument($plain, 'ยังไม่ติดตาม');
        $this->seedDocument($cancelled, 'ยกเลิกแล้ว');

        $this->setProclamation($overdue, '2026-10-01');
        $this->setProclamation($next, '2026-11-15');
        $this->setProclamation($later, '2026-12-01');
        $this->setProclamation($cancelled, '2026-09-01');
        $this->track($overdue);
        $this->track($next);
        $this->track($later);
        $this->seedLegacyStatus($cancelled, 'ยกเลิก');

        $res = $this->getJson('/api/oversight/summary');
        $res->assertOk();
        $res->assertJsonPath('totals.tracked', 4);
        $res->assertJsonPath('totals.overdue_publication', 1);
        $res->assertJsonPath('next_month_label', 'พฤศจิกายน 2569');

        $nextIds = collect($res->json('next_month'))->pluck('document_id')->all();
        $this->assertEqualsCanonicalizing([$next], $nextIds);

        $overdueRow = collect($res->json('items'))->firstWhere('document_id', $overdue);
        $this->assertTrue($overdueRow['overdue']);
        $this->assertSame('2026-10-01', $overdueRow['due_date']);
        $this->assertSame('ต้องออกประกาศภายใน', $overdueRow['due_label']);
        $this->assertNotEmpty($overdueRow['due_dates']);

        $nextRow = collect($res->json('next_month'))->firstWhere('document_id', $next);
        $this->assertSame('2026-11-15', $nextRow['next_action_date']);
        $this->assertStringContainsString('ออกประกาศฉบับใหม่', (string) $nextRow['next_action_label']);

        $cancelledRow = collect($res->json('items'))->firstWhere('document_id', $cancelled);
        $this->assertTrue($cancelledRow['closed']);
        $this->assertFalse($cancelledRow['overdue']);

        $plainRow = collect($res->json('items'))->firstWhere('document_id', $plain);
        $this->assertFalse($plainRow['tracked']);
        $this->assertNull($plainRow['due_date']);
        $this->assertFalse($plainRow['overdue']);
    }

    public function test_update_stores_contacts_without_status_or_steps(): void
    {
        $id = 'ov_edit_'.uniqid();
        $this->seedDocument($id, 'ฉบับแก้ไขผู้รับ');
        $this->seedLegacyStatus($id, 'อยู่ระหว่างการยกร่าง', '2026-12-31', [
            'drafting' => ['due_date' => '2026-11-01', 'completed_on' => '2026-10-28'],
        ]);

        $res = $this->putJson("/api/oversight/{$id}", [
            'primary' => ['name' => 'สมชาย ใจดี', 'email' => 'Owner@Example.com'],
            'backup' => ['name' => 'สุดา แสงทอง', 'email' => 'backup@example.com'],
        ]);

        $res->assertOk();
        $res->assertJsonPath('primary.email', 'owner@example.com');
        $res->assertJsonPath('backup.name', 'สุดา แสงทอง');
        $res->assertJsonPath('status', 'อยู่ระหว่างการยกร่าง');
        $res->assertJsonPath('publish_due_date', '2026-12-31');
        $res->assertJsonPath('steps.0.completed_on', '2026-10-28');
        $res->assertJsonPath('steps.0.due_date', '2026-11-01');
    }

    public function test_update_rejects_invalid_email_and_missing_document(): void
    {
        $id = 'ov_bad_'.uniqid();
        $this->seedDocument($id);

        $this->putJson("/api/oversight/{$id}", [
            'primary' => ['name' => '', 'email' => 'not-an-email'],
            'backup' => ['name' => '', 'email' => null],
        ])->assertStatus(422);

        $this->putJson('/api/oversight/missing_'.uniqid(), [
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
        $this->track($id);
        $this->setProclamation($id, $due);

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, function (OversightReminderMail $mail) use ($days): bool {
            return $mail->hasTo('owner@example.com')
                && $mail->hasTo('backup@example.com')
                && $mail->hits[0]['days_left'] === $days
                && str_contains($mail->envelope()->subject, 'ต้องออกประกาศตามกฎหมาย')
                && str_contains($mail->bodyHtml, 'ใกล้ถึงกำหนดต้องออกประกาศฉบับใหม่')
                && str_contains($mail->bodyHtml, 'ระบบติดตามการกำกับดูแล');
        });

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, 1);
    }

    public function test_contact_update_does_not_resend_the_same_reminder(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $due = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(14)->toDateString();
        $id = 'ov_keep_'.uniqid();
        $this->seedDocument($id);
        $this->track($id);
        $this->setProclamation($id, $due);

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        $this->track($id, [
            'primary' => ['name' => 'สมชายใหม่', 'email' => 'owner@example.com'],
            'backup' => ['name' => 'สุดา', 'email' => 'backup@example.com'],
        ]);
        $this->artisan('oversight:send-reminders')->assertSuccessful();

        Mail::assertSent(OversightReminderMail::class, 1);
    }

    public function test_reminder_uses_proclamation_deadline_and_skips_closed_or_empty_contacts(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();
        $due = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(7)->toDateString();

        $openId = 'ov_open_mail_'.uniqid();
        $this->seedDocument($openId, 'เตือนตามประกาศ');
        $this->track($openId, [
            'primary' => ['name' => '', 'email' => null],
            'backup' => ['name' => 'ผู้ประสาน', 'email' => 'backup-only@example.com'],
        ]);
        $this->setProclamation($openId, $due);

        $closedId = 'ov_closed_mail_'.uniqid();
        $this->seedDocument($closedId);
        $this->seedLegacyStatus($closedId, 'ประกาศแล้ว', $due);
        $this->setProclamation($closedId, $due);

        $silentId = 'ov_silent_'.uniqid();
        $this->seedDocument($silentId);
        $this->track($silentId, [
            'primary' => ['name' => '', 'email' => null],
            'backup' => ['name' => '', 'email' => null],
        ]);
        $this->setProclamation($silentId, $due);

        $this->artisan('oversight:send-reminders --dry-run')->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, function (OversightReminderMail $mail): bool {
            return $mail->hasTo('backup-only@example.com')
                && $mail->lawTitle === 'เตือนตามประกาศ'
                && count($mail->hits) === 1
                && $mail->hits[0]['days_left'] === 7;
        });
        Mail::assertSent(OversightReminderMail::class, 1);
    }

    public function test_admin_can_edit_the_mail_text_and_trigger_sending(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $due = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(7)->toDateString();
        $id = 'ov_ui_mail_'.uniqid();
        $this->seedDocument($id, 'ฉบับจากหน้าแอดมิน');
        $this->track($id);
        $this->setProclamation($id, $due);

        $this->getJson('/api/oversight/mail')
            ->assertOk()
            ->assertJsonPath('templates.deadline.link_label', 'ระบบติดตามการกำกับดูแล')
            ->assertJsonPath('templates.proclamation.link_label', 'ระบบติดตามการกำกับดูแล')
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('items.0.due_now', true)
            ->assertJsonPath('items.0.document_id', $id)
            ->assertJsonPath('items.0.channel', 'proclamation');

        $this->putJson('/api/oversight/mail', [
            'deadline' => [
                'subject' => '',
                'body' => 'มีข้อความ',
                'link_label' => 'เปิดระบบ',
            ],
            'proclamation' => [
                'subject' => 'ออกประกาศ {title}',
                'body' => '{title} {deadlines} {link}',
                'link_label' => 'เปิดระบบ',
            ],
        ])->assertStatus(422);

        $this->putJson('/api/oversight/mail', [
            'deadline' => [
                'subject' => 'เตือนเอง {title}',
                'body' => "<script>alert(1)</script>\n\n{title}\n\n{deadlines}\n\nดูที่ {link}",
                'link_label' => 'เปิดระบบ',
            ],
            'proclamation' => [
                'subject' => 'ออกประกาศเอง {title}',
                'body' => "<script>alert(1)</script>\n\n{title}\n\n{deadlines}\n\nดูที่ {link}",
                'link_label' => 'เปิดระบบ',
            ],
        ])->assertOk()->assertJsonPath('templates.proclamation.subject', 'ออกประกาศเอง {title}');

        $this->postJson('/api/oversight/mail/send')
            ->assertOk()
            ->assertJsonPath('sent', 1);

        Mail::assertSent(OversightReminderMail::class, function (OversightReminderMail $mail): bool {
            return $mail->hasTo('owner@example.com')
                && $mail->envelope()->subject === 'ออกประกาศเอง ฉบับจากหน้าแอดมิน'
                && str_contains($mail->bodyHtml, '&lt;script&gt;alert(1)&lt;/script&gt;')
                && ! str_contains($mail->bodyHtml, '<script>')
                && str_contains($mail->bodyHtml, 'เปิดระบบ');
        });

        $this->postJson('/api/oversight/mail/send')->assertOk()->assertJsonPath('sent', 0);

        app(\App\Services\Auth\MongoUserStore::class)->upsertFromLogin('audit_admin', ['name' => 'ผู้ตรวจสอบ'], null, true);
        $this->withHeaders(['Referer' => 'http://localhost:8500/admin'])->withSession(['auth_username' => 'audit_admin']);
        $summaries = collect($this->getJson('/api/audit-logs')->assertOk()->json('items'))->pluck('summary');
        $this->assertTrue($summaries->contains('แก้ไขข้อความอีเมลแจ้งเตือน'));
        $this->assertTrue($summaries->contains('ส่งอีเมลแจ้งเตือนตามรอบด้วยตนเอง'));
    }

    public function test_admin_can_change_the_reminder_days(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $customDue = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(10)->toDateString();
        $oldOffset = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(14)->toDateString();
        $customId = 'ov_days_custom_'.uniqid();
        $oldId = 'ov_days_old_'.uniqid();
        $this->seedDocument($customId, 'ฉบับรอบใหม่');
        $this->seedDocument($oldId, 'ฉบับรอบเดิม');
        $this->track($customId);
        $this->track($oldId);
        $this->setProclamation($customId, $customDue);
        $this->setProclamation($oldId, $oldOffset);

        $this->putJson('/api/oversight/mail', [
            'deadline' => [
                'subject' => 'แจ้งเตือน {days}',
                'body' => '{title} {deadlines}',
                'link_label' => 'เปิดระบบ',
            ],
            'proclamation' => [
                'subject' => 'ออกประกาศ {days}',
                'body' => '{title} {deadlines}',
                'link_label' => 'เปิดระบบ',
            ],
            'reminder_days' => [3, 10],
        ])->assertOk()
            ->assertJsonPath('templates.deadline.reminder_days', [10, 3])
            ->assertJsonPath('reminder_days', [10, 3]);

        $this->getJson('/api/oversight/summary')->assertJsonPath('reminder_days', [10, 3]);
        $this->getJson('/api/oversight/mail')->assertJsonPath('pending_count', 1);

        $this->putJson('/api/oversight/mail', [
            'deadline' => [
                'subject' => 'แจ้งเตือน {days}',
                'body' => '{title} {deadlines}',
                'link_label' => 'เปิดระบบ',
            ],
            'proclamation' => [
                'subject' => 'ออกประกาศ {days}',
                'body' => '{title} {deadlines}',
                'link_label' => 'เปิดระบบ',
            ],
            'reminder_days' => [],
        ])->assertStatus(422);

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, function (OversightReminderMail $mail): bool {
            return $mail->lawTitle === 'ฉบับรอบใหม่' && $mail->hits[0]['days_left'] === 10;
        });
        Mail::assertSent(OversightReminderMail::class, 1);
    }

    public function test_reminder_includes_a_new_proclamation_deadline_from_a_relation(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $due = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(14)->toDateString();
        $id = 'ov_proclaim_'.uniqid();
        $this->seedDocument($id, 'ฉบับที่ต้องออกประกาศใหม่');
        $this->track($id);
        $this->putJson("/api/documents/{$id}/document-review", [
            'relations' => [[
                'id' => 'r-new',
                'scope' => 'document',
                'type' => 'amends',
                'target_title' => 'ประกาศที่ถูกเชื่อม',
                'requires_new_proclamation' => true,
                'proclamation_due_date' => $due,
            ]],
        ])->assertOk();

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, function (OversightReminderMail $mail): bool {
            return $mail->lawTitle === 'ฉบับที่ต้องออกประกาศใหม่'
                && count($mail->hits) === 1
                && $mail->hits[0]['days_left'] === 14
                && $mail->hits[0]['label'] === 'ออกประกาศฉบับใหม่ทั้งฉบับ: ประกาศที่ถูกเชื่อม'
                && str_contains($mail->envelope()->subject, 'ต้องออกประกาศตามกฎหมาย')
                && str_contains($mail->bodyHtml, 'ใกล้ถึงกำหนดต้องออกประกาศฉบับใหม่')
                && str_contains($mail->bodyHtml, 'ออกประกาศฉบับใหม่ทั้งฉบับ: ประกาศที่ถูกเชื่อม');
        });
        Mail::assertSent(OversightReminderMail::class, 1);
    }

    public function test_publish_due_and_steps_no_longer_trigger_deadline_mail(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $due = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(14)->toDateString();
        $id = 'ov_no_manual_'.uniqid();
        $this->seedDocument($id, 'ฉบับไม่มีประกาศจากความสัมพันธ์');
        $this->seedLegacyStatus($id, 'อยู่ระหว่างการยกร่าง', $due, [
            'drafting' => ['due_date' => $due],
        ], [
            'primary' => ['name' => 'สมชาย', 'email' => 'owner@example.com'],
            'backup' => ['name' => 'สุดา', 'email' => 'backup@example.com'],
        ]);

        $this->getJson('/api/oversight/mail')
            ->assertOk()
            ->assertJsonPath('pending_count', 0);

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_proclamation_reminder_stops_when_the_new_law_is_in_force(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $due = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(14)->toDateString();
        $whole = 'ov_whole_'.uniqid();
        $partial = 'ov_part_'.uniqid();
        $this->seedDocument($whole, 'ต้องออกทั้งฉบับ');
        $this->seedDocument($partial, 'ต้องออกบางข้อ');
        $this->track($whole);
        $this->track($partial);

        $this->putJson("/api/documents/{$whole}/document-review", [
            'relations' => [[
                'id' => 'r-whole',
                'scope' => 'document',
                'type' => 'amends',
                'target_title' => 'กฎหมายเดิม',
                'requires_new_proclamation' => true,
                'proclamation_scope' => 'whole',
                'proclamation_due_date' => $due,
                'proclamation_issued_on' => '2026-10-20',
            ]],
        ])->assertOk()->assertJsonPath('relations.0.proclamation_issued_on', '2026-10-20');

        $this->putJson("/api/documents/{$partial}/document-review", [
            'relations' => [[
                'id' => 'r-part',
                'scope' => 'document',
                'type' => 'amends',
                'target_title' => 'กฎหมายเดิม',
                'requires_new_proclamation' => true,
                'proclamation_scope' => 'sections',
                'proclamation_sections' => ['ข้อ ๕', 'ข้อ ๗'],
                'proclamation_due_date' => $due,
            ]],
        ])->assertOk()->assertJsonPath('relations.0.proclamation_sections', ['ข้อ ๕', 'ข้อ ๗']);

        $child = 'ov_child_'.uniqid();
        $this->seedDocument($child, 'ประกาศลูก');
        $this->putJson("/api/documents/{$child}/document-review", [
            'law_meta' => [
                'status' => 'มีผลบังคับใช้',
                'parent_document_ids' => [$partial],
            ],
            'relations' => [[
                'id' => 'cover-5',
                'scope' => 'section',
                'type' => 'amends',
                'target_document_id' => $partial,
                'target_title' => 'ต้องออกบางข้อ',
                'target_section' => 'ข้อ ๕',
            ]],
        ])->assertOk();

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, function (OversightReminderMail $mail): bool {
            return $mail->lawTitle === 'ต้องออกบางข้อ'
                && $mail->hits[0]['label'] === 'ออกประกาศฉบับใหม่บางข้อ (ข้อ ๕, ข้อ ๗): กฎหมายเดิม';
        });
        Mail::assertSent(OversightReminderMail::class, 1);
    }

    public function test_section_proclamation_stops_only_after_every_section_is_covered(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $due = Carbon::now('Asia/Bangkok')->startOfDay()->addDays(7)->toDateString();
        $id = 'ov_cover_'.uniqid();
        $this->seedDocument($id, 'รอครบทุกข้อ');
        $this->track($id);
        $this->putJson("/api/documents/{$id}/document-review", [
            'relations' => [[
                'id' => 'r-sections',
                'type' => 'amends',
                'target_title' => 'ต้นทาง',
                'requires_new_proclamation' => true,
                'proclamation_scope' => 'sections',
                'proclamation_sections' => ['ข้อ ๕'],
                'proclamation_due_date' => $due,
            ]],
        ])->assertOk();

        $child = 'ov_cover_child_'.uniqid();
        $this->seedDocument($child, 'ประกาศที่ครอบข้อ');
        $this->putJson("/api/documents/{$child}/document-review", [
            'law_meta' => ['status' => 'มีผลบังคับใช้', 'parent_document_ids' => [$id]],
            'relations' => [[
                'id' => 'covered',
                'type' => 'amends',
                'target_document_id' => $id,
                'target_title' => 'รอครบทุกข้อ',
                'target_section' => 'ข้อ ๕',
            ]],
        ])->assertOk();

        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_admin_can_send_one_law_before_the_reminder_day(): void
    {
        Carbon::setTestNow('2026-10-23 02:00:00');
        Mail::fake();

        $id = 'ov_early_'.uniqid();
        $this->seedDocument($id, 'ฉบับส่งก่อนถึงรอบ');
        $this->track($id);
        $this->setProclamation($id, '2026-12-20');

        $this->postJson('/api/oversight/mail/send', ['document_id' => $id])
            ->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('emails.0', 'owner@example.com');
        Mail::assertSent(OversightReminderMail::class, 1);

        Carbon::setTestNow('2026-12-13 02:00:00');
        $this->artisan('oversight:send-reminders')->assertSuccessful();
        Mail::assertSent(OversightReminderMail::class, 2);

        $closed = 'ov_closed_send_'.uniqid();
        $this->seedDocument($closed);
        $this->seedLegacyStatus($closed, 'ยกเลิก', '2026-12-20', [], [
            'primary' => ['name' => 'สมชาย', 'email' => 'owner@example.com'],
            'backup' => ['name' => 'สุดา', 'email' => 'backup@example.com'],
        ]);
        $this->setProclamation($closed, '2026-12-20');
        $this->postJson('/api/oversight/mail/send', ['document_id' => $closed])->assertStatus(422);
        $this->postJson('/api/oversight/mail/send', ['document_id' => 'missing_'.uniqid()])->assertNotFound();
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
     * @param  array<string, array{name?: string, email?: string|null}>  $contacts
     */
    private function track(string $id, array $contacts = []): void
    {
        $this->putJson("/api/oversight/{$id}", [
            'primary' => $contacts['primary'] ?? ['name' => 'สมชาย', 'email' => 'owner@example.com'],
            'backup' => $contacts['backup'] ?? ['name' => 'สุดา', 'email' => 'backup@example.com'],
        ])->assertOk();
    }

    private function setProclamation(string $id, string $due, string $targetTitle = 'ประกาศที่ถูกเชื่อม'): void
    {
        $this->putJson("/api/documents/{$id}/document-review", [
            'relations' => [[
                'id' => 'r-'.$id,
                'scope' => 'document',
                'type' => 'amends',
                'target_title' => $targetTitle,
                'requires_new_proclamation' => true,
                'proclamation_due_date' => $due,
            ]],
        ])->assertOk();
    }

    /**
     * @param  array<string, array{due_date?: string|null, completed_on?: string|null}>  $stepDates
     * @param  array<string, array{name?: string, email?: string|null}>  $contacts
     */
    private function seedLegacyStatus(
        string $id,
        string $status,
        ?string $publishDue = null,
        array $stepDates = [],
        array $contacts = [],
    ): void {
        $store = app(ReviewStore::class);
        $doc = $store->getReviewDocument($id);
        $steps = [];
        foreach (['drafting', 'submitted', 'approved', 'published', 'cancelled'] as $key) {
            $steps[] = [
                'key' => $key,
                'due_date' => $stepDates[$key]['due_date'] ?? null,
                'completed_on' => $stepDates[$key]['completed_on'] ?? null,
            ];
        }
        $doc['oversight'] = [
            'status' => $status,
            'publish_due_date' => $publishDue,
            'steps' => $steps,
            'primary' => $contacts['primary'] ?? ['name' => 'สมชาย', 'email' => 'owner@example.com'],
            'backup' => $contacts['backup'] ?? ['name' => 'สุดา', 'email' => 'backup@example.com'],
            'reminders_sent' => [],
        ];
        $store->writeReviewDocument($id, $doc);
    }
}
