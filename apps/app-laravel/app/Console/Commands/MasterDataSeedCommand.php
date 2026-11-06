<?php

namespace App\Console\Commands;

use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Illuminate\Console\Command;

class MasterDataSeedCommand extends Command
{
    protected $signature = 'master-data:seed {--kind=} {--force}';

    protected $description = 'Seed generic master data definitions.';

    public function handle(MasterDataStore $store): int
    {
        $kindOption = $this->option('kind');
        $force = (bool) $this->option('force');
        $kinds = [];

        if (is_string($kindOption) && $kindOption !== '') {
            $kind = MasterDataKind::tryFrom($kindOption);
            if ($kind === null) {
                $this->error("Unknown master data kind: {$kindOption}");

                return self::FAILURE;
            }

            $kinds = [$kind];
        } else {
            $kinds = MasterDataKind::cases();
        }

        foreach ($kinds as $kind) {
            $changed = $force ? $store->reseed($kind, true) : $store->reseed($kind);
            $this->info("{$kind->value}: {$changed} item(s) seeded");
        }

        return self::SUCCESS;
    }
}
