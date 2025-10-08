<?php

namespace App\BusinessModules\Features\AdvancedDashboard;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;

class AdvancedDashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем основной модуль как singleton
        $this->app->singleton(AdvancedDashboardModule::class);
        
        // Регистрируем сервисы модуля
        $this->registerServices();
    }

    public function boot(): void
    {
        // Загружаем миграции
        $this->loadMigrations();
        
        // Загружаем маршруты
        $this->loadRoutes();
        
        // Регистрируем middleware
        $this->registerMiddleware();
        
        // Регистрируем события и слушателей
        $this->registerEvents();
    }

    protected function registerServices(): void
    {
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\WidgetRegistry::class,
            function ($app) {
                return \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\WidgetRegistry::getInstance();
            }
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\WidgetService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\DashboardLayoutService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\AlertsService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\DashboardExportService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\DashboardCacheService::class
        );

        $this->registerWidgetProviders();
    }

    protected function registerWidgetProviders(): void
    {
        $registry = \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\WidgetRegistry::getInstance();

        $providers = [
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\CashFlowWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\ProfitLossWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\ROIWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\RevenueForecastWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\ReceivablesPayablesWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\ExpenseBreakdownWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\FinancialHealthWidgetProvider(),

            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsOverviewWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsStatusWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsTimelineWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsBudgetWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsCompletionWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsRisksWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsMapWidgetProvider(),

            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsOverviewWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsStatusWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsPaymentsWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsPerformanceWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsUpcomingWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsCompletionForecastWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsByContractorWidgetProvider(),

            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsInventoryWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsConsumptionWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsForecastWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsLowStockWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsTopUsedWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsByProjectWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsSuppliersWidgetProvider(),

            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\EmployeeKPIWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\TopPerformersWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\ResourceUtilizationWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\EmployeeWorkloadWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\EmployeeAttendanceWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\EmployeeEfficiencyWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\TeamPerformanceWidgetProvider(),

            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\BudgetRiskWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\DeadlineRiskWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\ResourceDemandWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\CashFlowForecastWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\ProjectCompletionWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\CostOverrunWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\TrendAnalysisWidgetProvider(),

            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\RecentActivityWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\SystemEventsWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\UserActionsWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\NotificationsWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\AuditLogWidgetProvider(),

            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\SystemMetricsWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\ApiPerformanceWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\DatabaseStatsWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\CacheStatsWidgetProvider(),
            new \App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\ResponseTimesWidgetProvider(),
        ];

        foreach ($providers as $provider) {
            $registry->register($provider);
        }
    }

    protected function loadRoutes(): void
    {
        $routesPath = __DIR__ . '/routes.php';
        
        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }

    protected function loadMigrations(): void
    {
        $migrationsPath = __DIR__ . '/migrations';
        
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    protected function registerMiddleware(): void
    {
        // Регистрируем middleware для проверки активации модуля
        $router = $this->app['router'];
        
        $router->aliasMiddleware(
            'advanced_dashboard.active',
            \App\BusinessModules\Features\AdvancedDashboard\Http\Middleware\EnsureAdvancedDashboardActive::class
        );
    }

    protected function registerEvents(): void
    {
        // Инвалидация кеша при обновлении дашборда
        Event::listen(
            \App\BusinessModules\Features\AdvancedDashboard\Events\DashboardUpdated::class,
            \App\BusinessModules\Features\AdvancedDashboard\Listeners\InvalidateDashboardCache::class
        );
        
        // Инвалидация финансового кеша при изменении контрактов
        Event::listen(
            \App\BusinessModules\Features\AdvancedDashboard\Events\ContractDataChanged::class,
            \App\BusinessModules\Features\AdvancedDashboard\Listeners\InvalidateFinancialCache::class
        );
        
        // Инвалидация KPI кеша при изменении выполненных работ
        Event::listen(
            \App\BusinessModules\Features\AdvancedDashboard\Events\CompletedWorkDataChanged::class,
            \App\BusinessModules\Features\AdvancedDashboard\Listeners\InvalidateKPICache::class
        );
    }
}

