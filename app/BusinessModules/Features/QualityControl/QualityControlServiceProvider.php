<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl;

use Illuminate\Support\ServiceProvider;

final class QualityControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QualityControlModule::class);
        $this->app->singleton(
            Reporting\DefectFlow\Contracts\QualityDefectFlowStore::class,
            Reporting\DefectFlow\Services\EloquentQualityDefectFlowStore::class,
        );
        $this->app->singleton(
            Reporting\DefectFlow\Contracts\QualityDefectFlowTransactionBoundary::class,
            Reporting\DefectFlow\Services\LaravelQualityDefectFlowTransactionBoundary::class,
        );
        $this->app->singleton(
            Reporting\DefectFlow\Contracts\QualityDefectFlowOwnerEventSink::class,
            Reporting\DefectFlow\Services\QualityDefectFlowOwnerRecorder::class,
        );
        $this->app->singleton(Services\QualityDefectNumberGenerator::class);
        $this->app->singleton(Services\QualityDefectWorkflowService::class);
        $this->app->singleton(Services\QualityDefectService::class);
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
