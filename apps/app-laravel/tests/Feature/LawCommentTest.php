<?php

namespace Tests\Feature;

use App\Services\Auth\MongoUserStore;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LawCommentTest extends TestCase
{
    private string $documentId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->documentId = 'doc_cmt_'.uniqid();
        $this->seedLaw($this->documentId, 'พระราชบัญญัติทดสอบ', '2024-01-01');
    }

    public function test_guest_can_list_public_comments_but_cannot_post(): void
    {
        $this->user('alice', 'Alice');
        $this->as('alice')->post($this->uri(), [
            'type' => 'question',
            'body' => 'มาตรานี้ใช้กับใคร',
            'visibility' => 'public',
        ])->assertCreated();

        $this->flushSession();
        $this->withHeaders($this->headers())->getJson($this->uri())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('counts.all', 1)
            ->assertJsonPath('items.0.body', 'มาตรานี้ใช้กับใคร')
            ->assertJsonPath('items.0.is_mine', false)
            ->assertJsonPath('document.title', 'พระราชบัญญัติทดสอบ')
            ->assertJsonPath('can_reply', false);

        $this->flushSession();
        $this->withHeaders($this->headers())->postJson($this->uri(), [
            'type' => 'question',
            'body' => 'ยังไม่ได้เข้าสู่ระบบ',
        ])->assertStatus(401);
    }

    public function test_private_comments_are_visible_only_to_the_author_and_staff(): void
    {
        $this->user('alice', 'Alice');
        $this->user('bob', 'Bob');
        $this->user('staff', 'เจ้าหน้าที่', true);

        $created = $this->as('alice')->post($this->uri(), [
            'type' => 'incorrect',
            'body' => 'ตัวเลขมาตราผิด',
            'section_ref' => 'มาตรา 3',
            'visibility' => 'private',
        ])->assertCreated()->json('comment');

        $this->assertSame('private', $created['visibility']);
        $this->assertTrue($created['is_mine']);

        $this->as('alice')->getJson($this->uri())
            ->assertOk()
            ->assertJsonPath('counts.visibility.mine', 1)
            ->assertJsonPath('items.0.body', 'ตัวเลขมาตราผิด');

        $this->as('bob')->getJson($this->uri())
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('counts.all', 0);

        $this->as('staff')->getJson($this->uri())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('can_reply', true)
            ->assertJsonPath('items.0.is_mine', false);
    }

    public function test_staff_reply_marks_the_comment_answered(): void
    {
        $this->user('alice', 'Alice');
        $this->user('staff', 'กองกฎหมาย', true);
        $id = $this->as('alice')->post($this->uri(), [
            'type' => 'suggestion',
            'body' => 'ขอให้เพิ่มคำอธิบาย',
            'visibility' => 'public',
        ])->assertCreated()->json('comment.id');

        $this->as('alice')->postJson($this->uri($id).'/reply', ['body' => 'รับทราบ'])
            ->assertStatus(403);

        $this->as('staff')->postJson($this->uri($id).'/reply', ['body' => 'จะนำไปประกอบการพิจารณา'])
            ->assertOk()
            ->assertJsonPath('comment.status', 'answered')
            ->assertJsonPath('comment.reply.body', 'จะนำไปประกอบการพิจารณา')
            ->assertJsonPath('comment.reply.author_name', 'กองกฎหมาย');

        $this->flushSession();
        $this->withHeaders($this->headers())->getJson($this->uri().'?status=answered')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('counts.statuses.pending', 0)
            ->assertJsonPath('counts.statuses.answered', 1);
    }

    public function test_filters_search_and_rejects_invalid_payloads(): void
    {
        $this->user('alice', 'Alice');
        $this->as('alice')->post($this->uri(), ['type' => 'question', 'body' => 'คำถามเรื่องขั้นตอน', 'visibility' => 'public'])->assertCreated();
        $this->as('alice')->post($this->uri(), ['type' => 'suggestion', 'body' => 'ข้อเสนอให้เขียนใหม่อ่านง่าย', 'visibility' => 'public'])->assertCreated();

        $this->flushSession();
        $this->withHeaders($this->headers())->getJson($this->uri().'?type=question')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('counts.types.question', 1)
            ->assertJsonPath('counts.types.suggestion', 1);

        $this->flushSession();
        $this->withHeaders($this->headers())->getJson($this->uri().'?q='.urlencode('อ่านง่าย'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.type', 'suggestion');

        $this->as('alice')->postJson($this->uri(), ['type' => 'nope', 'body' => 'x'])->assertStatus(422);
        $this->as('alice')->post($this->uri(), [
            'type' => 'question',
            'body' => 'มีไฟล์เกิน',
            'attachments' => [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('d.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('f.pdf', 10, 'application/pdf'),
            ],
        ])->assertStatus(422);

        $this->as('alice')->post($this->uri(), [
            'type' => 'question',
            'body' => 'ไฟล์ไม่รองรับ',
            'attachments' => [UploadedFile::fake()->create('note.exe', 10, 'application/octet-stream')],
        ])->assertStatus(422);
    }

    public function test_attachment_download_follows_comment_visibility(): void
    {
        $this->user('alice', 'Alice');
        $this->user('bob', 'Bob');
        $created = $this->as('alice')->post($this->uri(), [
            'type' => 'question',
            'body' => 'ขอส่งเอกสารประกอบ',
            'visibility' => 'private',
            'attachments' => [UploadedFile::fake()->create('หลักฐาน.pdf', 12, 'application/pdf')],
        ])->assertCreated()->json('comment');

        $fileId = $created['attachments'][0]['id'];
        $url = $this->uri($created['id']).'/files/'.$fileId;

        $this->as('bob')->get($url)->assertNotFound();
        $this->as('alice')->get($url)->assertOk();
        Storage::disk('local')->assertExists('law-comments/'.$created['id'].'/'.$fileId);
    }

    public function test_missing_document_returns_404_and_enforced_draft_stays_closed(): void
    {
        $this->withHeaders($this->headers())->getJson('/api/laws/missing_doc/comments')->assertNotFound();

        $draft = 'doc_cmt_draft_'.uniqid();
        $this->seedLaw($draft, 'ร่างที่ยังไม่เผยแพร่', '');
        config(['auth_ldap.enforced' => true]);

        $this->withHeaders($this->headers())->getJson('/api/laws/'.$draft.'/comments')->assertStatus(401);
        $this->withHeaders($this->headers())->getJson($this->uri())->assertOk();
    }

    private function seedLaw(string $id, string $title, string $publishedDate): void
    {
        app(ReviewStore::class)->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => 'law.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
            'law_meta' => [
                'title' => $title,
                'published_date' => $publishedDate,
                'access_scope' => 'public',
            ],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'p1-b0001',
                    'type' => 'title',
                    'reading_order' => 1,
                    'raw_text' => $title,
                    'normalized_text' => $title,
                    'ai_suggested_text' => $title,
                    'approved_text' => $title,
                    'confidence' => 1.0,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => ['layout' => ['tabs' => []], 'formatting' => []],
                ]],
            ]],
        ]);
    }

    private function user(string $username, string $name, bool $super = false): void
    {
        app('mongo.blob.users')->write(MongoUserStore::KIND, $username, [
            'username' => $username,
            'name' => $name,
            'email' => $username.'@example.com',
            'is_super_admin' => $super,
            'is_disabled' => false,
            'memberships' => [],
        ]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Referer' => 'http://localhost:8500/law/'.$this->documentId,
            'Accept' => 'application/json',
        ];
    }

    private function as(string $username): self
    {
        return $this->withSession(['auth_username' => $username])->withHeaders($this->headers());
    }

    private function uri(?string $commentId = null): string
    {
        $uri = '/api/laws/'.$this->documentId.'/comments';

        return $commentId === null ? $uri : $uri.'/'.$commentId;
    }
}
