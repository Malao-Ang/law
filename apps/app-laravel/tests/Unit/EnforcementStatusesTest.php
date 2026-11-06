<?php

namespace Tests\Unit;

use App\Services\MasterData\EnforcementStatuses;
use Tests\TestCase;

class EnforcementStatusesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_code_role_resolve_and_labels_use_master_data(): void
    {
        /** @var EnforcementStatuses $statuses */
        $statuses = app(EnforcementStatuses::class);

        $this->assertSame('STA01', $statuses->codeForRole('in_force'));
        $this->assertSame('STA02', $statuses->codeForRole('repealed'));
        $this->assertSame('STA03', $statuses->codeForRole('draft'));

        $this->assertSame('in_force', $statuses->roleOf('STA01'));
        $this->assertSame('repealed', $statuses->roleOf('ยกเลิกการใช้งาน'));
        $this->assertSame('draft', $statuses->roleOf(''));

        $this->assertTrue($statuses->isInForce('มีผลบังคับใช้'));
        $this->assertTrue($statuses->isRepealed('STA02'));
        $this->assertTrue($statuses->isDraft('ร่าง'));
        $this->assertSame('มีผลบังคับใช้', $statuses->labelOf('STA01'));
        $this->assertSame('custom', $statuses->labelOf('custom'));
    }
}
