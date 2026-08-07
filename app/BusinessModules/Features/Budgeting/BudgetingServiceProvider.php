<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Features\Budgeting\Console\Commands\RecalculateEpmDataMartSnapshotsCommand;
use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\Contracts\PlanFactSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\Contracts\ProjectMarginSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\Infrastructure\Persistence\EloquentBudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\CashGapOpeningBalance;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlComponentSet;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlProjectionService;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Readiness\ManagementPnlReadinessProbe;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Services\ManagementPnlOptionsService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\EloquentProjectPortfolioHealthSourceReader;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityBudgetVersionObserver;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityProvider;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquiditySourceVersionObserver;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthOwnerSourcePolicy;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthProvider;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthReadinessProbe;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceReader;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DrillDown\ProjectEvmControlDrillDownProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Providers\ProjectEvmControlReportProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Queries\ProjectEvmControlRowQuery;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Readiness\ProjectControlReadinessProbe;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectEvmControlOptionsService;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\BudgetPlanFactManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectFinanceQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectMarginManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastOptionsService;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\BudgetCatalogService;
use App\BusinessModules\Features\Budgeting\Services\BudgetImportFileReader;
use App\BusinessModules\Features\Budgeting\Services\BudgetImportService;
use App\BusinessModules\Features\Budgeting\Services\BudgetImportValidator;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportSourceCloseService;
use App\BusinessModules\Features\Budgeting\Services\BudgetLineService;
use App\BusinessModules\Features\Budgeting\Services\BudgetPeriodClosureService;
use App\BusinessModules\Features\Budgeting\Services\BudgetPeriodReopenService;
use App\BusinessModules\Features\Budgeting\Services\BudgetPlanFactPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Services\BudgetPlanFactReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Services\BudgetVersionService;
use App\BusinessModules\Features\Budgeting\Services\BudgetWorkflowService;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastReadService;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastService;
use App\BusinessModules\Features\Budgeting\Services\CashGapOpeningBalanceService;
use App\BusinessModules\Features\Budgeting\Services\CfoCommandCenterPayloadBuilder;
use App\BusinessModules\Features\Budgeting\Services\CfoCommandCenterService;
use App\BusinessModules\Features\Budgeting\Services\CfoProjectPortfolioAggregator;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartFreshnessService;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartHealthService;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartPayloadProjector;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartRecalculationCoordinator;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartRecalculationService;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportService;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotWriter;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportService;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotMaterializer;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotWriter;
use App\BusinessModules\Features\Budgeting\Services\ProjectPortfolioDashboardPayloadBuilder;
use App\BusinessModules\Features\Budgeting\Services\ProjectPortfolioDashboardService;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostManagementPnlComponentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessManagementPnlComponentSource;
use Illuminate\Support\ServiceProvider;

final class BudgetingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BudgetingReportSourceCloseStore::class, EloquentBudgetingReportSourceCloseStore::class);
        $this->app->singleton(BudgetingReportSourceCloseService::class);
        $this->app->bind(PlanFactSourceSnapshotReport::class, PlanFactReportService::class);
        $this->app->bind(ProjectMarginSourceSnapshotReport::class, ProjectMarginReportService::class);
        $this->app->singleton(PlanFactSourceSnapshotMaterializer::class);
        $this->app->singleton(PlanFactSourceSnapshotWriter::class);
        $this->app->singleton(PlanFactReportSourceSnapshotAdapter::class);
        $this->app->singleton(BudgetPlanFactCandidateContract::class);
        $this->app->singleton(ManagementPnlCandidateContract::class);
        $this->app->singleton(ManagementPnlBuiltinPublishedReport::class);
        $this->app->scoped(ManagementPnlComponentSet::class);
        $this->app->scoped(ManagementPnlProjectionService::class, static fn ($app): ManagementPnlProjectionService => new ManagementPnlProjectionService(
            [
                $app->make(ProjectMarginManagementPnlComponentSource::class),
                $app->make(BudgetPlanFactManagementPnlComponentSource::class),
                $app->make(ProjectLaborCostManagementPnlComponentSource::class),
                $app->make(PayrollReadinessManagementPnlComponentSource::class),
            ],
            $app->make(ManagementPnlComponentSet::class),
        ));
        $this->app->scoped(ManagementPnlProvider::class);
        $this->app->scoped(ManagementPnlQueryService::class);
        $this->app->scoped(ManagementPnlReadinessProbe::class);
        $this->app->scoped(ManagementPnlOptionsService::class);
        $this->app->scoped(ManagementPnlReportBindingFactory::class);
        $this->app->scoped(ManagementPnlPublishedRuntimeBindingRegistrar::class);
        $this->app->singleton(BudgetPlanFactReportBindingFactory::class);
        $this->app->singleton(BudgetPlanFactPublishedRuntimeBindingRegistrar::class);
        $this->app->singleton(ProjectMarginSourceSnapshotMaterializer::class);
        $this->app->singleton(ProjectMarginSourceSnapshotWriter::class);
        $this->app->singleton(ProjectMarginReportSourceSnapshotAdapter::class);
        $this->app->singleton(ProjectMarginCandidateContract::class);
        $this->app->singleton(ProjectMarginReportBindingFactory::class);
        $this->app->singleton(ProjectMarginPublishedRuntimeBindingRegistrar::class);
        $this->app->singleton(PortfolioLiquidityCandidateContract::class);
        $this->app->scoped(PortfolioLiquidityProvider::class);
        $this->app->scoped(BudgetingPortfolioQueryService::class);
        $this->app->singleton(ProjectPortfolioHealthOwnerSourcePolicy::class);
        $this->app->bind(ProjectPortfolioHealthSourceReader::class, EloquentProjectPortfolioHealthSourceReader::class);
        $this->app->scoped(EloquentProjectPortfolioHealthSourceReader::class);
        $this->app->scoped(ProjectPortfolioHealthReadinessProbe::class);
        $this->app->scoped(ProjectPortfolioHealthProvider::class);
        $this->app->scoped(ProjectPortfolioHealthReportBindingFactory::class);
        $this->app->scoped(ProjectPortfolioHealthPublishedRuntimeBindingRegistrar::class);
        $this->app->scoped(PortfolioLiquidityReportBindingFactory::class);
        $this->app->scoped(PortfolioLiquidityPublishedRuntimeBindingRegistrar::class);
        $this->app->singleton(ProjectEvmControlCandidateContract::class);
        $this->app->scoped(ProjectEvmControlReportProvider::class);
        $this->app->scoped(ProjectEvmControlRowQuery::class);
        $this->app->scoped(ProjectEvmControlDrillDownProvider::class);
        $this->app->scoped(ProjectControlReadinessProbe::class);
        $this->app->scoped(ProjectEvmControlOptionsService::class);
        $this->app->scoped(ProjectEvmControlReportBindingFactory::class);
        $this->app->scoped(ProjectEvmControlPublishedRuntimeBindingRegistrar::class);
        $this->app->singleton(WipCompletionForecastCandidateContract::class);
        $this->app->scoped(WipCompletionForecastProvider::class);
        $this->app->scoped(WipCompletionForecastOptionsService::class);
        $this->app->scoped(ProjectFinanceQueryService::class);
        $this->app->scoped(WipCompletionForecastReportBindingFactory::class);
        $this->app->scoped(WipCompletionForecastPublishedRuntimeBindingRegistrar::class);
        $this->app->singleton(BudgetCatalogService::class);
        $this->app->singleton(BudgetVersionService::class);
        $this->app->singleton(BudgetLineService::class);
        $this->app->singleton(BudgetPeriodClosureService::class);
        $this->app->singleton(BudgetPeriodReopenService::class);
        $this->app->singleton(BudgetWorkflowService::class);
        $this->app->singleton(BudgetImportFileReader::class);
        $this->app->singleton(BudgetImportValidator::class);
        $this->app->singleton(BudgetImportService::class);
        $this->app->singleton(CashGapForecastService::class);
        $this->app->singleton(CashGapOpeningBalanceService::class);
        $this->app->singleton(CashGapForecastReadService::class);
        $this->app->singleton(CfoCommandCenterPayloadBuilder::class);
        $this->app->singleton(CfoProjectPortfolioAggregator::class);
        $this->app->singleton(EpmDataMartPayloadProjector::class);
        $this->app->singleton(EpmDataMartFreshnessService::class);
        $this->app->singleton(EpmDataMartHealthService::class);
        $this->app->singleton(EpmDataMartRecalculationCoordinator::class);
        $this->app->singleton(EpmDataMartRecalculationService::class);
        $this->app->singleton(CfoCommandCenterService::class);
        $this->app->singleton(ProjectPortfolioDashboardPayloadBuilder::class);
        $this->app->singleton(ProjectPortfolioDashboardService::class);
    }

    public function boot(): void
    {
        BudgetAmount::observe(PortfolioLiquiditySourceVersionObserver::class);
        BudgetLimitReservation::observe(PortfolioLiquiditySourceVersionObserver::class);
        CashGapOpeningBalance::observe(PortfolioLiquiditySourceVersionObserver::class);
        BudgetVersion::observe(PortfolioLiquidityBudgetVersionObserver::class);

        $this->app->afterResolving(
            ReportDefinitionBindingAssembler::class,
            function (ReportDefinitionBindingAssembler $assembler): void {
                $this->app
                    ->make(BudgetPlanFactPublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
                $this->app
                    ->make(ProjectMarginPublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
                $this->app
                    ->make(ManagementPnlPublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
                $this->app
                    ->make(PortfolioLiquidityPublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
                $this->app
                    ->make(ProjectPortfolioHealthPublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
                $this->app
                    ->make(ProjectEvmControlPublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
                $this->app
                    ->make(WipCompletionForecastPublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
            },
        );
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RecalculateEpmDataMartSnapshotsCommand::class,
            ]);
        }

        $routesPath = __DIR__.'/routes.php';
        if (is_file($routesPath)) {
            require $routesPath;
        }
    }
}
