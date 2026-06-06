<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\BusinessModules\Features\DesignManagement\Services\DesignNormativeCatalogService;
use Illuminate\Database\Seeder;

final class DesignManagementNormativeCatalogSeeder extends Seeder
{
    public function run(): void
    {
        app(DesignNormativeCatalogService::class)->ensureCatalog();
    }
}
