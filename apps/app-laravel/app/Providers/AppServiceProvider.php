<?php

namespace App\Providers;

use App\Services\Auth\MongoUserStore;
use App\Services\Auth\MembershipAuditLog;
use App\Services\DocumentExportService;
use App\Services\DocumentHtmlService;
use App\Services\Fast\LibreOfficeConverter;
use App\Services\MasterData\ChangeStatusUsageCounter;
use App\Services\MasterData\CompositeUsageCounter;
use App\Services\MasterData\EnforcementStatusUsageCounter;
use App\Services\MasterData\LawCategoryUsageCounter;
use App\Services\MasterData\LawFamilyUsageCounter;
use App\Services\MasterData\LegalStructureUsageCounter;
use App\Services\MasterData\LawTypeUsageCounter;
use App\Services\MasterData\MasterDataStore;
use App\Services\MasterData\UnitStore;
use App\Services\MasterData\UsageCounter;
use App\Services\Permissions\PermissionStore;
use App\Services\ReviewStore;
use App\Services\Storage\MongoBlobStore;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\URL;
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

        $this->app->singleton('mongo.blob.master', function (): MongoBlobStore {
            $client = $this->app->make(Client::class);
            $database = (string) config('database.connections.mongodb.database', 'poc');

            return new MongoBlobStore($client->$database->selectCollection('master_data'));
        });

        $this->app->singleton('mongo.blob.users', function (): MongoBlobStore {
            $client = $this->app->make(Client::class);
            $database = (string) config('database.connections.mongodb.database', 'poc');

            return new MongoBlobStore($client->$database->selectCollection('users'));
        });

        $this->app->when(MongoUserStore::class)
            ->needs(MongoBlobStore::class)
            ->give(fn (): MongoBlobStore => $this->app->make('mongo.blob.users'));

        $this->app->singleton('mongo.blob.membership-audit', function (): MongoBlobStore {
            $client = $this->app->make(Client::class);
            $database = (string) config('database.connections.mongodb.database', 'poc');

            return new MongoBlobStore($client->$database->selectCollection('membership_audit_logs'));
        });

        $this->app->when(MembershipAuditLog::class)
            ->needs(MongoBlobStore::class)
            ->give(fn (): MongoBlobStore => $this->app->make('mongo.blob.membership-audit'));

        $this->app->when(PermissionStore::class)
            ->needs(MongoBlobStore::class)
            ->give(fn (): MongoBlobStore => $this->app->make('mongo.blob.permissions'));

        $this->app->when(MasterDataStore::class)
            ->needs(MongoBlobStore::class)
            ->give(fn (): MongoBlobStore => $this->app->make('mongo.blob.master'));

        $this->app->when(UnitStore::class)
            ->needs(MongoBlobStore::class)
            ->give(fn (): MongoBlobStore => $this->app->make('mongo.blob.master'));

        $this->app->bind(UsageCounter::class, fn (): UsageCounter => new CompositeUsageCounter([
            $this->app->make(EnforcementStatusUsageCounter::class),
            $this->app->make(LawTypeUsageCounter::class),
            $this->app->make(LawCategoryUsageCounter::class),
            $this->app->make(LawFamilyUsageCounter::class),
            $this->app->make(LegalStructureUsageCounter::class),
            $this->app->make(ChangeStatusUsageCounter::class),
        ]));

        $this->app->bind(DocumentExportService::class, fn (): DocumentExportService => new DocumentExportService(
            $this->app->make(DocumentHtmlService::class),
            $this->app->make(LibreOfficeConverter::class),
            $this->app->make(ReviewStore::class),
        ));
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

        // Behind a TLS-terminating reverse proxy the request arrives as plain HTTP
        // with an internal Host, so build asset URLs from APP_URL instead.
        $appUrl = (string) config('app.url');
        if ($this->app->isProduction() && str_starts_with($appUrl, 'https://')) {
            URL::forceRootUrl($appUrl);
            URL::forceScheme('https');
        }
    }
}
