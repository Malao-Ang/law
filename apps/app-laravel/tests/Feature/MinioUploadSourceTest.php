<?php

namespace Tests\Feature;

use App\Services\Buu\MinioUploadService;
use App\Services\ReviewStore;
use Tests\TestCase;

class MinioUploadSourceTest extends TestCase
{
    public function test_upload_source_noop_when_minio_disabled(): void
    {
        config(['buu.minio_enabled' => false]);
        $store = $this->createMock(ReviewStore::class);
        $store->expects($this->never())->method('getStatus');

        $result = app(MinioUploadService::class)->uploadSource('doc_x', $store);

        $this->assertNull($result);
    }
}
