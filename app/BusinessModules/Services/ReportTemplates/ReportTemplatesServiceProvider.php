<?php

namespace App\BusinessModules\Services\ReportTemplates;

use Illuminate\Support\ServiceProvider;

class ReportTemplatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportTemplatesModule::class, function ($app) {
            return new ReportTemplatesModule();
        });
    }

    public function boot(): void
    {
        // Регистрация маршрутов модуля, если необходимо
        // $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        // Регистрация миграций модуля, если необходимо
        // $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Регистрация представлений модуля, если необходимо
        // $this->loadViewsFrom(__DIR__ . '/views', 'report-templates');
    }
}
