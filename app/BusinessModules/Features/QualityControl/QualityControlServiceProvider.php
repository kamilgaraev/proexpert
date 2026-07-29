<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl;

use Illuminate\Support\ServiceProvider;

final class QualityControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QualityControlModule::class);
        $this->app->singleton(Services\QualityDefectNumberGenerator::class);
        $this->app->singleton(Services\QualityDefectWorkflowService::class);
        $this->app->singleton(Services\QualityDefectService::class);
        $this->app->singleton(Reporting\DefectFlow\Services\QualityDefectTransitionRecorder::class);
        $this->app->singleton(Reporting\DefectFlow\Services\QualityDefectFlowFormula::class);
        $this->app->singleton(Reporting\DefectFlow\Services\QualityDefectFlowSnapshotMaterializer::class);
        $this->app->singleton(Reporting\DefectFlow\Providers\QualityDefectFlowReportProvider::class);
        $this->app->singleton(Reporting\DefectFlow\Queries\QualityDefectFlowRowQuery::class);
        $this->app->singleton(Reporting\DefectFlow\DrillDown\QualityDefectFlowDrillDownProvider::class);
        $this->app->singleton(Reporting\DefectFlow\Backfill\QualityDefectFlowBackfill::class);
        $this->app->singleton(Reporting\DefectFlow\Readiness\QualityDefectFlowReadinessProbe::class);
    }

    public function boot(): void
    {
        $migrationsPath = __DIR__.'/migrations';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        $routesPath = __DIR__.'/routes.php';
        if (is_file($routesPath)) {
            require $routesPath;
        }

        $this->app['router']->aliasMiddleware(
            'quality-control.active',
            Http\Middleware\EnsureQualityControlActive::class
        );
    }
}
