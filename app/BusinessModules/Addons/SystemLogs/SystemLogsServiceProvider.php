<?php

namespace App\BusinessModules\Addons\SystemLogs;

use Illuminate\Support\ServiceProvider;

class SystemLogsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SystemLogsModule::class, function ($app) {
            return new SystemLogsModule();
        });
    }

    public function boot(): void
    {
        // Регистрация маршрутов модуля, если необходимо
        // $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        // Регистрация миграций модуля, если необходимо
        // $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Регистрация представлений модуля, если необходимо
        // $this->loadViewsFrom(__DIR__ . '/views', 'system-logs');
    }
}
