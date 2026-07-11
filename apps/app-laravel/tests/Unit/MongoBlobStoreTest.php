<?php

namespace Tests\Unit;

use App\Services\Storage\MongoBlobStore;
use Tests\TestCase;

class MongoBlobStoreTest extends TestCase
{
    private MongoBlobStore $blob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blob = app(MongoBlobStore::class);
    }

    public function test_read_returns_null_for_missing_document(): void
    {
        $this->assertNull($this->blob->read('status', 'nonexistent_id_'.uniqid()));
    }

    public function test_write_then_read_round_trips_data(): void
    {
        $id = 'test_'.uniqid();
        $data = ['document_id' => $id, 'status' => 'queued', 'nested' => ['key' => 'value']];

        $this->blob->write('status', $id, $data);
        $result = $this->blob->read('status', $id);

        $this->assertSame($data['document_id'], $result['document_id']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame(['key' => 'value'], $result['nested']);
    }

    public function test_exists_returns_false_when_kind_not_set(): void
    {
        $id = 'test_'.uniqid();
        $this->blob->write('status', $id, ['document_id' => $id]);

        $this->assertFalse($this->blob->exists('review', $id));
        $this->assertTrue($this->blob->exists('status', $id));
    }

    public function test_write_two_kinds_on_same_document(): void
    {
        $id = 'test_'.uniqid();
        $this->blob->write('status', $id, ['status' => 'done']);
        $this->blob->write('review', $id, ['pages' => []]);

        $this->assertSame('done', $this->blob->read('status', $id)['status']);
        $this->assertSame([], $this->blob->read('review', $id)['pages']);
    }

    public function test_with_lock_mutates_and_persists(): void
    {
        $id = 'test_'.uniqid();
        $this->blob->write('status', $id, ['count' => 0]);

        $this->blob->withLock('status', $id, function (array &$data): void {
            $data['count'] = ($data['count'] ?? 0) + 1;
            $data['flag'] = true;
        });

        $result = $this->blob->read('status', $id);
        $this->assertSame(1, $result['count']);
        $this->assertTrue($result['flag']);
    }

    public function test_with_lock_creates_document_when_missing(): void
    {
        $id = 'test_'.uniqid();

        $this->blob->withLock('status', $id, function (array &$data) use ($id): void {
            $data = ['document_id' => $id, 'status' => 'new'];
        });

        $result = $this->blob->read('status', $id);
        $this->assertSame($id, $result['document_id']);
        $this->assertSame('new', $result['status']);
    }

    public function test_all_statuses_returns_only_status_subfields(): void
    {
        $id1 = 'test_'.uniqid();
        $id2 = 'test_'.uniqid();
        $this->blob->write('status', $id1, ['document_id' => $id1, 'status' => 'done']);
        $this->blob->write('status', $id2, ['document_id' => $id2, 'status' => 'queued']);

        $all = $this->blob->allStatuses();
        $ids = array_column($all, 'document_id');

        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
        foreach ($all as $row) {
            $this->assertArrayHasKey('status', $row);
            $this->assertArrayNotHasKey('_id', $row);
        }
    }

    public function test_write_overwrites_existing_kind(): void
    {
        $id = 'test_'.uniqid();
        $this->blob->write('status', $id, ['status' => 'queued']);
        $this->blob->write('status', $id, ['status' => 'done', 'extra' => 'value']);

        $result = $this->blob->read('status', $id);
        $this->assertSame('done', $result['status']);
        $this->assertSame('value', $result['extra']);
    }
}
