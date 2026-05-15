<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance;

use Illuminate\Support\ServiceProvider;

final class HandoverAcceptanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HandoverAcceptanceModule::class);
        $this->app->singleton(Services\HandoverAcceptanceService::class);
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
            'handover-acceptance.active',
            Http\Middleware\EnsureHandoverAcceptanceActive::class
        );
    }
}
