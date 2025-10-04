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
        if ($this->app->runningInConsole()) {
            // В консоли загружаем только миграции
            $this->loadMigrations();
            return;
        }

        // Загружаем маршруты
        $this->loadRoutes();
        
        // Загружаем миграции
        $this->loadMigrations();
        
        // Регистрируем middleware
        $this->registerMiddleware();
        
        // Регистрируем события и слушателей
        $this->registerEvents();
    }

    protected function registerServices(): void
    {
        // Финансовая аналитика
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\FinancialAnalyticsService::class
        );
        
        // Предиктивная аналитика
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\PredictiveAnalyticsService::class
        );
        
        // KPI расчеты
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\KPICalculationService::class
        );
        
        // Управление layout дашборда
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\DashboardLayoutService::class
        );
        
        // Алерты
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\AlertsService::class
        );
        
        // Экспорт
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\DashboardExportService::class
        );
        
        // Кеширование
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\DashboardCacheService::class
        );
    }

    protected function loadRoutes(): void
    {
        $routesPath = __DIR__ . '/routes.php';
        
        if (file_exists($routesPath)) {
            Route::middleware(['web'])
                ->group($routesPath);
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

