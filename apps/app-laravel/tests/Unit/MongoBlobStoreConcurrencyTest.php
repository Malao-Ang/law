<?php

namespace Tests\Unit;

use App\Services\Storage\ConcurrencyException;
use App\Services\Storage\MongoBlobStore;
use MongoDB\Collection;
use MongoDB\UpdateResult;
use Tests\TestCase;

class MongoBlobStoreConcurrencyTest extends TestCase
{
    public function test_withlock_throws_concurrency_exception_when_retries_exhausted(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('findOne')->willReturn(['_id' => 'doc_x', '_version' => 1, 'review' => []]);

        $missResult = $this->createMock(UpdateResult::class);
        $missResult->method('getMatchedCount')->willReturn(0);
        $collection->method('updateOne')->willReturn($missResult);

        $store = new MongoBlobStore($collection);

        $this->expectException(ConcurrencyException::class);
        $store->withLock('review', 'doc_x', function (array &$d): void { $d['x'] = 1; });
    }
}
