<?php

namespace App\BusinessModules\Addons\ContractorPortal;

use Illuminate\Support\ServiceProvider;

class ContractorPortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContractorPortalModule::class, function ($app) {
            return new ContractorPortalModule();
        });
    }

    public function boot(): void
    {
        // Регистрация маршрутов модуля, если необходимо
        // $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        // Регистрация миграций модуля, если необходимо
        // $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Регистрация представлений модуля, если необходимо
        // $this->loadViewsFrom(__DIR__ . '/views', 'contractor-portal');
    }
}
