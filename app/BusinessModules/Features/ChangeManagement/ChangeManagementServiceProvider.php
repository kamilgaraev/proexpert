<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement;

use App\BusinessModules\Features\ChangeManagement\Http\Middleware\EnsureChangeManagementActive;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class ChangeManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChangeManagementModule::class);
        $this->app->scoped(ChangeManagementService::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('change-management.active', EnsureChangeManagementActive::class);

        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
