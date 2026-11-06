<?php

namespace App\Services\MasterData;

class CompositeUsageCounter implements UsageCounter
{
    /** @param list<UsageCounter> $counters */
    public function __construct(private readonly array $counters) {}

    public function count(MasterDataKind $kind, string $code): int
    {
        foreach ($this->counters as $counter) {
            $count = $counter->count($kind, $code);
            if ($count > 0) {
                return $count;
            }
        }

        return 0;
    }
}
