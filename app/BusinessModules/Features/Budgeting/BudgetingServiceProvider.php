<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting;

use App\BusinessModules\Features\Budgeting\Services\BudgetCatalogService;
use App\BusinessModules\Features\Budgeting\Services\BudgetImportFileReader;
use App\BusinessModules\Features\Budgeting\Services\BudgetImportService;
use App\BusinessModules\Features\Budgeting\Services\BudgetImportValidator;
use App\BusinessModules\Features\Budgeting\Services\BudgetLineService;
use App\BusinessModules\Features\Budgeting\Services\BudgetPeriodClosureService;
use App\BusinessModules\Features\Budgeting\Services\BudgetPeriodReopenService;
use App\BusinessModules\Features\Budgeting\Services\BudgetVersionService;
use App\BusinessModules\Features\Budgeting\Services\BudgetWorkflowService;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastReadService;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastService;
use App\BusinessModules\Features\Budgeting\Services\CashGapOpeningBalanceService;
use App\BusinessModules\Features\Budgeting\Services\CfoCommandCenterPayloadBuilder;
use App\BusinessModules\Features\Budgeting\Services\CfoCommandCenterService;
use Illuminate\Support\ServiceProvider;

final class BudgetingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BudgetCatalogService::class);
        $this->app->singleton(BudgetVersionService::class);
        $this->app->singleton(BudgetLineService::class);
        $this->app->singleton(BudgetPeriodClosureService::class);
        $this->app->singleton(BudgetPeriodReopenService::class);
        $this->app->singleton(BudgetWorkflowService::class);
        $this->app->singleton(BudgetImportFileReader::class);
        $this->app->singleton(BudgetImportValidator::class);
        $this->app->singleton(BudgetImportService::class);
        $this->app->singleton(CashGapForecastService::class);
        $this->app->singleton(CashGapOpeningBalanceService::class);
        $this->app->singleton(CashGapForecastReadService::class);
        $this->app->singleton(CfoCommandCenterPayloadBuilder::class);
        $this->app->singleton(CfoCommandCenterService::class);
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
