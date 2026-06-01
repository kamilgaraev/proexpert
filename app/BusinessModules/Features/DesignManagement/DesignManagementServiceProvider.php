<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement;

use Illuminate\Support\ServiceProvider;

final class DesignManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DesignManagementModule::class);
        $this->app->singleton(Services\DesignStoragePathService::class);
        $this->app->singleton(Services\DesignManagementService::class);
        $this->app->singleton(Services\DesignPulseFactSource::class);
    }

    public function boot(): void
    {
        $migrationsPath = __DIR__ . '/migrations';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        $routesPath = __DIR__ . '/routes.php';
        if (is_file($routesPath)) {
            require $routesPath;
        }

        $this->app['router']->aliasMiddleware(
            'design-management.active',
            Http\Middleware\EnsureDesignManagementActive::class
        );
    }
}
