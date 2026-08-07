<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Features\ChangeManagement\Http\Middleware\EnsureChangeManagementActive;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimBuiltinPublishedReport;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimCandidateContract;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimReportBindingFactory;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DrillDown\ChangeClaimDrillDownProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Providers\ChangeClaimContingencyReportProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Queries\ChangeClaimRowQuery;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Readiness\ChangeClaimReadinessProbe;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class ChangeManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChangeManagementModule::class);
        $this->app->scoped(ChangeManagementService::class);
        $this->app->singleton(ChangeClaimCandidateContract::class);
        $this->app->singleton(ChangeClaimBuiltinPublishedReport::class);
        $this->app->scoped(ChangeClaimContingencyReportProvider::class);
        $this->app->scoped(ChangeClaimRowQuery::class);
        $this->app->scoped(ChangeClaimDrillDownProvider::class);
        $this->app->scoped(ChangeClaimReadinessProbe::class);
        $this->app->scoped(ChangeClaimReportBindingFactory::class);
        $this->app->scoped(ChangeClaimPublishedRuntimeBindingRegistrar::class);
    }

    public function boot(Router $router): void
    {
        $this->app->afterResolving(
            ReportDefinitionBindingAssembler::class,
            fn (ReportDefinitionBindingAssembler $assembler) => $this->app
                ->make(ChangeClaimPublishedRuntimeBindingRegistrar::class)
                ->register($assembler),
        );
        $router->aliasMiddleware('change-management.active', EnsureChangeManagementActive::class);

        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
