<?php

namespace App\BusinessModules\Services\DataFilters;

use Illuminate\Support\ServiceProvider;

class DataFiltersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DataFiltersModule::class, function ($app) {
            return new DataFiltersModule();
        });
    }

    public function boot(): void
    {
        // Регистрация маршрутов модуля, если необходимо
        // $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        // Регистрация миграций модуля, если необходимо
        // $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Регистрация представлений модуля, если необходимо
        // $this->loadViewsFrom(__DIR__ . '/views', 'data-filters');
    }
}
