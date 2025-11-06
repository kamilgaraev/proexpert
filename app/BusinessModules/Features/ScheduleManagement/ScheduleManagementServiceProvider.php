<?php

namespace App\BusinessModules\Features\ScheduleManagement;

use Illuminate\Support\ServiceProvider;

class ScheduleManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем singleton основного модуля
        $this->app->singleton(ScheduleManagementModule::class, function ($app) {
            return new ScheduleManagementModule();
        });
        
        // Регистрируем сервисы
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService::class);
    }

    public function boot(): void
    {
        // Загружаем миграции
        $this->loadMigrations();
        
        // ❗ Маршруты НЕ загружаем здесь - они централизованы в routes/api/v1/admin/project-based.php
        // $this->loadRoutes();
    }
    
    /**
     * Загрузка миграций модуля
     */
    protected function loadMigrations(): void
    {
        $migrationsPath = __DIR__ . '/migrations';
        
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }
    
    /**
     * Загрузка маршрутов модуля
     * 
     * ❗ НЕ ИСПОЛЬЗУЕТСЯ - маршруты интегрированы в routes/api/v1/admin/project-based.php
     */
    // protected function loadRoutes(): void
    // {
    //     $routesPath = __DIR__ . '/routes.php';
    //     
    //     if (file_exists($routesPath)) {
    //         $this->loadRoutesFrom($routesPath);
    //     }
    // }
}
