<?php

namespace App\BusinessModules\Features\ScheduleManagement;

use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleSnapshotService;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceQueryService;
use App\BusinessModules\Features\ScheduleManagement\Reporting\HistoricalScheduleTaskStateQuery;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Readiness\BaselineScheduleVarianceReadinessProbe;
use App\BusinessModules\Features\ScheduleManagement\Reporting\ScheduleBaselineVersionBackfill;
use App\BusinessModules\Features\ScheduleManagement\Reporting\ScheduleTaskStateRecorder;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Backfill\LookaheadReadinessBackfill;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Readiness\LookaheadReadinessProbe;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessSnapshotMaterializer;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\WorkConstraintEventRecorder;
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
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\LookaheadPlanningService::class);
        $this->app->singleton(BaselineScheduleSnapshotService::class);
        $this->app->singleton(BaselineScheduleVarianceQueryService::class);
        $this->app->singleton(HistoricalScheduleTaskStateQuery::class);
        $this->app->singleton(ScheduleTaskStateRecorder::class);
        $this->app->singleton(ScheduleBaselineVersionBackfill::class);
        $this->app->singleton(BaselineScheduleVarianceReadinessProbe::class);
        $this->app->singleton(WorkConstraintEventRecorder::class);
        $this->app->singleton(LookaheadReadinessSnapshotMaterializer::class);
        $this->app->singleton(LookaheadReadinessBackfill::class);
        $this->app->singleton(LookaheadReadinessProbe::class);
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
