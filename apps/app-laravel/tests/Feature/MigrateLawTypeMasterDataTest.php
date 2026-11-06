<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexer;
use Tests\TestCase;

class MigrateLawTypeMasterDataTest extends TestCase
{
    private const ANNOUNCEMENT = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}";
    private const ANNOUNCEMENT_UNIVERSITY = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const COMMAND = "\u{0E04}\u{0E33}\u{0E2A}\u{0E31}\u{0E48}\u{0E07}";
    private const ANNOUNCEMENT_COUNCIL = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E2A}\u{0E20}\u{0E32}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const RESOLUTION = "\u{0E21}\u{0E15}\u{0E34}";
    private const UNIVERSITY = "\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const COUNCIL = "\u{0E2A}\u{0E20}\u{0E32}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const ACT_ABBR = "\u{0E1E}.\u{0E23}.\u{0E1A}.";

    private ReviewStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(ReviewStore::class);
        app('mongo.blob.master')->truncate();
    }

    public function test_migrates_legacy_announcement_aliases_to_folded_type_codes(): void
    {
        $this->seedLaw('law_command', ['law_type' => self::COMMAND]);
        $this->seedLaw('law_resolution', ['law_type' => self::RESOLUTION]);
        $this->seedLaw('law_uni', ['law_type' => self::ANNOUNCEMENT_UNIVERSITY, 'issuer' => 'ISS01']);
        $this->seedLaw('law_council', ['law_type' => self::ANNOUNCEMENT_COUNCIL, 'issuer' => 'ISS02']);
        $this->mockReindex(['law_command', 'law_resolution', 'law_uni', 'law_council']);

        $this->artisan('master-data:migrate', ['kind' => 'law-type'])
            ->assertExitCode(0);

        $this->assertSame(['law_type' => 'LTY01', 'issuer' => null], $this->onlyTypeIssuer('law_command'));
        $this->assertSame(['law_type' => 'LTY09', 'issuer' => null], $this->onlyTypeIssuer('law_resolution'));
        $this->assertSame(['law_type' => 'LTY01', 'issuer' => null], $this->onlyTypeIssuer('law_uni'));
        $this->assertSame(['law_type' => 'LTY09', 'issuer' => null], $this->onlyTypeIssuer('law_council'));
    }

    public function test_folds_legacy_issuer_for_bare_announcement_and_clears_stale_issuer(): void
    {
        $this->seedLaw('law_prb', ['law_type' => self::ACT_ABBR, 'issuer' => self::UNIVERSITY]);
        $this->seedLaw('law_uni', ['law_type' => self::ANNOUNCEMENT, 'issuer' => 'ISS01']);
        $this->seedLaw('law_council', ['law_type' => 'LTY01', 'issuer' => self::COUNCIL]);
        $this->mockReindex(['law_prb', 'law_uni', 'law_council']);

        $this->artisan('master-data:migrate', ['kind' => 'law-type'])
            ->assertExitCode(0);

        $this->assertSame(['law_type' => 'LTY05', 'issuer' => null], $this->onlyTypeIssuer('law_prb'));
        $this->assertSame(['law_type' => 'LTY01', 'issuer' => null], $this->onlyTypeIssuer('law_uni'));
        $this->assertSame(['law_type' => 'LTY09', 'issuer' => null], $this->onlyTypeIssuer('law_council'));
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->seedLaw('law_dry', ['law_type' => self::ACT_ABBR, 'issuer' => 'ISS01']);

        $this->artisan('master-data:migrate', ['kind' => 'law-type', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $meta = $this->store->getReviewDocument('law_dry')['law_meta'];
        $this->assertSame(self::ACT_ABBR, $meta['law_type']);
        $this->assertSame('ISS01', $meta['issuer']);
    }

    public function test_bare_announcement_without_issuer_needs_form_and_does_not_abort_or_write(): void
    {
        $this->seedLaw('law_needs_form', ['title' => 'ประกาศเก่า', 'law_type' => self::ANNOUNCEMENT]);
        $this->seedLaw('law_lty01_empty', ['title' => 'รหัสเก่า', 'law_type' => 'LTY01']);

        $this->artisan('master-data:migrate', ['kind' => 'law-type'])
            ->expectsOutputToContain('NEEDS_FORM')
            ->expectsOutputToContain('law_needs_form')
            ->expectsOutputToContain('law_lty01_empty')
            ->assertExitCode(0);

        $this->assertSame(['law_type' => self::ANNOUNCEMENT, 'issuer' => null], $this->onlyTypeIssuer('law_needs_form'));
        $this->assertSame(['law_type' => 'LTY01', 'issuer' => null], $this->onlyTypeIssuer('law_lty01_empty'));
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

    public function test_existing_non_ambiguous_codes_are_idempotent_and_old_command_is_deprecated(): void
    {
        $this->seedLaw('law_code', ['law_type' => 'LTY09', 'issuer' => null]);

        $this->artisan('master-data:migrate', ['kind' => 'law-type'])
            ->expectsOutputToContain('0 document(s) updated.')
            ->assertExitCode(0);

        $this->assertSame(['law_type' => 'LTY09', 'issuer' => null], $this->onlyTypeIssuer('law_code'));

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
