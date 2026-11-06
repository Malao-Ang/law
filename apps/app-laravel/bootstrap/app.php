<?php

use App\Http\Middleware\EnsureAuthSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('oversight:send-reminders')
            ->dailyAt('08:00')
            ->timezone('Asia/Bangkok')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(append: [\App\Http\Middleware\RecordAuditMutation::class, \App\Http\Middleware\ValidateActiveUnit::class, \App\Http\Middleware\EnforceDocumentAccess::class]);
        $middleware->alias([
            'auth.session' => EnsureAuthSession::class,
            'doc.read' => \App\Http\Middleware\EnforceDocumentRead::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
