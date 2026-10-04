<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexer;
use Tests\TestCase;

class MigrateLawTypeMasterDataTest extends TestCase
{
    private ReviewStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(ReviewStore::class);
        app('mongo.blob.master')->truncate();
    }

    public function test_migrates_legacy_announcement_aliases_to_type_and_issuer_codes(): void
    {
        $this->seedLaw('law_command', ['law_type' => 'คำสั่ง']);
        $this->seedLaw('law_resolution', ['law_type' => 'มติ']);
        $this->seedLaw('law_uni', ['law_type' => 'ประกาศที่ออกโดยมหาวิทยาลัย']);
        $this->seedLaw('law_council', ['law_type' => 'ประกาศที่ออกโดยสภามหาวิทยาลัย']);
        $this->mockReindex(['law_command', 'law_resolution', 'law_uni', 'law_council']);

        $this->artisan('master-data:migrate', ['kind' => 'law-type'])
            ->assertExitCode(0);

        $this->assertSame(['law_type' => 'LTY01', 'issuer' => 'ISS01'], $this->onlyTypeIssuer('law_command'));
        $this->assertSame(['law_type' => 'LTY01', 'issuer' => 'ISS02'], $this->onlyTypeIssuer('law_resolution'));
        $this->assertSame(['law_type' => 'LTY01', 'issuer' => 'ISS01'], $this->onlyTypeIssuer('law_uni'));
        $this->assertSame(['law_type' => 'LTY01', 'issuer' => 'ISS02'], $this->onlyTypeIssuer('law_council'));
    }

    public function test_migrates_aliases_preserves_existing_issuer_and_clears_stale_issuer(): void
    {
        $this->seedLaw('law_prb', ['law_type' => 'พ.ร.บ.', 'issuer' => 'มหาวิทยาลัย']);
        $this->seedLaw('law_existing_issuer', ['law_type' => 'คำสั่ง', 'issuer' => 'สภามหาวิทยาลัย']);
        $this->mockReindex(['law_prb', 'law_existing_issuer']);

        $this->artisan('master-data:migrate', ['kind' => 'law-type'])
            ->assertExitCode(0);

        $this->assertSame(['law_type' => 'LTY05', 'issuer' => null], $this->onlyTypeIssuer('law_prb'));
        $this->assertSame(['law_type' => 'LTY01', 'issuer' => 'ISS02'], $this->onlyTypeIssuer('law_existing_issuer'));
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->seedLaw('law_dry', ['law_type' => 'พ.ร.บ.']);

        $this->artisan('master-data:migrate', ['kind' => 'law-type', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame('พ.ร.บ.', $this->store->getReviewDocument('law_dry')['law_meta']['law_type']);
    }

    public function test_unmapped_values_abort_and_explicit_map_migrates(): void
    {
        $this->seedLaw('law_unknown', ['law_type' => 'legacy custom']);

        $this->artisan('master-data:migrate', ['kind' => 'law-type'])
            ->expectsOutputToContain('UNMAPPED')
            ->expectsOutputToContain('law_unknown')
            ->assertExitCode(1);

        $this->assertSame('legacy custom', $this->store->getReviewDocument('law_unknown')['law_meta']['law_type']);

        $this->mockReindex(['law_unknown']);
        $this->artisan('master-data:migrate', [
            'kind' => 'law-type',
            '--map' => ['legacy custom=LTY08'],
        ])->assertExitCode(0);

        $this->assertSame(['law_type' => 'LTY08', 'issuer' => null], $this->onlyTypeIssuer('law_unknown'));
    }

    public function test_existing_codes_are_idempotent_and_old_command_is_deprecated(): void
    {
        $this->seedLaw('law_code', ['law_type' => 'LTY01', 'issuer' => 'ISS01']);

        $this->artisan('master-data:migrate', ['kind' => 'law-type'])
            ->expectsOutputToContain('0 document(s) updated.')
            ->assertExitCode(0);

        $this->assertSame(['law_type' => 'LTY01', 'issuer' => 'ISS01'], $this->onlyTypeIssuer('law_code'));

        $this->artisan('laws:migrate-types')
            ->expectsOutputToContain('Deprecated')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function seedLaw(string $id, array $meta): void
    {
        $this->store->setStatus($id, ['status' => 'done', 'source_file' => "{$id}.pdf"]);
        $this->store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => "{$id}.pdf",
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => array_merge(['title' => $id], $meta),
            'pages' => [],
        ]);
    }

    /**
     * @return array{law_type: mixed, issuer: mixed}
     */
    private function onlyTypeIssuer(string $documentId): array
    {
        $meta = $this->store->getReviewDocument($documentId)['law_meta'];

        return [
            'law_type' => $meta['law_type'] ?? null,
            'issuer' => $meta['issuer'] ?? null,
        ];
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
