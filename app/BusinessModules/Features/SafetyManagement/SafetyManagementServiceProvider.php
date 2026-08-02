<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement;

use Illuminate\Support\ServiceProvider;

final class SafetyManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SafetyManagementModule::class);
        $this->app->singleton(Services\SafetyManagementService::class);
        $this->app->singleton(Services\SafetyComplianceService::class);
        $this->app->singleton(Services\SafetyDocumentDraftService::class);
        $this->app->singleton(Reporting\IncidentActions\Services\SafetyTransitionRecorder::class);
        $this->app->singleton(Reporting\IncidentActions\Services\SafetyExposureProjector::class);
        $this->app->singleton(Reporting\IncidentActions\Services\SafetyIncidentFormula::class);
        $this->app->singleton(Reporting\IncidentActions\Services\SafetyIncidentSnapshotMaterializer::class);
        $this->app->singleton(Reporting\IncidentActions\Providers\SafetyIncidentActionsReportProvider::class);
        $this->app->singleton(Reporting\IncidentActions\Queries\SafetyIncidentRowQuery::class);
        $this->app->singleton(Reporting\IncidentActions\DrillDown\SafetyIncidentDrillDownProvider::class);
        $this->app->singleton(Reporting\IncidentActions\Backfill\SafetyIncidentBackfill::class);
        $this->app->singleton(Reporting\IncidentActions\Backfill\SafetyExposureBackfill::class);
        $this->app->singleton(Reporting\IncidentActions\Readiness\SafetyIncidentReadinessProbe::class);
        $this->app->singleton(Reporting\Admission\Services\SafetySiteAssignmentService::class);
        $this->app->singleton(Reporting\Admission\Services\WorkforceAdmissionFormula::class);
        $this->app->singleton(Reporting\Admission\Services\WorkforceAdmissionSnapshotMaterializer::class);
        $this->app->singleton(Reporting\Admission\Providers\WorkforceAdmissionReportProvider::class);
        $this->app->singleton(Reporting\Admission\Queries\WorkforceAdmissionRowQuery::class);
        $this->app->singleton(Reporting\Admission\DrillDown\WorkforceAdmissionDrillDownProvider::class);
        $this->app->singleton(Reporting\Admission\Backfill\WorkforceAdmissionBackfill::class);
        $this->app->singleton(Reporting\Admission\Readiness\WorkforceAdmissionReadinessProbe::class);
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
            'safety-management.active',
            Http\Middleware\EnsureSafetyManagementActive::class
        );
    }
}
