<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation;

use Illuminate\Support\ServiceProvider;

final class ExecutiveDocumentationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExecutiveDocumentationModule::class);
        $this->app->singleton(Services\ExecutiveDocumentNumberGenerator::class);
        $this->app->singleton(Services\ExecutiveDocumentationWorkflowService::class);
        $this->app->singleton(Services\ExecutiveDocumentationService::class);
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
            'executive-documentation.active',
            Http\Middleware\EnsureExecutiveDocumentationActive::class
        );
    }
}
