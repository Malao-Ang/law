<?php

namespace App\Providers;

use App\Services\Permissions\PermissionStore;
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
        $this->app->singleton(Client::class, function (): Client {
            $config = config('database.connections.mongodb');
            $host = (string) ($config['host'] ?? 'mongo');
            $port = (int) ($config['port'] ?? 27017);
            $username = (string) ($config['username'] ?? '');
            $password = (string) ($config['password'] ?? '');

            $uri = ($username !== '' && $password !== '')
                ? sprintf('mongodb://%s:%s@%s:%d', $username, $password, $host, $port)
                : sprintf('mongodb://%s:%d', $host, $port);

            return new Client($uri, [], [
                'typeMap' => [
                    'root' => 'array',
                    'document' => 'array',
                    'array' => 'array',
                ],
            ]);
        });

        $this->app->singleton(MongoBlobStore::class, function (): MongoBlobStore {
            $client = $this->app->make(Client::class);
            $database = (string) config('database.connections.mongodb.database', 'poc');

            return new MongoBlobStore($client->$database->selectCollection('documents'));
        });

        $this->app->singleton('mongo.blob.permissions', function (): MongoBlobStore {
            $client = $this->app->make(Client::class);
            $database = (string) config('database.connections.mongodb.database', 'poc');

            return new MongoBlobStore($client->$database->selectCollection('permissions'));
        });

        $this->app->when(PermissionStore::class)
            ->needs(MongoBlobStore::class)
            ->give(fn (): MongoBlobStore => $this->app->make('mongo.blob.permissions'));
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
