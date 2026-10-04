<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MigrateLawTypesCommand extends Command
{
    protected $signature = 'laws:migrate-types';

    protected $description = 'Deprecated. Use master-data:migrate law-type.';

    public function handle(): int
    {
        $this->error('Deprecated: use php artisan master-data:migrate law-type.');

        return self::FAILURE;
    }
}
