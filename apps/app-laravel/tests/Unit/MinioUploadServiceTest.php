<?php

namespace Tests\Unit;

use App\Services\Buu\BuuApiException;
use App\Services\Buu\BuuMinioService;
use App\Services\Buu\MinioUploadService;
use Mockery;
use Tests\TestCase;

class MinioUploadServiceTest extends TestCase
{
    public function test_returns_null_when_disabled(): void
    {
        config(['buu.minio_enabled' => false]);
        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldNotReceive('putFile');
        $svc = new MinioUploadService($mock);

        $this->assertNull($svc->uploadIfEnabled('/tmp/f.pdf', 'pdf', 'doc_1'));
    }

    public function test_uploads_when_enabled(): void
    {
        config(['buu.minio_enabled' => true]);
        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('putFile')->once()->andReturn('stored.pdf');
        $svc = new MinioUploadService($mock);

        $this->assertSame('stored.pdf', $svc->uploadIfEnabled('/tmp/f.pdf', 'pdf', 'doc_1'));
    }

    public function test_returns_null_on_error(): void
    {
        config(['buu.minio_enabled' => true]);
        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('putFile')->once()->andThrow(new BuuApiException('down'));
        $svc = new MinioUploadService($mock);

        $this->assertNull($svc->uploadIfEnabled('/tmp/f.pdf', 'pdf', 'doc_1'));
    }

    public function test_upload_and_cleanup_deletes_local_file_after_success(): void
    {
        config(['buu.minio_enabled' => true]);
        $path = tempnam(sys_get_temp_dir(), 'minio-upload-');
        file_put_contents($path, 'content');

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('putFile')->once()->andReturn('stored.pdf');
        $svc = new MinioUploadService($mock);

        $this->assertSame('stored.pdf', $svc->uploadAndCleanup($path, 'pdf', 'doc_1'));
        $this->assertFileDoesNotExist($path);
    }

    public function test_upload_and_cleanup_keeps_local_file_when_upload_fails(): void
    {
        config(['buu.minio_enabled' => true]);
        $path = tempnam(sys_get_temp_dir(), 'minio-upload-');
        file_put_contents($path, 'content');

        $mock = Mockery::mock(BuuMinioService::class);
        $mock->shouldReceive('putFile')->once()->andThrow(new BuuApiException('down'));
        $svc = new MinioUploadService($mock);

        $this->assertNull($svc->uploadAndCleanup($path, 'pdf', 'doc_1'));
        $this->assertFileExists($path);

        unlink($path);
    }
}
