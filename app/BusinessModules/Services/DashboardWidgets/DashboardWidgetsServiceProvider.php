<?php

namespace App\BusinessModules\Services\DashboardWidgets;

use Illuminate\Support\ServiceProvider;

class DashboardWidgetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DashboardWidgetsModule::class, function ($app) {
            return new DashboardWidgetsModule();
        });
    }

    public function boot(): void
    {
        // Регистрация маршрутов модуля, если необходимо
        // $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        // Регистрация миграций модуля, если необходимо
        // $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Регистрация представлений модуля, если необходимо
        // $this->loadViewsFrom(__DIR__ . '/views', 'dashboard-widgets');
    }
}
