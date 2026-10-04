<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexer;
use Tests\TestCase;

class MigrateEnforcementStatusTest extends TestCase
{
    private ReviewStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(ReviewStore::class);
        app('mongo.blob.master')->truncate();
    }

    public function test_migrates_thai_names_and_empty_status_to_codes(): void
    {
        $this->seedLaw('law_active', 'มีผลบังคับใช้');
        $this->seedLaw('law_draft', '');
        $this->mockReindex(['law_active', 'law_draft']);

        $this->artisan('master-data:migrate', ['kind' => 'enforcement-status'])
            ->assertExitCode(0);

        $this->assertSame('STA01', $this->store->getReviewDocument('law_active')['law_meta']['status']);
        $this->assertSame('STA03', $this->store->getReviewDocument('law_draft')['law_meta']['status']);
    }

    public function test_dry_run_audits_without_writing(): void
    {
        $this->seedLaw('law_active', 'มีผลบังคับใช้');

        $this->artisan('master-data:migrate', ['kind' => 'enforcement-status', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame('มีผลบังคับใช้', $this->store->getReviewDocument('law_active')['law_meta']['status']);
    }

    public function test_unmapped_values_abort_and_show_document_ids(): void
    {
        $this->seedLaw('law_unknown', 'unknown status');

        $this->artisan('master-data:migrate', ['kind' => 'enforcement-status'])
            ->expectsOutputToContain('UNMAPPED')
            ->expectsOutputToContain('law_unknown')
            ->assertExitCode(1);

        $this->assertSame('unknown status', $this->store->getReviewDocument('law_unknown')['law_meta']['status']);
    }

    public function test_explicit_map_migrates_unmapped_values(): void
    {
        $this->seedLaw('law_custom', 'legacy live');
        $this->mockReindex(['law_custom']);

        $this->artisan('master-data:migrate', [
            'kind' => 'enforcement-status',
            '--map' => ['legacy live=STA01'],
        ])->assertExitCode(0);

        $this->assertSame('STA01', $this->store->getReviewDocument('law_custom')['law_meta']['status']);
    }

    public function test_existing_codes_are_idempotent(): void
    {
        $this->seedLaw('law_code', 'STA01');

        $this->artisan('master-data:migrate', ['kind' => 'enforcement-status'])
            ->expectsOutputToContain('0 document(s) updated.')
            ->assertExitCode(0);

        $this->assertSame('STA01', $this->store->getReviewDocument('law_code')['law_meta']['status']);
    }

    private function seedLaw(string $id, string $status): void
    {
        $this->store->setStatus($id, ['status' => 'done', 'source_file' => "{$id}.pdf"]);
        $this->store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => "{$id}.pdf",
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => ['status' => $status, 'title' => $id],
            'pages' => [],
        ]);
    }

    /**
     * @param  list<string>  $documentIds
     */
    private function mockReindex(array $documentIds): void
    {
        $this->mock(ElasticClient::class, function ($mock): void {
            $mock->shouldReceive('indexExists')->andReturn(true);
        });
        $this->mock(LawIndexer::class, function ($mock) use ($documentIds): void {
            foreach ($documentIds as $documentId) {
                $mock->shouldReceive('index')->once()->with($documentId);
            }
        });
    }
}
