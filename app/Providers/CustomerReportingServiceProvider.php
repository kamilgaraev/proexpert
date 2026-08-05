<?php

declare(strict_types=1);

namespace App\Providers;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\Services\Customer\Reporting\Sla\CustomerSlaCandidateContract;
use App\Services\Customer\Reporting\Sla\CustomerSlaPublishedRuntimeBindingRegistrar;
use App\Services\Customer\Reporting\Sla\CustomerSlaReportBindingFactory;
use App\Services\Customer\Reporting\Sla\DrillDown\CustomerSlaDrillDownProvider;
use App\Services\Customer\Reporting\Sla\Providers\CustomerSlaReportProvider;
use App\Services\Customer\Reporting\Sla\Queries\CustomerSlaRowQuery;
use App\Services\Customer\Reporting\Sla\Readiness\CustomerSlaReadinessProbe;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaClock;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaFormula;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaSnapshotMaterializer;
use Illuminate\Support\ServiceProvider;

final class CustomerReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerSlaClock::class);
        $this->app->singleton(CustomerSlaFormula::class);
        $this->app->scoped(CustomerSlaSnapshotMaterializer::class);
        $this->app->scoped(CustomerSlaReportProvider::class);
        $this->app->scoped(CustomerSlaRowQuery::class);
        $this->app->scoped(CustomerSlaDrillDownProvider::class);
        $this->app->scoped(CustomerSlaReadinessProbe::class);
        $this->app->singleton(CustomerSlaCandidateContract::class);
        $this->app->scoped(CustomerSlaReportBindingFactory::class);
        $this->app->scoped(CustomerSlaPublishedRuntimeBindingRegistrar::class);
    }

    public function boot(): void
    {
        $this->app->resolving(
            ReportDefinitionBindingAssembler::class,
            function (ReportDefinitionBindingAssembler $assembler): void {
                $this->app->make(CustomerSlaPublishedRuntimeBindingRegistrar::class)->register($assembler);
            },
        );
    }
}
