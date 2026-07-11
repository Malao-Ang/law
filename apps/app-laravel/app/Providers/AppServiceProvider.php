<?php

namespace App\Providers;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Foundation\Vite;
use Illuminate\Support\ServiceProvider;
use MongoDB\Client;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MongoBlobStore::class, function (): MongoBlobStore {
            $config = config('database.connections.mongodb');
            $host = (string) ($config['host'] ?? 'mongo');
            $port = (int) ($config['port'] ?? 27017);
            $database = (string) ($config['database'] ?? 'poc');
            $username = (string) ($config['username'] ?? '');
            $password = (string) ($config['password'] ?? '');

            $uri = ($username !== '' && $password !== '')
                ? sprintf('mongodb://%s:%s@%s:%d', $username, $password, $host, $port)
                : sprintf('mongodb://%s:%d', $host, $port);

            $client = new Client($uri, [], [
                'typeMap' => [
                    'root' => 'array',
                    'document' => 'array',
                    'array' => 'array',
                ],
            ]);

            return new MongoBlobStore($client->$database->selectCollection('documents'));
        });
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
