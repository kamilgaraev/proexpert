<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor;

use Illuminate\Support\ServiceProvider;

final class ProductionLaborServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProductionLaborModule::class);
    }
}
