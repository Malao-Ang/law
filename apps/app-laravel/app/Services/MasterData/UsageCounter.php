<?php

namespace App\Services\MasterData;

interface UsageCounter
{
    public function count(MasterDataKind $kind, string $code): int;
}
