<?php

namespace Tests\Feature;

use App\Services\MasterData\ChangeStatuses;
use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexer;
use Tests\TestCase;

class MigrateChangeStatusMasterDataTest extends TestCase
{
    private ReviewStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(ReviewStore::class);
        app('mongo.blob.master')->truncate();
    }

    public function test_migrates_status_and_detail_names_aliases_and_codes(): void
    {
        $this->seedLaw('chg_names', [
            'change_status' => 'ปรับปรุงรายข้อ',
            'change_details' => ['ยกเลิกข้อ', 'เพิ่ม', 'CHD04', 'เพิ่ม'],
        ]);
        $this->seedLaw('chg_alias', [
            'change_status' => 'ยกเลิกทั้งฉบับ',
            'change_details' => ['แก้ไข'],
        ]);
        $this->mockReindex(['chg_names', 'chg_alias']);

        $this->artisan('master-data:migrate', ['kind' => 'change-status', '--map' => $this->leftoverMap()])
            ->assertExitCode(0);

        $this->assertSame([
            'change_status' => 'CHG03',
            'change_details' => ['CHD01', 'CHD03', 'CHD04'],
        ], $this->changeMeta('chg_names'));
        $this->assertSame([
            'change_status' => 'CHG02',
            'change_details' => ['CHD04'],
        ], $this->changeMeta('chg_alias'));
    }

    public function test_dry_run_does_not_write_or_reindex(): void
    {
        $this->seedLaw('chg_dry', [
            'change_status' => 'กฎหมายใหม่',
            'change_details' => ['ยกเลิกข้อ'],
        ]);
        $this->mock(ElasticClient::class, function ($mock): void {
            $mock->shouldReceive('indexExists')->never();
        });
        $this->mock(LawIndexer::class, function ($mock): void {
            $mock->shouldReceive('index')->never();
        });

        $this->artisan('master-data:migrate', ['kind' => 'change-status', '--dry-run' => true, '--map' => $this->leftoverMap()])
            ->expectsOutputToContain('Dry run: 1 document(s)')
            ->assertExitCode(0);

        $this->assertSame([
            'change_status' => 'กฎหมายใหม่',
            'change_details' => ['ยกเลิกข้อ'],
        ], $this->changeMeta('chg_dry'));
    }

    public function test_unmapped_values_abort_and_explicit_map_migrates(): void
    {
        $this->seedLaw('chg_unknown', [
            'change_status' => 'สถานะเก่า',
            'change_details' => ['รายละเอียดเก่า'],
        ]);

        $this->artisan('master-data:migrate', ['kind' => 'change-status', '--map' => $this->leftoverMap(['สถานะเก่า', 'รายละเอียดเก่า'])])
            ->expectsOutputToContain('UNMAPPED')
            ->expectsOutputToContain('chg_unknown')
            ->assertExitCode(1);

        $this->assertSame([
            'change_status' => 'สถานะเก่า',
            'change_details' => ['รายละเอียดเก่า'],
        ], $this->changeMeta('chg_unknown'));

        $this->mockReindex(['chg_unknown']);
        $this->artisan('master-data:migrate', [
            'kind' => 'change-status',
            '--map' => [...$this->leftoverMap(), 'สถานะเก่า=CHG04', 'รายละเอียดเก่า=CHD02'],
        ])->assertExitCode(0);

        $this->assertSame([
            'change_status' => 'CHG04',
            'change_details' => ['CHD02'],
        ], $this->changeMeta('chg_unknown'));
    }

    public function test_existing_codes_and_empty_values_are_idempotent(): void
    {
        $this->seedLaw('chg_code', [
            'change_status' => 'CHG01',
            'change_details' => ['CHD01', 'CHD03'],
        ]);
        $this->seedLaw('chg_empty', [
            'change_status' => '',
            'change_details' => [''],
        ]);
        $this->mock(ElasticClient::class, function ($mock): void {
            $mock->shouldReceive('indexExists')->andReturn(true);
        });
        $this->mock(LawIndexer::class, function ($mock): void {
            $mock->shouldReceive('index')->never()->with('chg_code');
            $mock->shouldReceive('index')->never()->with('chg_empty');
            $mock->shouldReceive('index')->zeroOrMoreTimes();
        });

        $this->artisan('master-data:migrate', ['kind' => 'change-status', '--map' => $this->leftoverMap()]);
        $this->artisan('master-data:migrate', ['kind' => 'change-status'])
            ->expectsOutputToContain('0 document(s) updated.')
            ->assertExitCode(0);

        $this->assertSame([
            'change_status' => 'CHG01',
            'change_details' => ['CHD01', 'CHD03'],
        ], $this->changeMeta('chg_code'));
        $this->assertSame([
            'change_status' => '',
            'change_details' => [''],
        ], $this->changeMeta('chg_empty'));
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
     * @return array{change_status: mixed, change_details: mixed}
     */
    private function changeMeta(string $documentId): array
    {
        $meta = $this->store->getReviewDocument($documentId)['law_meta'];

        return [
            'change_status' => $meta['change_status'] ?? null,
            'change_details' => $meta['change_details'] ?? null,
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
            $mock->shouldReceive('index')->zeroOrMoreTimes();
        });
    }

    /**
     * @param  list<string>  $except
     * @return list<string>
     */
    private function leftoverMap(array $except = []): array
    {
        $changeStatuses = app(ChangeStatuses::class);
        $map = [];
        foreach ($this->store->listLawMeta() as $row) {
            $status = trim((string) ($row['change_status'] ?? ''));
            if ($status !== '' && ! in_array($status, $except, true) && $changeStatuses->resolve($status) === null) {
                $map[] = $status.'=CHG01';
            }

            foreach ((array) ($row['change_details'] ?? []) as $detail) {
                $detail = trim((string) $detail);
                if ($detail !== '' && ! in_array($detail, $except, true) && $changeStatuses->resolveDetail($detail) === null) {
                    $map[] = $detail.'=CHD04';
                }
            }
        }

        return array_values(array_unique($map));
    }
}
