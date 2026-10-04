<?php

namespace Tests\Feature;

use App\Services\MasterData\LawCategories;
use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexer;
use Tests\TestCase;

class MigrateLawCategoryMasterDataTest extends TestCase
{
    private ReviewStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(ReviewStore::class);
        app('mongo.blob.master')->truncate();
    }

    public function test_migrates_names_slugs_and_legacy_single_value_to_codes(): void
    {
        $this->seedLaw('cat_names', ['law_groups' => ['ด้านกิจการนิสิต', 'hr-discipline', 'DCT002']]);
        $this->seedLaw('cat_legacy', ['law_group' => 'ด้านอื่น ๆ']);
        $this->mockReindex(['cat_names', 'cat_legacy']);

        $this->artisan('master-data:migrate', ['kind' => 'law-category'])
            ->assertExitCode(0);

        $this->assertSame(['law_groups' => ['DCT002', 'DCT007'], 'law_group' => 'DCT002'], $this->categoryMeta('cat_names'));
        $this->assertSame(['law_groups' => ['DCT012'], 'law_group' => 'DCT012'], $this->categoryMeta('cat_legacy'));
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->seedLaw('cat_dry', ['law_groups' => ['ด้านกิจการนิสิต']]);

        $this->artisan('master-data:migrate', ['kind' => 'law-category', '--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 document(s)')
            ->assertExitCode(0);

        $this->assertSame(['ด้านกิจการนิสิต'], $this->categoryMeta('cat_dry')['law_groups']);
    }

    public function test_unmapped_values_abort_and_explicit_map_migrates(): void
    {
        $this->seedLaw('cat_unknown', ['law_groups' => ['ด้านกฎหมายและนิติการ']]);

        $this->artisan('master-data:migrate', ['kind' => 'law-category'])
            ->expectsOutputToContain('UNMAPPED')
            ->expectsOutputToContain('cat_unknown')
            ->assertExitCode(1);

        $this->assertSame(['ด้านกฎหมายและนิติการ'], $this->categoryMeta('cat_unknown')['law_groups']);

        $this->mockReindex(['cat_unknown']);
        $this->artisan('master-data:migrate', [
            'kind' => 'law-category',
            '--map' => ['ด้านกฎหมายและนิติการ=DCT012'],
        ])->assertExitCode(0);

        $this->assertSame(['law_groups' => ['DCT012'], 'law_group' => 'DCT012'], $this->categoryMeta('cat_unknown'));
    }

    public function test_existing_codes_are_idempotent(): void
    {
        $this->seedLaw('cat_code', ['law_groups' => ['DCT001', 'DCT003'], 'law_group' => 'DCT001']);
        $this->mock(ElasticClient::class, function ($mock): void {
            $mock->shouldReceive('indexExists')->andReturn(true);
        });
        $this->mock(LawIndexer::class, function ($mock): void {
            $mock->shouldReceive('index')->never()->with('cat_code');
            $mock->shouldReceive('index')->zeroOrMoreTimes();
        });

        // First run may normalise documents left by other tests; the second run must be a no-op.
        $this->artisan('master-data:migrate', ['kind' => 'law-category', '--map' => $this->leftoverMap()]);
        $this->artisan('master-data:migrate', ['kind' => 'law-category'])
            ->expectsOutputToContain('0 document(s) updated.')
            ->assertExitCode(0);

        $this->assertSame(['law_groups' => ['DCT001', 'DCT003'], 'law_group' => 'DCT001'], $this->categoryMeta('cat_code'));
    }

    /**
     * Map any unknown category values left in the shared test DB to DCT012.
     *
     * @return list<string>
     */
    private function leftoverMap(): array
    {
        $categories = app(LawCategories::class);
        $map = [];
        foreach ($this->store->listLawMeta() as $row) {
            foreach ((array) ($row['law_groups'] ?? []) as $value) {
                $value = trim((string) $value);
                if ($value !== '' && ! str_contains($value, '=') && $categories->resolve($value) === null) {
                    $map[] = $value.'=DCT012';
                }
            }
        }

        return array_values(array_unique($map));
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
     * @return array{law_groups: mixed, law_group: mixed}
     */
    private function categoryMeta(string $documentId): array
    {
        $meta = $this->store->getReviewDocument($documentId)['law_meta'];

        return [
            'law_groups' => $meta['law_groups'] ?? null,
            'law_group' => $meta['law_group'] ?? null,
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
