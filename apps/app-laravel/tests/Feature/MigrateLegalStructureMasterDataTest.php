<?php

namespace Tests\Feature;

use App\Services\MasterData\LegalStructures;
use App\Services\ReviewStore;
use Tests\TestCase;

class MigrateLegalStructureMasterDataTest extends TestCase
{
    private ReviewStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(ReviewStore::class);
        app('mongo.blob.master')->truncate();
    }

    public function test_migrates_legacy_keys_to_codes_and_keeps_other_meta(): void
    {
        $this->seedBlocks('lst_legacy', [
            ['TITLE', ['landingai' => ['chunk_type' => 'title']]],
            ['ARTICLE', []],
            ['CHAPTER', []],
            ['SECTION', []],
            ['LST004', []],
            [null, []],
        ]);

        $this->artisan('master-data:migrate', ['kind' => 'legal-structure'])
            ->expectsOutputToContain('4 block(s) in 1 document(s) updated.')
            ->assertExitCode(0);

        $blocks = $this->blocks('lst_legacy');
        $this->assertSame(['LST001', 'LST004', 'LST012', 'LST011', 'LST004', null], array_map(
            static fn (array $block): ?string => $block['meta']['chunk_type'] ?? null,
            $blocks,
        ));
        $this->assertSame('title', $blocks[0]['meta']['landingai']['chunk_type']);
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->seedBlocks('lst_dry', [['CLAUSE', []]]);

        $this->artisan('master-data:migrate', ['kind' => 'legal-structure', '--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 block(s) in 1 document(s)')
            ->assertExitCode(0);

        $this->assertSame('CLAUSE', $this->blocks('lst_dry')[0]['meta']['chunk_type']);
    }

    public function test_unmapped_values_abort_and_explicit_map_migrates(): void
    {
        $this->seedBlocks('lst_unknown', [['APPENDIX', []], ['TITLE', []]]);

        $this->artisan('master-data:migrate', ['kind' => 'legal-structure'])
            ->expectsOutputToContain('UNMAPPED')
            ->expectsOutputToContain('lst_unknown')
            ->assertExitCode(1);

        $this->assertSame('APPENDIX', $this->blocks('lst_unknown')[0]['meta']['chunk_type']);
        $this->assertSame('TITLE', $this->blocks('lst_unknown')[1]['meta']['chunk_type']);

        $this->artisan('master-data:migrate', [
            'kind' => 'legal-structure',
            '--map' => ['APPENDIX=LST012'],
        ])->assertExitCode(0);

        $this->assertSame('LST012', $this->blocks('lst_unknown')[0]['meta']['chunk_type']);
        $this->assertSame('LST001', $this->blocks('lst_unknown')[1]['meta']['chunk_type']);
    }

    public function test_second_run_is_a_no_op(): void
    {
        $this->seedBlocks('lst_idem', [['PREAMBLE', []], ['LST002', []]]);

        $this->artisan('master-data:migrate', ['kind' => 'legal-structure', '--map' => $this->leftoverMap()])
            ->assertExitCode(0);
        $this->artisan('master-data:migrate', ['kind' => 'legal-structure'])
            ->expectsOutputToContain('0 block(s) in 0 document(s) updated.')
            ->assertExitCode(0);

        $this->assertSame(['LST002', 'LST002'], array_map(
            static fn (array $block): ?string => $block['meta']['chunk_type'] ?? null,
            $this->blocks('lst_idem'),
        ));
    }

    /**
     * Map unknown chunk types left by other tests in the shared DB to LST012.
     *
     * @return list<string>
     */
    private function leftoverMap(): array
    {
        $legalStructures = app(LegalStructures::class);
        $map = [];
        foreach ($this->store->listDocuments() as $row) {
            try {
                $document = $this->store->getReviewDocument((string) $row['document_id']);
            } catch (\RuntimeException) {
                continue;
            }
            foreach (($document['pages'] ?? []) as $page) {
                foreach ((array) ($page['blocks'] ?? []) as $block) {
                    $value = trim((string) ($block['meta']['chunk_type'] ?? ''));
                    if ($value !== '' && ! str_contains($value, '=') && $legalStructures->resolve($value) === null) {
                        $map[] = $value.'=LST012';
                    }
                }
            }
        }

        return array_values(array_unique($map));
    }

    /**
     * @param  list<array{0: string|null, 1: array<string, mixed>}>  $specs
     */
    private function seedBlocks(string $id, array $specs): void
    {
        $blocks = [];
        foreach ($specs as $index => [$chunkType, $extraMeta]) {
            $meta = $extraMeta;
            if ($chunkType !== null) {
                $meta['chunk_type'] = $chunkType;
            }
            $blocks[] = [
                'block_id' => sprintf('p1-b%04d', $index + 1),
                'type' => 'paragraph',
                'reading_order' => $index + 1,
                'raw_text' => "block {$index}",
                'normalized_text' => "block {$index}",
                'ai_suggested_text' => '',
                'approved_text' => "block {$index}",
                'confidence' => 1.0,
                'needs_review' => false,
                'flags' => [],
                'meta' => $meta,
            ];
        }

        $this->store->setStatus($id, ['status' => 'done', 'source_file' => "{$id}.docx"]);
        $this->store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => "{$id}.docx",
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => count($blocks), 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => $blocks]],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blocks(string $id): array
    {
        return $this->store->getReviewDocument($id)['pages'][0]['blocks'];
    }
}
