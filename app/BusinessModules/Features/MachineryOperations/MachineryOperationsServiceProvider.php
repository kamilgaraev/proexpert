<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations;

use Illuminate\Support\ServiceProvider;

final class MachineryOperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MachineryOperationsModule::class);
        $this->app->singleton(Services\MachineryOperationsService::class);
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
            'machinery-operations.active',
            Http\Middleware\EnsureMachineryOperationsActive::class
        );
    }
}
