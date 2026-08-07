<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Access\ReportingPermissionMatrix;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\AcceptedProductionReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\BudgetPlanFactReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ContractSettlementExposureReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\HoldingPerformanceReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\IntercompanyContractFlowReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\PayrollReadinessReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\PortfolioLiquidityReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ProjectPortfolioHealthReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ProjectEvmControlReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ProjectLaborCostReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ProjectMarginReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\WipCompletionForecastReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportCatalogController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportDrillDownController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportExportController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportRowsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportRunController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportSavedViewController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportSubscriptionController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportWorkspacePreferencesController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\WorkforceCapacityReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\RenderReportErrors;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/reports')
    ->name('admin.reports.')
    ->middleware(AdminRouteStack::middleware([RenderReportErrors::class]))
    ->group(function (): void {
        $view = ReportingPermissionMatrix::middleware(ReportOperation::VIEW);
        $manage = ReportingPermissionMatrix::middleware(ReportOperation::MANAGE);

        $resourceAccess = AuthorizeReportDefinitionAccess::class;
        $workspaceModule = 'module.access:reports';

        Route::get('/catalog', ReportCatalogController::class)
            ->middleware($resourceAccess)
            ->name('catalog');
        Route::get('/workspace', [ReportWorkspacePreferencesController::class, 'show'])
            ->middleware([$workspaceModule, ...$view])
            ->name('workspace.show');
        Route::post('/workspace/recent/{reportCode}', [ReportWorkspacePreferencesController::class, 'recordRecent'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('workspace.recent.store');
        Route::put('/workspace/favourites', [ReportWorkspacePreferencesController::class, 'setFavourites'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('workspace.favourites.update');
        Route::patch('/workspace/preferences', [ReportWorkspacePreferencesController::class, 'updatePreferences'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('workspace.preferences.update');
        Route::get('/saved-views', [ReportSavedViewController::class, 'index'])
            ->middleware([$workspaceModule, ...$view])->name('saved-views.index');
        Route::post('/saved-views', [ReportSavedViewController::class, 'store'])
            ->middleware([$workspaceModule, ...$manage])->name('saved-views.store');
        Route::get('/saved-views/{savedViewId}', [ReportSavedViewController::class, 'show'])
            ->middleware([$workspaceModule, ...$view])->name('saved-views.show');
        Route::patch('/saved-views/{savedViewId}', [ReportSavedViewController::class, 'update'])
            ->middleware([$workspaceModule, ...$manage])->name('saved-views.update');
        Route::delete('/saved-views/{savedViewId}', [ReportSavedViewController::class, 'destroy'])
            ->middleware([$workspaceModule, ...$manage])->name('saved-views.destroy');
        Route::post('/saved-views/{savedViewId}/set-default', [ReportSavedViewController::class, 'setDefault'])
            ->middleware([$workspaceModule, ...$manage])->name('saved-views.set-default');
        Route::get('/subscriptions', [ReportSubscriptionController::class, 'index'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.index');
        Route::post('/subscriptions', [ReportSubscriptionController::class, 'store'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.store');
        Route::patch('/subscriptions/{subscriptionId}', [ReportSubscriptionController::class, 'update'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.update');
        Route::delete('/subscriptions/{subscriptionId}', [ReportSubscriptionController::class, 'destroy'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.destroy');
        Route::post('/subscriptions/{subscriptionId}/pause', [ReportSubscriptionController::class, 'pause'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.pause');
        Route::post('/subscriptions/{subscriptionId}/resume', [ReportSubscriptionController::class, 'resume'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.resume');
        Route::post('/subscriptions/{subscriptionId}/run-now', [ReportSubscriptionController::class, 'runNow'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.run-now');
        Route::post('/workforce-capacity/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'workforce_capacity')
            ->middleware(['report.organization-scope', $resourceAccess])
            ->name('workforce-capacity.runs.store');
        Route::get('/workforce-capacity/options', WorkforceCapacityReportOptionsController::class)
            ->defaults('reportCode', 'workforce_capacity')
            ->middleware(['report.organization-scope', $resourceAccess])
            ->name('workforce-capacity.options');
        Route::get('/portfolio-liquidity/options', PortfolioLiquidityReportOptionsController::class)
            ->defaults('reportCode', 'portfolio_liquidity')
            ->middleware(['report.organization-scope', $resourceAccess])
            ->name('portfolio-liquidity.options');
        Route::get('/project-portfolio-health/options', ProjectPortfolioHealthReportOptionsController::class)
            ->defaults('reportCode', 'project_portfolio_health')
            ->middleware(['report.organization-scope', $resourceAccess])
            ->name('project-portfolio-health.options');
        Route::post('/intercompany-contract-flows/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'intercompany_contract_flows')
            ->middleware($resourceAccess)
            ->name('intercompany-contract-flows.runs.store');
        Route::get('/intercompany-contract-flows/options', IntercompanyContractFlowReportOptionsController::class)
            ->defaults('reportCode', 'intercompany_contract_flows')
            ->middleware(['report.organization-scope', $resourceAccess])
            ->name('intercompany-contract-flows.options');
        Route::post('/holding-performance/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'holding_performance')
            ->middleware($resourceAccess)
            ->name('holding-performance.runs.store');
        Route::get('/holding-performance/options', HoldingPerformanceReportOptionsController::class)
            ->defaults('reportCode', 'holding_performance')
            ->middleware(['report.organization-scope', $resourceAccess])
            ->name('holding-performance.options');
        Route::post('/contract-settlement-exposure/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'contract_settlement_exposure')
            ->middleware(['report.organization-scope', $resourceAccess])
            ->name('contract-settlement-exposure.runs.store');
        Route::get('/contract-settlement-exposure/options', ContractSettlementExposureReportOptionsController::class)
            ->defaults('reportCode', 'contract_settlement_exposure')
            ->middleware(['report.organization-scope', $resourceAccess])
            ->name('contract-settlement-exposure.options');
        Route::post('/{reportCode}/runs', [ReportRunController::class, 'store'])
            ->middleware($resourceAccess)
            ->name('runs.store');
        Route::post('/projects/{project}/budget-plan-fact/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'budget_plan_fact')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-budget-plan-fact.runs.store');
        Route::get('/projects/{project}/budget-plan-fact/options', BudgetPlanFactReportOptionsController::class)
            ->defaults('reportCode', 'budget_plan_fact')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-budget-plan-fact.options');
        Route::post('/projects/{project}/project-margin/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'project_margin')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-margin.runs.store');
        Route::get('/projects/{project}/project-margin/options', ProjectMarginReportOptionsController::class)
            ->defaults('reportCode', 'project_margin')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-margin.options');
        Route::post('/projects/{project}/project-evm-control/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'project_evm_control')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-evm-control.runs.store');
        Route::get('/projects/{project}/project-evm-control/options', ProjectEvmControlReportOptionsController::class)
            ->defaults('reportCode', 'project_evm_control')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-evm-control.options');
        Route::post('/projects/{project}/accepted-production-progress/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'accepted_production_progress')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('accepted-production-progress.runs.store');
        Route::get('/projects/{project}/accepted-production-progress/options', AcceptedProductionReportOptionsController::class)
            ->defaults('reportCode', 'accepted_production_progress')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('accepted-production-progress.options');
        Route::post('/projects/{project}/wip-completion-forecast/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'wip_completion_forecast')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('wip-completion-forecast.runs.store');
        Route::get('/projects/{project}/wip-completion-forecast/options', WipCompletionForecastReportOptionsController::class)
            ->defaults('reportCode', 'wip_completion_forecast')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('wip-completion-forecast.options');
        Route::post('/projects/{project}/project-labor-cost/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'project_labor_cost')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-labor-cost.runs.store');
        Route::get('/projects/{project}/project-labor-cost/options', ProjectLaborCostReportOptionsController::class)
            ->defaults('reportCode', 'project_labor_cost')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-labor-cost.options');
        Route::post('/projects/{project}/payroll-readiness/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'payroll_readiness')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('payroll-readiness.runs.store');
        Route::get('/projects/{project}/payroll-readiness/options', PayrollReadinessReportOptionsController::class)
            ->defaults('reportCode', 'payroll_readiness')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('payroll-readiness.options');
        Route::get('/runs/{runId}', [ReportRunController::class, 'show'])
            ->middleware($resourceAccess)
            ->name('runs.show');
        Route::get('/runs/{runId}/rows', ReportRowsController::class)
            ->middleware($resourceAccess)
            ->name('runs.rows');
        Route::post('/runs/{runId}/drill-down', ReportDrillDownController::class)
            ->middleware($resourceAccess)
            ->name('runs.drill-down');
        Route::post('/runs/{runId}/retry', [ReportRunController::class, 'retry'])
            ->middleware($resourceAccess)
            ->name('runs.retry');
        Route::post('/runs/{runId}/cancel', [ReportRunController::class, 'cancel'])
            ->middleware($resourceAccess)
            ->name('runs.cancel');
        Route::post('/runs/{runId}/exports', [ReportExportController::class, 'store'])
            ->middleware($resourceAccess)
            ->name('exports.store');
        Route::get('/exports/{exportId}', [ReportExportController::class, 'show'])
            ->middleware($resourceAccess)
            ->name('exports.show');
        Route::post('/exports/{exportId}/retry', [ReportExportController::class, 'retry'])
            ->middleware($resourceAccess)
            ->name('exports.retry');
        Route::post('/exports/{exportId}/cancel', [ReportExportController::class, 'cancel'])
            ->middleware($resourceAccess)
            ->name('exports.cancel');
        Route::post('/exports/{exportId}/download-link', [ReportExportController::class, 'downloadLink'])
            ->middleware($resourceAccess)
            ->name('exports.download-link');
    });
