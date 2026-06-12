<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders;

use App\BusinessModules\Features\Tenders\Services\TenderDeadlineService;
use App\BusinessModules\Features\Tenders\Services\TenderFileService;
use App\BusinessModules\Features\Tenders\Services\TenderRegistryService;
use App\BusinessModules\Features\Tenders\Services\TenderTimelineService;
use App\BusinessModules\Features\Tenders\Services\TenderWorkflowService;
use Illuminate\Support\ServiceProvider;

final class TendersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TendersModule::class);
        $this->app->singleton(TenderDeadlineService::class);
        $this->app->singleton(TenderTimelineService::class);
        $this->app->singleton(TenderRegistryService::class);
        $this->app->singleton(TenderWorkflowService::class);
        $this->app->singleton(TenderFileService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        $routesPath = __DIR__ . '/routes.php';
        if (is_file($routesPath)) {
            require $routesPath;
        }
    }
}
