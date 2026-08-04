<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Features\TimeTracking\Http\Middleware\EnsureTimeTrackingActive;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\EffectiveLaborRateSource;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\ProjectLaborCostDatabasePort;
use App\BusinessModules\Features\TimeTracking\Reporting\Formulas\ProjectLaborCostFormula;
use App\BusinessModules\Features\TimeTracking\Reporting\Infrastructure\DatabaseProjectLaborCostAdapter;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostCandidateContract;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostOptionsService;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostProjectionService;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostProvider;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostQueryService;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostReportBindingFactory;
use App\BusinessModules\Features\TimeTracking\Services\MobileTimeTrackingService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class TimeTrackingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TimeTrackingModule::class);
        $this->app->scoped(MobileTimeTrackingService::class);
        $this->app->scoped(
            ProjectLaborCostDatabasePort::class,
            static fn ($app): DatabaseProjectLaborCostAdapter => new DatabaseProjectLaborCostAdapter(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(ProjectLaborCostFormula::class),
            ),
        );
        $this->app->singleton(ProjectLaborCostCandidateContract::class);
        $this->app->scoped(ProjectLaborCostProjectionService::class);
        $this->app->scoped(ProjectLaborCostProvider::class);
        $this->app->scoped(ProjectLaborCostQueryService::class);
        $this->app->scoped(
            ProjectLaborCostOptionsService::class,
            static fn ($app): ProjectLaborCostOptionsService => new ProjectLaborCostOptionsService(
                $app->make(DatabaseManager::class)->connection(),
            ),
        );
        $this->app->scoped(ProjectLaborCostReportBindingFactory::class);
        $this->app->scoped(ProjectLaborCostPublishedRuntimeBindingRegistrar::class);
        $this->app->scoped(
            EffectiveLaborRateSource::class,
            static fn ($app): ProjectLaborCostDatabasePort => $app->make(ProjectLaborCostDatabasePort::class),
        );
    }

    public function boot(Router $router): void
    {
        $this->app->afterResolving(
            ReportDefinitionBindingAssembler::class,
            function (ReportDefinitionBindingAssembler $assembler): void {
                $this->app->make(ProjectLaborCostPublishedRuntimeBindingRegistrar::class)->register($assembler);
            },
        );
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $router->aliasMiddleware('time-tracking.active', EnsureTimeTrackingActive::class);
        $routesPath = __DIR__.'/routes.php';
        if (is_file($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }
}
