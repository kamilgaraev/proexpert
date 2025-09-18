<?php

namespace App\BusinessModules\Features\ScheduleManagement;

use Illuminate\Support\ServiceProvider;

class ScheduleManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScheduleManagementModule::class, function ($app) {
            return new ScheduleManagementModule();
        });
    }

    public function boot(): void
    {
        // Регистрация маршрутов модуля, если необходимо
        // $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        // Регистрация миграций модуля, если необходимо
        // $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Регистрация представлений модуля, если необходимо
        // $this->loadViewsFrom(__DIR__ . '/views', 'schedule-management');
    }
}
