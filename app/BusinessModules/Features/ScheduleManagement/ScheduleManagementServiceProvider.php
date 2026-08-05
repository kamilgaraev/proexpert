<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleSnapshotService;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceCandidateContract;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVariancePublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceQueryService;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceReportBindingFactory;
use App\BusinessModules\Features\ScheduleManagement\Reporting\HistoricalScheduleTaskStateQuery;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Readiness\BaselineScheduleVarianceReadinessProbe;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessAuthorizer;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessSourceStore;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\EloquentLookaheadReadinessSourceStore;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LaravelLookaheadReadinessAuthorizer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ScheduleManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем singleton основного модуля
        $this->app->singleton(ScheduleManagementModule::class, function ($app) {
            return new ScheduleManagementModule;
        });

        // Регистрируем сервисы
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService::class);

        // Регистрируем сервисы интеграции со сметой
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\DurationCalculationService::class);
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\EstimateScheduleImportService::class);
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\EstimateSyncService::class);
        $this->app->singleton(\App\BusinessModules\Features\ScheduleManagement\Services\LookaheadPlanningService::class);
        $this->app->bind(LookaheadReadinessSourceStore::class, EloquentLookaheadReadinessSourceStore::class);
        $this->app->bind(LookaheadReadinessAuthorizer::class, LaravelLookaheadReadinessAuthorizer::class);
        $this->app->singleton(HistoricalScheduleTaskStateQuery::class);
        $this->app->scoped(BaselineScheduleSnapshotService::class);
        $this->app->scoped(BaselineScheduleVarianceProvider::class);
        $this->app->scoped(BaselineScheduleVarianceQueryService::class);
        $this->app->scoped(BaselineScheduleVarianceReadinessProbe::class);
        $this->app->singleton(BaselineScheduleVarianceCandidateContract::class);
        $this->app->scoped(BaselineScheduleVarianceReportBindingFactory::class);
        $this->app->scoped(BaselineScheduleVariancePublishedRuntimeBindingRegistrar::class);
    }

    public function boot(): void
    {
        $this->app->afterResolving(
            ReportDefinitionBindingAssembler::class,
            function (ReportDefinitionBindingAssembler $assembler): void {
                $this->app->make(BaselineScheduleVariancePublishedRuntimeBindingRegistrar::class)->register($assembler);
            },
        );

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
        $migrationsPath = __DIR__.'/migrations';

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
