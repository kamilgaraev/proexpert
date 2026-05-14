<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement;

use Illuminate\Support\ServiceProvider;

final class SafetyManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SafetyManagementModule::class);
        $this->app->singleton(Services\SafetyManagementService::class);
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
            'safety-management.active',
            Http\Middleware\EnsureSafetyManagementActive::class
        );
    }
}
