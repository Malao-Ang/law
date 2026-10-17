<?php

namespace Tests\Feature;

use Tests\TestCase;

class MinioTestRoutesProductionTest extends TestCase
{
    public function test_minio_test_routes_are_not_found_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->getJson('/api/test/minio/bucket')->assertNotFound();
        $this->getJson('/api/test/minio')->assertNotFound();
        $this->postJson('/api/test/minio/presign', [])->assertNotFound();
        $this->postJson('/api/test/minio/upload', [])->assertNotFound();
    }

    public function test_minio_test_routes_are_reachable_outside_production(): void
    {
        $this->getJson('/api/test/minio/bucket')->assertStatus(200);
    }
}
