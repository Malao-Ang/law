<?php

namespace App\Console\Commands;

use App\Services\Search\LawIndexer;
use App\Services\Storage\MongoBlobStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ResetPermissionGroupsCommand extends Command
{
    protected $signature = 'permissions:reset-groups {--dry-run : แสดงจำนวนโดยไม่เขียนข้อมูล}';
    protected $description = 'สำรองและล้างกลุ่มสิทธิ์เดิม รวมถึงรายการอ้างอิงในเอกสารและไดเรกทอรีจำลอง';

    public function handle(MongoBlobStore $documents, LawIndexer $indexer): int
    {
        $permissions = app('mongo.blob.permissions');
        $groups = $permissions->read('data', 'groups') ?? [];
        $directory = $permissions->read('data', 'directory');
        $ids = array_column($groups, 'id');
        $rows = [];
        foreach ($documents->allStatuses() as $status) {
            $id = $status['document_id'];
            $review = $documents->read('review', $id);
            if (array_intersect($review['law_meta']['permission_group_ids'] ?? [], $ids) !== []) {
                $rows[$id] = $review;
            }
        }
        $this->info('กลุ่มสิทธิ์: '.count($groups).' | เอกสารที่อ้างอิง: '.count($rows).' | ไดเรกทอรีจำลอง: '.($directory === null ? 0 : 1));
        if ($this->option('dry-run') || ($groups === [] && $rows === [] && $directory === null)) {
            $this->info('ยังไม่เขียนข้อมูล');
            return self::SUCCESS;
        }
        $path = storage_path('app/backups');
        File::ensureDirectoryExists($path, 0700);
        $path .= '/permissions-reset-'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        $backup = json_encode(['at' => now()->toIso8601String(), 'groups' => $groups, 'directory' => $directory, 'reviews' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (File::put($path, $backup) !== strlen($backup)) {
            $this->error('ไม่สามารถสำรองข้อมูลได้ ยกเลิกการล้างกลุ่มสิทธิ์');
            return self::FAILURE;
        }
        $this->info('สำรองข้อมูลแล้ว: '.$path);
        foreach ($rows as $id => $review) {
            $documents->withLock('review', $id, function (array &$current) use ($ids): void {
                $current['law_meta']['permission_group_ids'] = array_values(array_diff($current['law_meta']['permission_group_ids'] ?? [], $ids));
            });
        }
        $permissions->delete('groups');
        $permissions->delete('directory');
        foreach (array_keys($rows) as $id) $indexer->index($id);
        $this->info('ล้างกลุ่มสิทธิ์และรายการอ้างอิงเรียบร้อยแล้ว');
        return self::SUCCESS;
    }
}
