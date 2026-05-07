<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Modules\PackageCatalogValidator;
use Illuminate\Console\Command;

class AuditPackageCatalogCommand extends Command
{
    protected $signature = 'packages:audit';

    protected $description = 'Validate module package catalog configuration';

    public function handle(PackageCatalogValidator $validator): int
    {
        $result = $validator->validate();

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        if ($result['errors'] !== []) {
            $this->line('Package catalog audit failed.');

            return self::FAILURE;
        }

        $this->info('Package catalog audit passed.');

        return self::SUCCESS;
    }
}
