<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor;

use App\BusinessModules\Features\ProductionLabor\Http\Middleware\EnsureProductionLaborActive;
use App\BusinessModules\Features\ProductionLabor\Services\ProductionLaborService;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class ProductionLaborServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProductionLaborModule::class);
        $this->app->scoped(ProductionLaborService::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('production-labor.active', EnsureProductionLaborActive::class);

        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
