<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm;

use App\BusinessModules\Features\Crm\Services\CrmDuplicateService;
use App\BusinessModules\Features\Crm\Services\CrmImportService;
use App\BusinessModules\Features\Crm\Services\CrmRegistryService;
use App\BusinessModules\Features\Crm\Services\CrmTimelineService;
use App\BusinessModules\Features\Crm\Services\CrmWorkflowService;
use Illuminate\Support\ServiceProvider;

final class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CrmTimelineService::class);
        $this->app->singleton(CrmDuplicateService::class);
        $this->app->singleton(CrmRegistryService::class);
        $this->app->singleton(CrmWorkflowService::class);
        $this->app->singleton(CrmImportService::class);
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
