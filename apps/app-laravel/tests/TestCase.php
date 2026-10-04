<?php

namespace Tests;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) config('database.connections.mongodb.database');
        if (! str_ends_with($database, '_testing')) {
            $this->fail("Refusing to run tests against MongoDB database [{$database}]; expected a *_testing database.");
        }

        try {
            app(MongoBlobStore::class)->truncate();
            app('mongo.blob.permissions')->truncate();
            app('mongo.blob.master')->truncate();
        } catch (\Exception) {
            // MongoDB not reachable — tests that need it will fail naturally
        }
    }
}
