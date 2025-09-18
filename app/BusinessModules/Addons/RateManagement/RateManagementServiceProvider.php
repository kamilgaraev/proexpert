<?php

namespace App\BusinessModules\Addons\RateManagement;

use Illuminate\Support\ServiceProvider;

class RateManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RateManagementModule::class, function ($app) {
            return new RateManagementModule();
        });
    }

    public function boot(): void
    {
        // Регистрация маршрутов модуля, если необходимо
        // $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        // Регистрация миграций модуля, если необходимо
        // $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Регистрация представлений модуля, если необходимо
        // $this->loadViewsFrom(__DIR__ . '/views', 'rate-management');
    }
}
