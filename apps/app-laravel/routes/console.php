<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Artisan::command('audit:indexes', function (): void {
    app(\App\Services\Audit\AuditLogStore::class)->indexes();
    $this->info('สร้างดัชนีประวัติการใช้งานแล้ว');
});
