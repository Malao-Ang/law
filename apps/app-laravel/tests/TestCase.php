<?php

namespace Tests;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            app(MongoBlobStore::class)->truncate();
            app('mongo.blob.permissions')->truncate();
        } catch (\Exception) {
            // MongoDB not reachable — tests that need it will fail naturally
        }
    }
}
