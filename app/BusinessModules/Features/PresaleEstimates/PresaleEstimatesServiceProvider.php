<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates;

use App\BusinessModules\Features\PresaleEstimates\Services\PresaleEstimateBudgetConversionService;
use Illuminate\Support\ServiceProvider;

final class PresaleEstimatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PresaleEstimateBudgetConversionService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        $routesPath = __DIR__.'/routes.php';
        if (is_file($routesPath)) {
            require $routesPath;
        }
    }
}
