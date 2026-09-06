<?php

namespace Tests\Feature;

use App\Services\Buu\BuuApiException;
use App\Services\Buu\BuuMinioService;
use Mockery;
use Tests\TestCase;

class MinioTestControllerTest extends TestCase
{
    public function test_bucket_check_uses_configured_bucket_when_query_is_empty(): void
    {
        config([
            'buu.minio_enabled' => true,
            'buu.default_bucket' => 'configured-bucket',
        ]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('listFiles')
            ->once()
            ->with('/', null)
            ->andReturn(['a.pdf', 'b.pdf']);
        $this->app->instance(BuuMinioService::class, $mock);

        $this->getJson('/api/test/minio/bucket')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('exists', true)
            ->assertJsonPath('access_ok', true)
            ->assertJsonPath('bucket', 'configured-bucket')
            ->assertJsonPath('file_count', 2);
    }

    public function test_bucket_check_accepts_bucket_override(): void
    {
        config(['buu.minio_enabled' => true]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('listFiles')
            ->once()
            ->with('/', 'other-bucket')
            ->andReturn([]);
        $this->app->instance(BuuMinioService::class, $mock);

        $this->getJson('/api/test/minio/bucket?bucket=other-bucket')
            ->assertOk()
            ->assertJsonPath('bucket', 'other-bucket')
            ->assertJsonPath('exists', true)
            ->assertJsonPath('file_count', 0);
    }

    public function test_bucket_check_returns_error_payload_when_minio_fails(): void
    {
        config(['buu.minio_enabled' => true]);

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('listFiles')
            ->once()
            ->with('/', 'missing-bucket')
            ->andThrow(new BuuApiException('Bucket not found', 404, ['message' => 'not found']));
        $this->app->instance(BuuMinioService::class, $mock);

        $this->getJson('/api/test/minio/bucket?bucket=missing-bucket')
            ->assertStatus(502)
            ->assertJsonPath('error', 'Bucket not found')
            ->assertJsonPath('exists', false)
            ->assertJsonPath('access_ok', false)
            ->assertJsonPath('bucket', 'missing-bucket')
            ->assertJsonPath('body.message', 'not found');
    }
}
