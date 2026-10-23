<?php

namespace App\Console\Commands;

use App\Services\Auth\MongoUserStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MigrateMembershipsCommand extends Command
{
    protected $signature = 'users:migrate-memberships {--dry-run : แสดงรายการโดยไม่เขียนข้อมูล}';

    protected $description = 'ย้ายบทบาทเดิมเป็นสมาชิกหน่วยงาน พร้อมสำรองข้อมูลก่อนเขียน';

    public function handle(MongoUserStore $users): int
    {
        $rows = array_values(array_filter($users->all(), fn (array $user): bool => array_key_exists('role', $user)));
        $this->table(['ชื่อผู้ใช้', 'บทบาทเดิม', 'คณะ UMS', 'หน่วยงาน UMS'], array_map(fn (array $user): array => [
            $user['username'], $user['role'], $user['ums_fac_id'] ?? $user['fac_id'] ?? '', $user['ums_dep_id'] ?? $user['dep_id'] ?? '',
        ], $rows));
        if ($this->option('dry-run') || $rows === []) {
            $this->info('จำนวนผู้ใช้ที่ต้องย้าย: '.count($rows).' (ยังไม่เขียนข้อมูล)');

            return self::SUCCESS;
        }
        $missing = array_filter($rows, fn (array $user): bool => $user['role'] !== MongoUserStore::ROLE_SUPER_ADMIN
            && trim((string) ($user['ums_fac_id'] ?? $user['fac_id'] ?? '')) === '');
        if ($missing !== []) {
            $this->error('ยังไม่ย้ายข้อมูล เนื่องจากผู้ใช้ต่อไปนี้ไม่มีสังกัด UMS: '.implode(', ', array_column($missing, 'username')));

            return self::FAILURE;
        }
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0700);
        $path = $directory.'/users-memberships-'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        $backup = json_encode(['at' => now()->toIso8601String(), 'users' => $users->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (File::put($path, $backup) !== strlen($backup)) {
            $this->error('ไม่สามารถสำรองข้อมูลผู้ใช้ได้ ยกเลิกการย้ายข้อมูล');

            return self::FAILURE;
        }
        $this->info('สำรองข้อมูลแล้ว: '.$path);
        foreach ($rows as $user) {
            $users->migrateMemberships($user['username']);
        }
        $this->info('ย้ายสมาชิกหน่วยงานเรียบร้อยแล้ว: '.count($rows).' คน');

        return self::SUCCESS;
    }
}
