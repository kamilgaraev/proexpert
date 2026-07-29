<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance;

use Illuminate\Support\ServiceProvider;

final class HandoverAcceptanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HandoverAcceptanceModule::class);
        $this->app->singleton(Services\HandoverAcceptanceService::class);
        $this->app->singleton(Reporting\Readiness\Services\HandoverEvidenceEventRecorder::class);
        $this->app->singleton(Reporting\Readiness\Services\HandoverReadinessFormula::class);
        $this->app->singleton(Reporting\Readiness\Services\HandoverReadinessSnapshotMaterializer::class);
        $this->app->singleton(Reporting\Readiness\Providers\HandoverReadinessReportProvider::class);
        $this->app->singleton(Reporting\Readiness\Queries\HandoverReadinessRowQuery::class);
        $this->app->singleton(Reporting\Readiness\DrillDown\HandoverReadinessDrillDownProvider::class);
        $this->app->singleton(Reporting\Readiness\Readiness\HandoverReadinessProbe::class);
    }

    public function boot(): void
    {
        $migrationsPath = __DIR__ . '/migrations';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        $routesPath = __DIR__ . '/routes.php';
        if (is_file($routesPath)) {
            require $routesPath;
        }

        $this->app['router']->aliasMiddleware(
            'handover-acceptance.active',
            Http\Middleware\EnsureHandoverAcceptanceActive::class
        );
    }
}
