<?php

namespace App\BusinessModules\Features\ScheduleManagement;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;

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
        
        // Регистрируем сервисы интеграции со сметой
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\DurationCalculationService::class);
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\EstimateScheduleImportService::class);
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\EstimateSyncService::class);
    }

    public function boot(): void
    {
        // Загружаем миграции
        $this->loadMigrations();
        
        // Регистрируем события
        $this->registerEvents();
        
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
    
    /**
     * Регистрация событий и слушателей
     */
    protected function registerEvents(): void
    {
        // Слушаем события из модуля смет (если модуль активен)
        if (class_exists(\App\BusinessModules\Features\BudgetEstimates\Events\EstimateUpdated::class)) {
            Event::listen(
                \App\BusinessModules\Features\BudgetEstimates\Events\EstimateUpdated::class,
                \App\BusinessModules\Features\ScheduleManagement\Listeners\SyncScheduleOnEstimateUpdate::class
            );
        }
        
        // Слушаем события обновления прогресса графика
        Event::listen(
            \App\BusinessModules\Features\ScheduleManagement\Events\ScheduleProgressUpdated::class,
            \App\BusinessModules\Features\ScheduleManagement\Listeners\UpdateEstimateProgressOnScheduleChange::class
        );
    }
}
