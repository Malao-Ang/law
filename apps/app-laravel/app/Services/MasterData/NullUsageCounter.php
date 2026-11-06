<?php

namespace App\Services\MasterData;

class NullUsageCounter implements UsageCounter
{
    public function count(MasterDataKind $kind, string $code): int
    {
        return 0;
    }
}
