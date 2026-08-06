<?php

declare(strict_types=1);

namespace App\Providers;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionPublishedRuntimeBindingRegistrar;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionReportBindingFactory;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown\AcceptedProductionDrillDownProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Options\AcceptedProductionOptionsService;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Options\AcceptedProductionOptionsSource;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Options\CanonicalAcceptedProductionOptionsSource;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Providers\AcceptedProductionReportProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Queries\AcceptedProductionRowQuery;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Readiness\AcceptedProductionReadinessProbe;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionEventUniverse;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFormula;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionHistoryBoundaryResolver;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionSnapshotMaterializer;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceRecognitionGrain;
use Illuminate\Support\ServiceProvider;

final class CompletedWorkReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AcceptedProductionFormula::class);
        $this->app->singleton(ProductionAcceptanceRecognitionGrain::class);
        $this->app->scoped(AcceptedProductionHistoryBoundaryResolver::class);
        $this->app->scoped(AcceptedProductionEventUniverse::class);
        $this->app->scoped(AcceptedProductionSnapshotMaterializer::class);
        $this->app->scoped(AcceptedProductionReportProvider::class);
        $this->app->scoped(AcceptedProductionRowQuery::class);
        $this->app->scoped(AcceptedProductionDrillDownProvider::class);
        $this->app->scoped(AcceptedProductionOptionsSource::class, CanonicalAcceptedProductionOptionsSource::class);
        $this->app->scoped(AcceptedProductionOptionsService::class);
        $this->app->scoped(AcceptedProductionReadinessProbe::class);
        $this->app->singleton(AcceptedProductionCandidateContract::class);
        $this->app->scoped(AcceptedProductionReportBindingFactory::class);
        $this->app->scoped(AcceptedProductionPublishedRuntimeBindingRegistrar::class);
    }

    public function boot(): void
    {
        $this->app->resolving(
            ReportDefinitionBindingAssembler::class,
            function (ReportDefinitionBindingAssembler $assembler): void {
                $this->app
                    ->make(AcceptedProductionPublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
            },
        );
    }
}
