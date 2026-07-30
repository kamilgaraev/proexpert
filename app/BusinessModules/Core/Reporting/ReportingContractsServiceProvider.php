<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorResponseFactory;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Infrastructure\Bindings\LaravelReportDefinitionBindingAssembler;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DrillDown\ProjectEvmControlDrillDownProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Providers\ProjectEvmControlReportProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Queries\ProjectEvmControlRowQuery;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Readiness\ProjectControlReadinessProbe;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceQueryService;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown\LookaheadReadinessDrillDownProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Providers\LookaheadReadinessReportProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Queries\LookaheadReadinessRowQuery;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Readiness\LookaheadReadinessProbe;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Readiness\BaselineScheduleVarianceReadinessProbe;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown\AcceptedProductionDrillDownProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Providers\AcceptedProductionReportProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Queries\AcceptedProductionRowQuery;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Readiness\AcceptedProductionReadinessProbe;
use Illuminate\Support\ServiceProvider;

final class ReportingContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportErrorCatalog::class);
        $this->app->singleton(ReportErrorResponseFactory::class);
        $this->app->singleton(ReportAccessService::class);
        $this->app->singleton(ReportExecutionContextFactory::class);
        $this->app->singleton(
            ReportDefinitionBindingAssembler::class,
            LaravelReportDefinitionBindingAssembler::class,
        );
    }

    public function boot(): void
    {
        $this->app->afterResolving(
            ReportDefinitionRegistry::class,
            function (ReportDefinitionRegistry $registry): void {
                $assembler = $this->app->make(ReportDefinitionBindingAssembler::class);
                foreach ($this->productionBindings($registry) as $binding) {
                    $assembler->register($binding);
                }
            },
        );
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }

    private function productionBindings(ReportDefinitionRegistry $registry): array
    {
        $contracts = [
            'project_evm_control' => [
                ProjectEvmControlReportProvider::class,
                ProjectEvmControlRowQuery::class,
                ProjectEvmControlDrillDownProvider::class,
                ProjectControlReadinessProbe::class,
            ],
            'baseline_schedule_variance' => [
                BaselineScheduleVarianceProvider::class,
                BaselineScheduleVarianceQueryService::class,
                BaselineScheduleVarianceQueryService::class,
                BaselineScheduleVarianceReadinessProbe::class,
            ],
            'lookahead_readiness' => [
                LookaheadReadinessReportProvider::class,
                LookaheadReadinessRowQuery::class,
                LookaheadReadinessDrillDownProvider::class,
                LookaheadReadinessProbe::class,
            ],
            'accepted_production_progress' => [
                AcceptedProductionReportProvider::class,
                AcceptedProductionRowQuery::class,
                AcceptedProductionDrillDownProvider::class,
                AcceptedProductionReadinessProbe::class,
            ],
        ];
        $bindings = [];
        foreach ($contracts as $code => [$provider, $rows, $drillDown, $readiness]) {
            $definition = $registry->published($code)->payload();
            $bindings[] = new ReportDefinitionBinding(
                $code,
                $definition->definitionHash,
                $definition->contractVersion,
                $this->app->make($provider),
                $this->app->make($rows),
                $this->app->make($drillDown),
                $this->app->make($readiness),
            );
        }

        return $bindings;
    }
}
