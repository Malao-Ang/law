<?php

namespace App\Providers;

use Illuminate\Foundation\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Hot file lives under public/ (bind-mounted), so a host-run Vite and the
        // containerized Laravel app share it. (The Docker volume path only worked
        // when Vite ran inside a container.)
        $this->app->make(Vite::class)->useHotFile(public_path('hot'));
    }
}
