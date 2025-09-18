<?php

namespace App\BusinessModules\Addons\FileManagement;

use Illuminate\Support\ServiceProvider;

class FileManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FileManagementModule::class, function ($app) {
            return new FileManagementModule();
        });
    }

    public function boot(): void
    {
        // Регистрация маршрутов модуля, если необходимо
        // $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        // Регистрация миграций модуля, если необходимо
        // $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Регистрация представлений модуля, если необходимо
        // $this->loadViewsFrom(__DIR__ . '/views', 'file-management');
    }
}
