<?php

namespace App\Console\Commands;

use App\Services\Buu\UmsFacultyService;
use App\Services\MasterData\UnitStore;
use Illuminate\Console\Command;

class SyncFacultiesCommand extends Command
{
    protected $signature = 'units:sync-faculties';

    protected $description = 'ดึงข้อมูลคณะจาก UMS หรือใช้ข้อมูลสำรอง';

    public function handle(UmsFacultyService $ums, UnitStore $units): int
    {
        $result = $ums->sync($units);
        $result['fallback'] ? $this->warn($result['message']) : $this->info($result['message']);

        return self::SUCCESS;
    }
}
